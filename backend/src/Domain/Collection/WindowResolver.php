<?php

declare(strict_types=1);

namespace DevRadar\Domain\Collection;

use DevRadar\Domain\Port\ClockInterface;

/**
 * Computes the rolling collection window.
 *
 * Two safety margins are applied, and both exist because of provider
 * behaviour rather than taste:
 *
 *   - The start is pulled back to just INSIDE the provider's seven-day
 *     horizon, not exactly onto it. A request whose start_time has drifted a
 *     second past the boundary is rejected outright, and a rejected request
 *     still costs a round trip.
 *
 *   - The end is set slightly in the past. X documents that an unspecified
 *     end_time defaults to roughly 30 seconds before the query, because the
 *     index is not instantly consistent. Asking for the last few seconds
 *     returns nothing useful.
 *
 * Time enters only through the clock, so this is fully deterministic in tests.
 */
final readonly class WindowResolver
{
    public function __construct(
        private ClockInterface $clock,
        private int $windowDays = 7,
        private int $startMarginSeconds = 300,
        private int $endMarginSeconds = 30,
    ) {}

    public function resolve(): CollectionWindow
    {
        $now = $this->clock->now();

        $end = $now->modify("-{$this->endMarginSeconds} seconds");
        $start = $now
            ->modify("-{$this->windowDays} days")
            ->modify("+{$this->startMarginSeconds} seconds");

        return new CollectionWindow($start, $end);
    }
}
