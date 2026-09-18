<?php

declare(strict_types=1);

namespace DevRadar\Domain\Query;

/**
 * Feed-level statistics.
 *
 * Public numbers only. Spend, budget position and per-query yield are
 * operational figures that belong behind the admin boundary, not on a public
 * endpoint -- they tell a competitor what the product costs to run.
 */
final readonly class Statistics
{
    /**
     * @param list<TaxonomyCount> $topCategories
     * @param list<TaxonomyCount> $topTechnologies
     */
    public function __construct(
        public int $projectsInWindow,
        public int $projectsPublishedToday,
        public int $projectsWithRepository,
        public float $averageScore,
        public array $topCategories,
        public array $topTechnologies,
        public ?string $lastPublishedAt,
        public int $windowDays,
    ) {}
}
