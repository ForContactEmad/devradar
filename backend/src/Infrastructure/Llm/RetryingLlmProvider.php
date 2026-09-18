<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Llm;

use DevRadar\Domain\Classification\LlmRequest;
use DevRadar\Domain\Classification\LlmResponse;
use DevRadar\Domain\Classification\ModelIdentity;
use DevRadar\Domain\Classification\LlmException;
use DevRadar\Domain\Port\LlmProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Bounded retry with full jitter, wrapped around any provider.
 *
 * A decorator rather than a feature of each provider: retry policy is a
 * transport concern with nothing to do with what a launch is, and every
 * provider that implemented it separately would implement it slightly
 * differently.
 *
 * PERMANENT AND AUTH FAILURES ARE NEVER RETRIED. A malformed request stays
 * malformed, and a rejected credential stays rejected -- each attempt is
 * another billable call that cannot succeed.
 */
final readonly class RetryingLlmProvider implements LlmProviderInterface
{
    public function __construct(
        private LlmProviderInterface $inner,
        private LoggerInterface $logger,
        private int $maxAttempts = 3,
        private float $baseSeconds = 1.0,
        private float $maxSeconds = 30.0,
        /** Injected so tests never actually sleep. */
        private ?\Closure $sleeper = null,
    ) {}

    public function identity(): ModelIdentity
    {
        return $this->inner->identity();
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->inner->complete($request);
            } catch (LlmException $e) {
                if (! $e->isRetryable() || $attempt >= $this->maxAttempts) {
                    throw $e;
                }

                $delay = $this->delayFor($e, $attempt);

                $this->logger->warning('llm.retrying', [
                    'attempt' => $attempt,
                    'error_class' => $e->errorClass,
                    'status' => $e->status,
                    'delay_seconds' => $delay,
                ]);

                $this->sleep($delay);
            }
        }
    }

    private function delayFor(LlmException $e, int $attempt): float
    {
        // A rate limit reports when the window resets; the correct wait is
        // that instant, not an exponential guess.
        if ($e->errorClass === 'rate_limit' && $e->retryAfterSeconds !== null) {
            return min((float) $e->retryAfterSeconds, $this->maxSeconds);
        }

        $capped = min($this->baseSeconds * (2 ** ($attempt - 1)), $this->maxSeconds);

        // Full jitter: without it, workers that fail together retry together
        // and produce a synchronised burst against a provider that has just
        // said it is overloaded.
        return round($capped * (mt_rand() / mt_getrandmax()), 3);
    }

    private function sleep(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }
}
