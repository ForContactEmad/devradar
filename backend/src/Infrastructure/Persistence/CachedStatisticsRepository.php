<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DateTimeImmutable;
use DevRadar\Domain\Port\StatisticsRepositoryInterface;

/**
 * Caches aggregations. A decorator, not a feature of the repository.
 *
 * WHY THE CACHE SITS HERE and not around the whole report: the aggregations
 * are the expensive part and they are keyed by date range, so two callers
 * asking about the same week share the work even if one wants categories and
 * the other wants the daily series.
 *
 * THE KEY IS THE DATE RANGE, TRUNCATED TO THE HOUR. Keying on an exact
 * timestamp would miss on every request, since "now" moves continuously; an
 * hourly bucket means a busy hour costs one set of queries. The feed only
 * changes when a pipeline run publishes, so an hour-stale count is not a
 * meaningful inaccuracy.
 */
final readonly class CachedStatisticsRepository implements StatisticsRepositoryInterface
{
    /**
     * @param \Closure(string, int, \Closure): mixed $remember
     */
    public function __construct(
        private StatisticsRepositoryInterface $inner,
        private \Closure $remember,
        private int $ttlSeconds = 900,
    ) {}

    public function projectsPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->cached('projects_per_day', $from, $to, fn () => $this->inner->projectsPerDay($from, $to));
    }

    public function engagementPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->cached('engagement_per_day', $from, $to, fn () => $this->inner->engagementPerDay($from, $to));
    }

    public function totals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->cached('totals', $from, $to, fn () => $this->inner->totals($from, $to));
    }

    public function taxonomyCounts(string $dimension, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->cached("taxonomy:{$dimension}", $from, $to, fn () => $this->inner->taxonomyCounts($dimension, $from, $to));
    }

    public function topProjects(DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array
    {
        return $this->cached("top_projects:{$limit}", $from, $to, fn () => $this->inner->topProjects($from, $to, $limit));
    }

    public function repositoryStarsPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->cached('repository_stars', $from, $to, fn () => $this->inner->repositoryStarsPerDay($from, $to));
    }

    /** @param \Closure(): mixed $compute */
    private function cached(string $name, DateTimeImmutable $from, DateTimeImmutable $to, \Closure $compute): mixed
    {
        $key = sprintf(
            'stats:%s:%s:%s',
            $name,
            $from->format('Y-m-d-H'),
            $to->format('Y-m-d-H'),
        );

        return ($this->remember)($key, $this->ttlSeconds, $compute);
    }
}
