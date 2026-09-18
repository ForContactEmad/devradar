<?php

declare(strict_types=1);

namespace DevRadar\Domain\Statistics;

/**
 * Everything the statistics endpoint returns.
 *
 * Assembled once on the backend and cached, because the alternative is a
 * dashboard that fires eight requests and stitches them together -- which
 * makes the page as slow as its slowest query and puts aggregation logic in
 * the browser.
 */
final readonly class TrendsReport
{
    /**
     * @param list<Trend>             $headlines
     * @param list<TaxonomyTrend>     $topCategories
     * @param list<TaxonomyTrend>     $topTechnologies
     * @param list<RankedProject>     $topProjects
     */
    public function __construct(
        public int $windowDays,
        public array $headlines,
        public TimeSeries $projectsPerDay,
        public TimeSeries $engagementPerDay,
        public array $topCategories,
        public array $topTechnologies,
        public array $topProjects,
        public ?TimeSeries $repositoryStars = null,
        public ?string $generatedAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'window_days' => $this->windowDays,
            'generated_at' => $this->generatedAt,
            'headlines' => array_map(fn (Trend $t) => $t->toArray(), $this->headlines),
            'projects_per_day' => $this->projectsPerDay->toArray(),
            'engagement_per_day' => $this->engagementPerDay->toArray(),
            'top_categories' => array_map(fn (TaxonomyTrend $t) => $t->toArray(), $this->topCategories),
            'top_technologies' => array_map(fn (TaxonomyTrend $t) => $t->toArray(), $this->topTechnologies),
            'top_projects' => array_map(fn (RankedProject $p) => $p->toArray(), $this->topProjects),
            // Null until enrichment has two snapshots of the same repository.
            // Absent is honest; a flat line at zero would be a lie.
            'repository_stars' => $this->repositoryStars?->toArray(),
        ];
    }
}
