<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Port\ProjectQueryRepositoryInterface;
use DevRadar\Domain\Query\Paginated;
use DevRadar\Domain\Query\ProjectDetail;
use DevRadar\Domain\Query\ProjectQuery;
use DevRadar\Domain\Query\ProjectSort;
use DevRadar\Domain\Query\ProjectSummary;
use DevRadar\Domain\Query\Statistics;
use DevRadar\Domain\Query\TaxonomyCount;
use RuntimeException;

/**
 * In-memory read model.
 *
 * Implements filtering, sorting and paging the same way the SQL repository
 * does, so the service tests exercise real behaviour rather than a stub that
 * returns whatever was handed to it.
 */
final class InMemoryProjectQueryRepository implements ProjectQueryRepositoryInterface
{
    /** @var list<ProjectSummary> */
    public array $projects = [];

    /** @var array<string, ProjectDetail> */
    public array $details = [];

    /** @var list<TaxonomyCount> */
    public array $categoryCounts = [];

    /** @var list<TaxonomyCount> */
    public array $technologyCounts = [];

    public bool $explode = false;

    /** @var list<ProjectQuery> */
    public array $received = [];

    public function search(ProjectQuery $query): Paginated
    {
        if ($this->explode) {
            throw new RuntimeException('database unavailable');
        }

        $this->received[] = $query;
        $matching = $this->projects;

        if ($query->categories !== []) {
            $matching = array_filter($matching, fn (ProjectSummary $p) => in_array($p->category, $query->categories, true));
        }

        if ($query->technologies !== []) {
            $matching = array_filter(
                $matching,
                fn (ProjectSummary $p) => array_intersect($p->technologies, $query->technologies) !== [],
            );
        }

        if ($query->projectType !== null) {
            $matching = array_filter($matching, fn (ProjectSummary $p) => $p->projectType === $query->projectType);
        }

        if ($query->hasRepository !== null) {
            $matching = array_filter(
                $matching,
                fn (ProjectSummary $p) => ($p->repositoryUrl !== null) === $query->hasRepository,
            );
        }

        if ($query->minScore !== null) {
            $matching = array_filter($matching, fn (ProjectSummary $p) => $p->score >= $query->minScore);
        }

        $search = $query->normalisedSearch();

        if ($search !== null) {
            $matching = array_filter($matching, fn (ProjectSummary $p) => stripos(
                $p->name . ' ' . ($p->description ?? ''),
                $search,
            ) !== false);
        }

        $matching = array_values($matching);

        usort($matching, match ($query->sort) {
            ProjectSort::Latest => fn ($a, $b) => $b->discoveredAt <=> $a->discoveredAt,
            ProjectSort::Oldest => fn ($a, $b) => $a->discoveredAt <=> $b->discoveredAt,
            default => fn ($a, $b) => $b->score <=> $a->score,
        });

        return new Paginated(
            array_slice($matching, $query->offset(), $query->perPage),
            count($matching),
            $query->page,
            $query->perPage,
        );
    }

    public function findBySlug(string $slug): ?ProjectDetail
    {
        if ($this->explode) {
            throw new RuntimeException('database unavailable');
        }

        return $this->details[$slug] ?? null;
    }

    public function categories(): array
    {
        return $this->categoryCounts;
    }

    public function technologies(?int $limit = null): array
    {
        return $limit === null ? $this->technologyCounts : array_slice($this->technologyCounts, 0, $limit);
    }

    public function statistics(int $windowDays): Statistics
    {
        return new Statistics(
            projectsInWindow: count($this->projects),
            projectsPublishedToday: 0,
            projectsWithRepository: count(array_filter($this->projects, fn ($p) => $p->repositoryUrl !== null)),
            averageScore: 0.0,
            topCategories: array_slice($this->categoryCounts, 0, 5),
            topTechnologies: array_slice($this->technologyCounts, 0, 10),
            lastPublishedAt: null,
            windowDays: $windowDays,
        );
    }
}
