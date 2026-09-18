<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Classification\LlmException;
use DevRadar\Domain\Classification\LlmRequest;
use DevRadar\Domain\Classification\LlmResponse;
use DevRadar\Domain\Classification\ModelIdentity;
use DevRadar\Domain\Port\LlmProviderInterface;

/**
 * Scripted model provider for tests.
 *
 * NO TEST IN DEVRADAR MAY CALL A REAL MODEL. A suite that costs money is a
 * suite you stop running, and a suite whose results depend on a model's mood
 * is not a suite at all -- it cannot tell a regression from a sampling
 * difference.
 *
 * Queue LlmResponse objects for successes and LlmException objects for
 * failures, in the order they should occur.
 */
final class MockLlmProvider implements LlmProviderInterface
{
    /** @var list<LlmResponse|LlmException> */
    private array $queue = [];

    /** @var list<LlmRequest> */
    public array $requests = [];

    public function __construct(
        private readonly string $provider = 'mock',
        private readonly string $model = 'mock-1',
    ) {}

    public function queue(LlmResponse|LlmException ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    /** Convenience: queue a well-formed JSON verdict. */
    public function queueVerdict(bool $isProject, ?bool $isNew = null, ?float $confidence = null, string $reason = 'test'): self
    {
        $payload = $isProject
            ? ['is_project' => true, 'is_new' => $isNew ?? true, 'confidence' => $confidence ?? 0.9, 'reason' => $reason]
            : ['is_project' => false, 'reason' => $reason];

        return $this->queue(new LlmResponse(
            text: json_encode($payload, JSON_THROW_ON_ERROR),
            inputTokens: 120,
            outputTokens: 30,
        ));
    }

    /** Convenience: queue arbitrary raw text, for malformed-output tests. */
    public function queueRaw(string $text, int $inputTokens = 120, int $outputTokens = 30): self
    {
        return $this->queue(new LlmResponse($text, $inputTokens, $outputTokens));
    }

    public function identity(): ModelIdentity
    {
        return new ModelIdentity($this->provider, $this->model, 'v-test');
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \RuntimeException(sprintf(
                'MockLlmProvider ran out of scripted responses after %d call(s). '
                . 'The code under test called the model more times than the test expected.',
                count($this->requests),
            ));
        }

        if ($next instanceof LlmException) {
            throw $next;
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): ?LlmRequest
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }
}
