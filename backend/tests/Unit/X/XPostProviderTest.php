<?php

declare(strict_types=1);

use DevRadar\Domain\Ingestion\SearchCriteria;
use DevRadar\Domain\Ingestion\StopReason;
use DevRadar\Infrastructure\Http\HttpResponse;
use DevRadar\Infrastructure\Http\HttpTransportException;
use DevRadar\Infrastructure\X\XApiConfig;
use DevRadar\Infrastructure\X\XApiException;
use DevRadar\Infrastructure\X\XErrorClassifier;
use DevRadar\Infrastructure\X\XPostMapper;
use DevRadar\Infrastructure\X\XPostProvider;
use DevRadar\Infrastructure\X\XRequestBuilder;
use DevRadar\Infrastructure\X\RetryPolicy;
use Tests\Fake\FakeBudgetGuard;
use Tests\Fake\FakeHttpClient;
use Tests\Fake\RecordingLogger;

/**
 * Provider tests. Every one of these runs with a scripted transport: no
 * network, no bearer token, no spend.
 */
function fixtureBody(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/../../Fixtures/' . $name . '.json');
}

function ok(string $fixture, array $headers = []): HttpResponse
{
    return new HttpResponse(200, fixtureBody($fixture), $headers);
}

/** @return array{0: XPostProvider, 1: FakeHttpClient, 2: FakeBudgetGuard, 3: RecordingLogger} */
function makeProvider(FakeHttpClient $http, ?FakeBudgetGuard $budget = null, int $maxRetries = 3): array
{
    $config = new XApiConfig(bearerToken: 'super-secret-token-value', maxRetries: $maxRetries);
    $budget ??= new FakeBudgetGuard();
    $logger = new RecordingLogger();

    $provider = new XPostProvider(
        http: $http,
        config: $config,
        requestBuilder: new XRequestBuilder($config),
        mapper: new XPostMapper(),
        classifier: new XErrorClassifier(),
        retryPolicy: new RetryPolicy(maxAttempts: $maxRetries, baseSeconds: 0.001),
        budget: $budget,
        logger: $logger,
        // Never actually sleep in a test.
        sleeper: static fn (float $s) => null,
    );

    return [$provider, $http, $budget, $logger];
}

// ------------------------------------------------------------- happy path

it('returns internal DTOs, never raw provider payloads', function () {
    [$provider] = makeProvider((new FakeHttpClient())->queue(ok('x-search-page-2')));

    $batch = $provider->search(new SearchCriteria('rust'));

    expect($batch->posts)->toHaveCount(1)
        ->and($batch->posts[0])->toBeInstanceOf(DevRadar\Domain\Ingestion\RawPost::class)
        ->and($batch->posts[0]->bestUrl())->toBe('https://github.com/rs/tinysched');
});

it('reports the provider name for the run ledger', function () {
    [$provider] = makeProvider((new FakeHttpClient())->queue(ok('x-search-page-2')));

    expect($provider->name())->toBe('x');
});

// -------------------------------------------------------------- pagination

it('follows next_token across pages', function () {
    $http = (new FakeHttpClient())->queue(ok('x-search-page-1'), ok('x-search-page-2'));
    [$provider] = makeProvider($http);

    $batch = $provider->search(new SearchCriteria('rust', maxPosts: 300, maxPages: 5));

    expect($http->requestCount())->toBe(2)
        ->and($batch->posts)->toHaveCount(3)
        ->and($batch->requestCount)->toBe(2)
        ->and($batch->stopReason)->toBe(StopReason::Exhausted);
});

it('sends the pagination token on the second request only', function () {
    $http = (new FakeHttpClient())->queue(ok('x-search-page-1'), ok('x-search-page-2'));
    [$provider] = makeProvider($http);

    $provider->search(new SearchCriteria('rust', maxPages: 5));

    expect($http->requests[0]['query'])->not->toHaveKey('pagination_token')
        ->and($http->requests[1]['query']['pagination_token'])->toBe('b26v89c19zqg8o3fpds1ab2c3d4e5f6g7');
});

it('stops at the page cap even when more pages exist', function () {
    $http = (new FakeHttpClient())->queue(ok('x-search-page-1'));
    [$provider] = makeProvider($http);

    $batch = $provider->search(new SearchCriteria('rust', maxPages: 1));

    expect($http->requestCount())->toBe(1)
        ->and($batch->stopReason)->toBe(StopReason::PageCapReached);
});

it('merges authors across pages without duplicating them', function () {
    $http = (new FakeHttpClient())->queue(ok('x-search-page-1'), ok('x-search-page-2'));
    [$provider] = makeProvider($http);

    $batch = $provider->search(new SearchCriteria('rust', maxPages: 5));

    expect($batch->authors)->toHaveCount(3)
        ->and($batch->authorFor($batch->posts[0])->username)->toBe('acmedev');
});

// ------------------------------------------------------------------ budget

it('refuses to start a page when the budget guard says no', function () {
    $http = new FakeHttpClient();
    [$provider, , $budget] = makeProvider($http, FakeBudgetGuard::exhausted());

    $batch = $provider->search(new SearchCriteria('rust'));

    // Nothing was requested, so nothing was charged.
    expect($http->requestCount())->toBe(0)
        ->and($batch->stopReason)->toBe(StopReason::BudgetRefused)
        ->and($batch->posts)->toHaveCount(0)
        ->and($budget->allowChecks)->toBe(1);
});

it('treats a budget refusal as a result, not an exception', function () {
    [$provider] = makeProvider(new FakeHttpClient(), FakeBudgetGuard::exhausted());

    // Throwing here would invite a retry, and a retry is a repeat purchase.
    $batch = $provider->search(new SearchCriteria('rust'));

    expect($batch)->toBeInstanceOf(DevRadar\Domain\Ingestion\PostBatch::class);
});

it('records every billable resource, posts and authors alike', function () {
    $http = (new FakeHttpClient())->queue(ok('x-search-page-1'));
    [$provider, , $budget] = makeProvider($http);

    $batch = $provider->search(new SearchCriteria('rust', maxPages: 1));

    // 2 posts + 2 author objects.
    expect($batch->billableResources)->toBe(4)
        ->and($budget->recorded)->toBe(4);
});

it('stops paginating once the post cap is reached', function () {
    $http = (new FakeHttpClient())->queue(ok('x-search-page-1'));
    [$provider] = makeProvider($http);

    $batch = $provider->search(new SearchCriteria('rust', maxPosts: 2, maxPages: 5));

    expect($batch->posts)->toHaveCount(2)
        ->and($http->requestCount())->toBe(1);
});

// ------------------------------------------------------- errors and retry

it('retries a server error and succeeds', function () {
    $http = (new FakeHttpClient())->queue(
        new HttpResponse(503, '{}'),
        ok('x-search-page-2'),
    );
    [$provider] = makeProvider($http);

    $batch = $provider->search(new SearchCriteria('rust'));

    expect($http->requestCount())->toBe(2)
        ->and($batch->posts)->toHaveCount(1);
});

it('retries a connection failure, which cannot have been charged', function () {
    $http = (new FakeHttpClient())->queue(
        new HttpTransportException('connection refused'),
        ok('x-search-page-2'),
    );
    [$provider] = makeProvider($http);

    expect($provider->search(new SearchCriteria('rust'))->posts)->toHaveCount(1);
});

it('never retries a malformed query', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(400, fixtureBody('x-error-400')));
    [$provider] = makeProvider($http);

    // Retrying cannot succeed, and each attempt is another round trip.
    expect(fn () => $provider->search(new SearchCriteria('rust')))
        ->toThrow(XApiException::class);

    expect($http->requestCount())->toBe(1);
});

it('never retries an authentication failure', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(401, fixtureBody('x-error-401')));
    [$provider] = makeProvider($http);

    expect(fn () => $provider->search(new SearchCriteria('rust')))->toThrow(XApiException::class);
    expect($http->requestCount())->toBe(1);
});

it('gives up after the retry ceiling rather than looping', function () {
    $http = (new FakeHttpClient())->queue(
        new HttpResponse(500, '{}'),
        new HttpResponse(500, '{}'),
        new HttpResponse(500, '{}'),
    );
    [$provider] = makeProvider($http, maxRetries: 3);

    expect(fn () => $provider->search(new SearchCriteria('rust')))->toThrow(XApiException::class);
    expect($http->requestCount())->toBe(3);
});

// -------------------------------------------------------------- rate limit

it('paces itself when the rate-limit window is nearly exhausted', function () {
    $slept = [];
    $config = new XApiConfig(bearerToken: 't');
    $http = (new FakeHttpClient())->queue(
        ok('x-search-page-1', [
            'x-rate-limit-limit' => '450',
            'x-rate-limit-remaining' => '1',
            'x-rate-limit-reset' => (string) (time() + 12),
        ]),
        ok('x-search-page-2'),
    );

    $provider = new XPostProvider(
        $http, $config, new XRequestBuilder($config), new XPostMapper(),
        new XErrorClassifier(), new RetryPolicy(baseSeconds: 0.001),
        new FakeBudgetGuard(), new RecordingLogger(),
        static function (float $s) use (&$slept) { $slept[] = $s; },
    );

    $provider->search(new SearchCriteria('rust', maxPages: 5));

    // Slowing down before the wall beats discovering it by hitting it.
    expect($slept)->not->toBeEmpty();
});

// ----------------------------------------------------------- partial data

it('keeps the good posts from a page that also reported errors', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, fixtureBody('x-search-partial-errors')));
    [$provider] = makeProvider($http);

    $batch = $provider->search(new SearchCriteria('rust'));

    // The page has already been paid for; discarding it would waste the spend.
    expect($batch->posts)->toHaveCount(1)
        ->and($batch->partialErrors)->toHaveCount(1);
});

// -------------------------------------------------------------- security

it('never writes the bearer token to a log', function () {
    $http = (new FakeHttpClient())->queue(
        new HttpResponse(401, '{"detail":"Unauthorized: Bearer super-secret-token-value rejected"}'),
    );
    [$provider, , , $logger] = makeProvider($http);

    try {
        $provider->search(new SearchCriteria('rust'));
    } catch (XApiException) {
        // expected
    }

    expect($logger->dump())->not->toContain('super-secret-token-value');
});

it('never puts the token in an exception message', function () {
    $http = (new FakeHttpClient())->queue(
        new HttpResponse(401, '{"detail":"token Bearer super-secret-token-value is invalid"}'),
    );
    [$provider] = makeProvider($http);

    try {
        $provider->search(new SearchCriteria('rust'));
        $thrown = null;
    } catch (XApiException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->not->toContain('super-secret-token-value')
        ->and($thrown->getMessage())->toContain('[redacted]');
});

it('sends the credential in the header and never in the query string', function () {
    $http = (new FakeHttpClient())->queue(ok('x-search-page-2'));
    [$provider] = makeProvider($http);

    $provider->search(new SearchCriteria('rust'));

    $request = $http->requests[0];

    expect($request['headers']['Authorization'])->toContain('Bearer ')
        ->and(json_encode($request['query']))->not->toContain('super-secret-token-value');
});
