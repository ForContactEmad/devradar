<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DateTimeImmutable;

/**
 * The only way time enters the domain.
 *
 * Recency decay, the rolling seven-day window and budget cycle boundaries all
 * depend on "now". Injecting it keeps the scoring engine and the window
 * sweeper pure and deterministically testable.
 */
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
