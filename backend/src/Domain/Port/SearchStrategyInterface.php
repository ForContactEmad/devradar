<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Collection\SearchPlan;

/**
 * Decides WHAT to search and over what window.
 *
 * Kept separate from the collector so that changing the search approach --
 * seven-day sweep, incremental poll, curated-account pass, backfill -- means
 * writing another strategy rather than adding a branch to the collector.
 */
interface SearchStrategyInterface
{
    /** @return list<SearchPlan> */
    public function plan(): array;
}
