<?php

declare(strict_types=1);

namespace DevRadar\Domain\Support;

/**
 * Bounded exponential backoff with full jitter. Pure, no I/O.
 *
 * Shared by every provider rather than reimplemented per integration. Three
 * copies of this arithmetic would drift, and the one that drifted would be
 * the least tested.
 *
 * WHY FULL JITTER. Without it, workers that fail together retry together and
 * produce a synchronised burst against a service that has just said it is
 * overloaded. Randomising the whole delay, not just a fraction of it, spreads
 * the retries out.
 *
 * A SERVER-SUPPLIED RESET TIME ALWAYS WINS. When a provider says exactly when
 * its window reopens, waiting that long is correct and guessing
 * exponentially is not.
 */
final readonly class BackoffPolicy
{
    public function __construct(
        private float $baseSeconds = 1.0,
        private float $maxSeconds = 60.0,
    ) {}

    /**
     * @param int        $attempt          1-based
     * @param float|null $serverRetryAfter seconds the provider asked for
     * @param float|null $jitter           0..1, injected so tests are deterministic
     */
    public function delayFor(int $attempt, ?float $serverRetryAfter = null, ?float $jitter = null): float
    {
        if ($serverRetryAfter !== null && $serverRetryAfter > 0) {
            return min($serverRetryAfter, $this->maxSeconds);
        }

        $exponential = $this->baseSeconds * (2 ** (max(1, $attempt) - 1));
        $capped = min($exponential, $this->maxSeconds);
        $jitter ??= mt_rand() / mt_getrandmax();

        return round($capped * $jitter, 3);
    }
}
