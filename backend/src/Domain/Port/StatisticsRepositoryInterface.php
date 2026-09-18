<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DateTimeImmutable;
use DevRadar\Domain\Statistics\RankedProject;

/**
 * The aggregation boundary.
 *
 * EVERY HEAVY CALCULATION IS ON THIS SIDE. Counting, grouping and summing
 * happen in the database, which is where the data already is and where an
 * index can help. Pulling rows out to count them in PHP -- or worse, in the
 * browser -- would scale with the feed rather than with the answer.
 *
 * Each method returns the smallest thing that answers the question: a map of
 * date to count, not a list of projects to be counted by the caller.
 */
interface StatisticsRepositoryInterface
{
    /** @return array<string, float> Y-m-d => count */
    public function projectsPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /** @return array<string, float> Y-m-d => total interactions on projects discovered that day */
    public function engagementPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /** @return array{projects: int, engagement: float, average_score: float, with_repository: int} */
    public function totals(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * @param  'category'|'technology' $dimension
     * @return array<string, array{name: string, count: int, kind: ?string}>
     */
    public function taxonomyCounts(string $dimension, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /** @return list<RankedProject> */
    public function topProjects(DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array;

    /**
     * Total stars across tracked repositories, by day.
     *
     * Requires at least two enrichment snapshots to say anything. Returns an
     * empty map when history does not exist yet, and the caller omits the
     * series rather than drawing a flat line at zero.
     *
     * @return array<string, float>
     */
    public function repositoryStarsPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
