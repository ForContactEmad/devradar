<?php

declare(strict_types=1);

namespace DevRadar\Application\Statistics;

use DateTimeImmutable;
use DevRadar\Domain\Port\StatisticsRepositoryInterface;
use DevRadar\Domain\Statistics\TaxonomyTrend;
use DevRadar\Domain\Statistics\TimeSeries;
use DevRadar\Domain\Statistics\Trend;
use DevRadar\Domain\Statistics\TrendDirection;
use DevRadar\Domain\Statistics\TrendsReport;

/**
 * Assembles the statistics report.
 *
 * EVERY HEADLINE IS A COMPARISON. The service asks the repository for two
 * periods -- the current window and the one immediately before it, of equal
 * length -- and reports the pair. A dashboard that shows only the current
 * figure cannot tell its reader whether anything is happening.
 *
 * The equal-length rule is not a detail. Comparing a seven-day window against
 * a fourteen-day one would show a permanent 50% decline, and somebody would
 * spend a morning looking for the bug.
 */
final readonly class TrendsService
{
    public function __construct(
        private StatisticsRepositoryInterface $repository,
        private int $windowDays = 7,
        /**
         * Below this many projects, no direction is claimed.
         *
         * DevRadar publishes on the order of ten a week, so a jump from two
         * to six is a 200% rise by arithmetic and noise by any honest
         * reading.
         */
        private int $minimumSample = 8,
        /** Fractional change treated as drift rather than movement. */
        private float $deadBand = 0.05,
        private int $topLimit = 10,
    ) {}

    public function report(?DateTimeImmutable $now = null): TrendsReport
    {
        $now ??= new DateTimeImmutable();

        $currentFrom = $now->modify("-{$this->windowDays} days");
        $previousFrom = $now->modify('-' . ($this->windowDays * 2) . ' days');
        $previousTo = $currentFrom;

        $current = $this->repository->totals($currentFrom, $now);
        $previous = $this->repository->totals($previousFrom, $previousTo);

        // The sample is the larger of the two periods: a trend computed from
        // eight projects last week and one this week is still a trend worth
        // reporting, and using only the current count would suppress exactly
        // the collapse most worth seeing.
        $sample = max($current['projects'], $previous['projects']);

        $headlines = [
            $this->trend('projects_discovered', $current['projects'], $previous['projects'], $sample),
            $this->trend('total_engagement', $current['engagement'], $previous['engagement'], $sample),
            $this->trend('average_score', $current['average_score'], $previous['average_score'], $sample),
            $this->trend('projects_with_repository', $current['with_repository'], $previous['with_repository'], $sample),
        ];

        // Drawn across both windows so the chart shows the comparison rather
        // than asserting it in a number the reader has to trust.
        $projectsPerDay = TimeSeries::dense(
            'projects_per_day',
            $previousFrom,
            $now,
            $this->repository->projectsPerDay($previousFrom, $now),
        );

        $engagementPerDay = TimeSeries::dense(
            'engagement_per_day',
            $previousFrom,
            $now,
            $this->repository->engagementPerDay($previousFrom, $now),
        );

        $stars = $this->repository->repositoryStarsPerDay($previousFrom, $now);

        return new TrendsReport(
            windowDays: $this->windowDays,
            headlines: $headlines,
            projectsPerDay: $projectsPerDay,
            engagementPerDay: $engagementPerDay,
            topCategories: $this->taxonomy('category', $currentFrom, $now, $previousFrom, $previousTo, $current['projects']),
            topTechnologies: $this->taxonomy('technology', $currentFrom, $now, $previousFrom, $previousTo, $current['projects']),
            topProjects: $this->repository->topProjects($currentFrom, $now, $this->topLimit),
            // Omitted rather than drawn flat when enrichment has no history.
            repositoryStars: $stars === []
                ? null
                : TimeSeries::dense('repository_stars', $previousFrom, $now, $stars),
            generatedAt: $now->format(DATE_ATOM),
        );
    }

    private function trend(string $metric, float $current, float $previous, int $sample): Trend
    {
        return Trend::compare($metric, $current, $previous, $sample, $this->minimumSample, $this->deadBand);
    }

    /**
     * @return list<TaxonomyTrend>
     */
    private function taxonomy(
        string $dimension,
        DateTimeImmutable $currentFrom,
        DateTimeImmutable $currentTo,
        DateTimeImmutable $previousFrom,
        DateTimeImmutable $previousTo,
        int $totalProjects,
    ): array {
        $current = $this->repository->taxonomyCounts($dimension, $currentFrom, $currentTo);
        $previous = $this->repository->taxonomyCounts($dimension, $previousFrom, $previousTo);

        $trends = [];

        foreach ($current as $slug => $row) {
            $previousCount = $previous[$slug]['count'] ?? 0;

            $trends[] = new TaxonomyTrend(
                slug: $slug,
                name: $row['name'],
                count: $row['count'],
                previousCount: $previousCount,
                // Share of the window, which is what makes a count comparable
                // between a busy week and a quiet one.
                share: $totalProjects > 0 ? $row['count'] / $totalProjects : 0.0,
                direction: $this->facetDirection($row['count'], $previousCount),
                kind: $row['kind'],
            );
        }

        usort($trends, fn (TaxonomyTrend $a, TaxonomyTrend $b) => $b->count <=> $a->count);

        return array_slice($trends, 0, $this->topLimit);
    }

    /**
     * Facet movement, deliberately coarser than a headline trend.
     *
     * Category counts are small by nature -- three projects is a normal week
     * for a category -- so a percentage would be meaningless. A plain
     * more/fewer/same is the most these numbers can honestly support.
     */
    private function facetDirection(int $current, int $previous): TrendDirection
    {
        if ($previous === 0 && $current === 0) {
            return TrendDirection::Steady;
        }

        if ($previous === 0) {
            // New this period. Real information, but not a measured rate.
            return TrendDirection::Rising;
        }

        return match (true) {
            $current > $previous => TrendDirection::Rising,
            $current < $previous => TrendDirection::Falling,
            default => TrendDirection::Steady,
        };
    }
}
