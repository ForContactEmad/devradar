<?php

declare(strict_types=1);

namespace Tests\Fake;

use DateTimeImmutable;
use DevRadar\Domain\Port\StatisticsRepositoryInterface;
use DevRadar\Domain\Statistics\RankedProject;

/**
 * Scripted aggregations, keyed by which period is asked for.
 *
 * The service asks for two ranges -- current and previous -- and the whole
 * point of the trend logic is that it compares them, so the fake has to be
 * able to answer differently for each.
 */
final class InMemoryStatisticsRepository implements StatisticsRepositoryInterface
{
    /** @var array<string, array{projects: int, engagement: float, average_score: float, with_repository: int}> */
    public array $totalsByPeriod = [];

    /** @var array<string, array<string, float>> */
    public array $perDay = [];

    /** @var array<string, array<string, array{name: string, count: int, kind: ?string}>> */
    public array $taxonomy = [];

    /** @var list<RankedProject> */
    public array $top = [];

    /** @var array<string, float> */
    public array $stars = [];

    public int $totalsCalls = 0;

    public function totals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $this->totalsCalls++;

        return $this->totalsByPeriod[$this->key($from)]
            ?? ['projects' => 0, 'engagement' => 0.0, 'average_score' => 0.0, 'with_repository' => 0];
    }

    public function projectsPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->perDay['projects'] ?? [];
    }

    public function engagementPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->perDay['engagement'] ?? [];
    }

    public function taxonomyCounts(string $dimension, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->taxonomy["{$dimension}:{$this->key($from)}"] ?? [];
    }

    public function topProjects(DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array
    {
        return array_slice($this->top, 0, $limit);
    }

    public function repositoryStarsPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->stars;
    }

    /** 'current' for the recent window, 'previous' for the one before it. */
    private function key(DateTimeImmutable $from): string
    {
        $daysAgo = (int) round((time() - $from->getTimestamp()) / 86400);

        return $daysAgo > 10 ? 'previous' : 'current';
    }
}
