<?php

declare(strict_types=1);

namespace DevRadar\Application\Query;

use DevRadar\Domain\Port\ProjectQueryRepositoryInterface;
use DevRadar\Domain\Query\Statistics;

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
