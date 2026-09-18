<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\X;

/**
 * Bounded exponential backoff with full jitter.
 *
 * Jitter matters more than it looks: without it, several workers that fail
 * together retry together, producing a synchronised burst against an API that
 * has just told you it is overloaded.
 *
 * A rate-limit failure is not backed off exponentially -- X reports exactly
 * when the window resets, so the correct wait is that instant, not a guess.
 */
final readonly class RetryPolicy
{
    public function __construct(
        private int $maxAttempts = 3,
        private float $baseSeconds = 1.0,
        private float $maxSeconds = 60.0,
    ) {}

    public function shouldRetry(XApiException $error, int $attempt): bool
    {
        return $error->isRetryable() && $attempt < $this->maxAttempts;
    }

    /**
     * @param int      $attempt   1-based
     * @param float|null $jitter  0..1; injected so tests are deterministic
     */
    public function delayFor(XApiException $error, int $attempt, ?float $jitter = null): float
    {
        if ($error->errorClass === 'rate_limit' && $error->retryAfterSeconds !== null) {
            return min((float) $error->retryAfterSeconds, $this->maxSeconds);
        }

        $exponential = $this->baseSeconds * (2 ** ($attempt - 1));
        $capped = min($exponential, $this->maxSeconds);
        $jitter ??= mt_rand() / mt_getrandmax();

        return round($capped * $jitter, 3);
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
