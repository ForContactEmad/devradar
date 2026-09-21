<?php

declare(strict_types=1);

namespace DevRadar\Application\Query;

use DevRadar\Domain\Port\ProjectQueryRepositoryInterface;
use DevRadar\Domain\Query\Statistics;

/**
 * Builds the statistics page for the discovery window.
 *
 * Every figure is scoped to the same window as the feed -- seven days by
 * default -- so the stats describe what the feed shows rather than all history.
 */
final readonly class StatisticsService
{
    public function __construct(
        private ProjectQueryRepositoryInterface $repository,
        private int $windowDays = 7,
    ) {}

    public function forWindow(): Statistics
    {
        return $this->repository->statistics($this->windowDays);
    }
}
