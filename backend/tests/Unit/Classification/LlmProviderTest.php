<?php

declare(strict_types=1);

use DevRadar\Domain\Classification\LlmException;
use DevRadar\Domain\Classification\LlmRequest;
use DevRadar\Domain\Classification\LlmResponse;
use DevRadar\Infrastructure\Http\HttpResponse;
use DevRadar\Infrastructure\Http\HttpTransportException;
use DevRadar\Infrastructure\Llm\AnthropicProvider;
use DevRadar\Infrastructure\Llm\CachingLlmProvider;
use DevRadar\Infrastructure\Llm\OpenAiCompatibleProvider;
use DevRadar\Infrastructure\Llm\RetryingLlmProvider;
use Tests\Fake\FakeHttpClient;
use Tests\Fake\MockLlmProvider;
use Tests\Fake\RecordingLogger;

function llmRequest(): LlmRequest
{
    return new LlmRequest('system instructions', 'user content');
}

function anthropicBody(string $text): string
{
    return json_encode([
        'content' => [['type' => 'text', 'text' => $text]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 20],
    ]);
}

// ------------------------------------------------------------ two providers

it('maps an Anthropic response', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, anthropicBody('{"is_project": false}')));
    $provider = new AnthropicProvider($http, 'test-key', 'model-x');

    $response = $provider->complete(llmRequest());

    expect($response->text)->toBe('{"is_project": false}')
        ->and($response->inputTokens)->toBe(100)
        ->and($provider->identity()->provider)->toBe('anthropic');
});

it('keeps instructions out of the message on Anthropic', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, anthropicBody('{}')));
    (new AnthropicProvider($http, 'k', 'm'))->complete(llmRequest());

    $body = $http->lastBody();

    // The separation is the structural half of the injection defence.
    expect($body['system'])->toBe('system instructions')
        ->and($body['messages'][0]['content'])->toBe('user content');
});

it('maps an OpenAI-compatible response', function () {
    $body = json_encode([
        'choices' => [['message' => ['content' => '{"is_project": true}']]],
        'usage' => ['prompt_tokens' => 90, 'completion_tokens' => 12],
    ]);

    $http = (new FakeHttpClient())->queue(new HttpResponse(200, $body));
    $provider = new OpenAiCompatibleProvider($http, 'k', 'gpt-x', providerName: 'openrouter');

    $response = $provider->complete(llmRequest());

    // A second provider requiring no change above this layer is the proof
    // that the abstraction is real.
    expect($response->text)->toBe('{"is_project": true}')
        ->and($response->inputTokens)->toBe(90)
        ->and($provider->identity()->provider)->toBe('openrouter');
});

it('omits the Authorization header when a local server needs no key', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, '{"choices":[{"message":{"content":"{}"}}]}'));
    (new OpenAiCompatibleProvider($http, '', 'llama', baseUrl: 'http://localhost:11434/v1'))->complete(llmRequest());

    expect($http->requests[0]['headers'])->not->toHaveKey('Authorization');
});

it('sends the credential in a header, never in the body', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, anthropicBody('{}')));
    (new AnthropicProvider($http, 'sk-secret-value-123', 'm'))->complete(llmRequest());

    expect($http->requests[0]['headers']['x-api-key'])->toBe('sk-secret-value-123')
        ->and(json_encode($http->lastBody()))->not->toContain('sk-secret-value-123');
});

// ------------------------------------------------------- error classification

it('classifies provider errors by what the caller should do', function () {
    $cases = [
        [401, 'auth'], [403, 'auth'], [429, 'rate_limit'],
        [400, 'permanent'], [404, 'permanent'],
        [500, 'transient'], [529, 'transient'],
    ];

    foreach ($cases as [$status, $expected]) {
        $http = (new FakeHttpClient())->queue(new HttpResponse($status, '{"error":{"message":"x"}}'));

        try {
            (new AnthropicProvider($http, 'k', 'm'))->complete(llmRequest());
            $thrown = null;
        } catch (LlmException $e) {
            $thrown = $e;
        }

        expect($thrown?->errorClass)->toBe($expected);
    }
});

it('treats a transport failure as a timeout, which is safe to retry', function () {
    $http = (new FakeHttpClient())->queue(new HttpTransportException('connection timed out'));

    try {
        (new AnthropicProvider($http, 'k', 'm'))->complete(llmRequest());
        $thrown = null;
    } catch (LlmException $e) {
        $thrown = $e;
    }

    // No response means the call may not have been billed.
    expect($thrown->errorClass)->toBe('timeout')
        ->and($thrown->isRetryable())->toBeTrue();
});

// ------------------------------------------------------------ retry decorator

it('retries a transient failure and succeeds', function () {
    $inner = (new MockLlmProvider())
        ->queue(new LlmException('overloaded', 'transient', 529))
        ->queueVerdict(false);

    $provider = new RetryingLlmProvider($inner, new RecordingLogger(), maxAttempts: 3, baseSeconds: 0.001,
        sleeper: static fn (float $s) => null);

    expect($provider->complete(llmRequest())->text)->toContain('is_project')
        ->and($inner->callCount())->toBe(2);
});

it('retries a timeout', function () {
    $inner = (new MockLlmProvider())->queue(new LlmException('timed out', 'timeout'))->queueVerdict(false);
    $provider = new RetryingLlmProvider($inner, new RecordingLogger(), baseSeconds: 0.001, sleeper: static fn ($s) => null);

    $provider->complete(llmRequest());

    expect($inner->callCount())->toBe(2);
});

it('never retries a permanent failure', function () {
    $inner = (new MockLlmProvider())->queue(new LlmException('bad request', 'permanent', 400));
    $provider = new RetryingLlmProvider($inner, new RecordingLogger(), baseSeconds: 0.001, sleeper: static fn ($s) => null);

    // A malformed request stays malformed; each attempt is another billable
    // call that cannot succeed.
    expect(fn () => $provider->complete(llmRequest()))->toThrow(LlmException::class);
    expect($inner->callCount())->toBe(1);
});

it('never retries an auth failure', function () {
    $inner = (new MockLlmProvider())->queue(new LlmException('bad key', 'auth', 401));
    $provider = new RetryingLlmProvider($inner, new RecordingLogger(), baseSeconds: 0.001, sleeper: static fn ($s) => null);

    expect(fn () => $provider->complete(llmRequest()))->toThrow(LlmException::class);
    expect($inner->callCount())->toBe(1);
});

it('gives up at the attempt ceiling', function () {
    $inner = (new MockLlmProvider())->queue(
        new LlmException('a', 'transient', 500),
        new LlmException('b', 'transient', 500),
        new LlmException('c', 'transient', 500),
    );
    $provider = new RetryingLlmProvider($inner, new RecordingLogger(), maxAttempts: 3, baseSeconds: 0.001,
        sleeper: static fn ($s) => null);

    expect(fn () => $provider->complete(llmRequest()))->toThrow(LlmException::class);
    expect($inner->callCount())->toBe(3);
});

it('waits for the reported reset on a rate limit', function () {
    $slept = [];
    $inner = (new MockLlmProvider())
        ->queue(new LlmException('slow down', 'rate_limit', 429, retryAfterSeconds: 7))
        ->queueVerdict(false);

    $provider = new RetryingLlmProvider($inner, new RecordingLogger(), baseSeconds: 0.001,
        sleeper: static function (float $s) use (&$slept) { $slept[] = $s; });

    $provider->complete(llmRequest());

    // The provider reports when the window resets; the correct wait is that
    // instant, not an exponential guess.
    expect($slept[0])->toBe(7.0);
});

// ---------------------------------------------------------- cache decorator

it('skips a paid call when the identical request is cached', function () {
    $store = [];
    $inner = (new MockLlmProvider())->queueVerdict(true, true, 0.9);

    $provider = new CachingLlmProvider(
        $inner,
        function (string $k) use (&$store) { return $store[$k] ?? null; },
        function (string $k, string $v) use (&$store) { $store[$k] = $v; },
    );

    $first = $provider->complete(llmRequest());
    $second = $provider->complete(llmRequest());

    expect($inner->callCount())->toBe(1)
        ->and($second->fromCache)->toBeTrue()
        ->and($second->text)->toBe($first->text);
});

it('misses the cache when the prompt changes', function () {
    $store = [];
    $inner = (new MockLlmProvider())->queueVerdict(false)->queueVerdict(false);

    $provider = new CachingLlmProvider(
        $inner,
        function (string $k) use (&$store) { return $store[$k] ?? null; },
        function (string $k, string $v) use (&$store) { $store[$k] = $v; },
    );

    $provider->complete(new LlmRequest('system A', 'content'));
    $provider->complete(new LlmRequest('system B', 'content'));

    // A prompt change must invalidate every entry, or the cache serves
    // decisions made under a prompt nobody is using any more.
    expect($inner->callCount())->toBe(2);
});
