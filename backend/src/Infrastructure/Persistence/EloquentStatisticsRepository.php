<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DateTimeImmutable;
use DevRadar\Domain\Port\StatisticsRepositoryInterface;
use DevRadar\Domain\Statistics\RankedProject;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every aggregation the statistics layer needs.
 *
 * GROUPING HAPPENS IN POSTGRES. Each of these returns a handful of rows --
 * one per day, one per category -- rather than the projects behind them. The
 * alternative is fetching a week of projects to count them in PHP, which
 * costs the same as rendering the feed and produces one number.
 *
 * `date_trunc` on the discovery timestamp rather than PHP date maths, so the
 * bucketing is done once, in the same place as the filtering, using the same
 * index.
 */
final readonly class EloquentStatisticsRepository implements StatisticsRepositoryInterface
{
    /** @return array<string, float> */
    public function projectsPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->visible($from, $to)
            ->selectRaw("to_char(date_trunc('day', projects.discovered_at), 'YYYY-MM-DD') as day, count(*) as value")
            ->groupBy('day')
            ->get();

        return $this->toMap($rows);
    }

    /** @return array<string, float> */
    public function engagementPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->visible($from, $to)
            ->selectRaw("
                to_char(date_trunc('day', projects.discovered_at), 'YYYY-MM-DD') as day,
                coalesce(sum(projects.engagement_count), 0) as value
            ")
            ->groupBy('day')
            ->get();

        return $this->toMap($rows);
    }

    /** @return array{projects: int, engagement: float, average_score: float, with_repository: int} */
    public function totals(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $row = $this->visible($from, $to)
            ->selectRaw('
                count(*) as projects,
                coalesce(sum(projects.engagement_count), 0) as engagement,
                coalesce(avg(projects.score), 0) as average_score,
                count(*) filter (where projects.repository_url is not null) as with_repository
            ')
            ->first();

        return [
            'projects' => (int) ($row->projects ?? 0),
            'engagement' => (float) ($row->engagement ?? 0),
            'average_score' => round((float) ($row->average_score ?? 0), 2),
            'with_repository' => (int) ($row->with_repository ?? 0),
        ];
    }

    /**
     * @param  'category'|'technology' $dimension
     * @return array<string, array{name: string, count: int, kind: ?string}>
     */
    public function taxonomyCounts(string $dimension, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($dimension === 'category') {
            $rows = $this->visible($from, $to)
                ->selectRaw('projects.category as slug, count(*) as total')
                ->groupBy('projects.category')
                ->get();

            $counts = [];

            foreach ($rows as $row) {
                $counts[(string) $row->slug] = [
                    'name' => ucwords(str_replace('-', ' ', (string) $row->slug)),
                    'count' => (int) $row->total,
                    'kind' => null,
                ];
            }

            return $counts;
        }

        $rows = $this->visible($from, $to)
            ->join('project_technology', 'project_technology.project_id', '=', 'projects.id')
            ->join('technologies', 'technologies.id', '=', 'project_technology.technology_id')
            ->selectRaw('technologies.slug, technologies.name, technologies.kind,
                count(distinct projects.id) as total')
            ->groupBy('technologies.slug', 'technologies.name', 'technologies.kind')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->slug] = [
                'name' => (string) $row->name,
                'count' => (int) $row->total,
                'kind' => $row->kind === null ? null : (string) $row->kind,
            ];
        }

        return $counts;
    }

    /** @return list<RankedProject> */
    public function topProjects(DateTimeImmutable $from, DateTimeImmutable $to, int $limit): array
    {
        $rows = $this->visible($from, $to)
            ->leftJoin('repositories', 'projects.repository_id', '=', 'repositories.id')
            ->select('projects.slug', 'projects.name', 'projects.category', 'projects.score',
                'projects.engagement_count', 'projects.discovered_at', 'repositories.stars')
            ->orderByDesc('projects.score')
            ->orderByDesc('projects.id')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => new RankedProject(
            slug: (string) $r->slug,
            name: (string) $r->name,
            category: (string) $r->category,
            score: (float) $r->score,
            engagement: (int) $r->engagement_count,
            stars: $r->stars === null ? null : (int) $r->stars,
            discoveredAt: (string) $r->discovered_at,
        ))->all();
    }

    /**
     * Total stars across tracked repositories, by day.
     *
     * Read from project_metrics, which is append-only precisely so that this
     * question is answerable. Returns an empty map until there are snapshots
     * on at least two distinct days -- a single point is not growth, and
     * drawing it would suggest a trend from one observation.
     */
    public function repositoryStarsPerDay(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = DB::table('project_metrics')
            ->join('projects', 'projects.id', '=', 'project_metrics.project_id')
            ->whereNotNull('project_metrics.repository_stars')
            ->whereBetween('project_metrics.captured_at', [$from, $to])
            ->whereNull('projects.aged_out_at')
            ->selectRaw("
                to_char(date_trunc('day', project_metrics.captured_at), 'YYYY-MM-DD') as day,
                coalesce(sum(project_metrics.repository_stars), 0) as value
            ")
            ->groupBy('day')
            ->get();

        // One day of data is a measurement, not a series.
        return $rows->count() < 2 ? [] : $this->toMap($rows);
    }

    /**
     * The visible-project predicate, shared by every aggregation.
     *
     * Defined once for the same reason the read repository defines it once:
     * six aggregations each repeating the clauses is five chances to forget
     * one and quietly count a hidden or purged project.
     */
    private function visible(DateTimeImmutable $from, DateTimeImmutable $to): Builder
    {
        return DB::table('projects')
            ->join('tweets', 'projects.primary_tweet_id', '=', 'tweets.id')
            ->where('projects.is_visible', true)
            ->whereNull('tweets.purged_at')
            ->whereBetween('projects.discovered_at', [$from, $to]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object> $rows
     * @return array<string, float>
     */
    private function toMap($rows): array
    {
        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->day] = (float) $row->value;
        }

        return $map;
    }
}
