<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DevRadar\Domain\Port\SearchRunQueryRepositoryInterface;
use DevRadar\Domain\Query\Paginated;
use Illuminate\Support\Facades\DB;

final readonly class EloquentSearchRunQueryRepository implements SearchRunQueryRepositoryInterface
{
    /** @return Paginated<array<string, mixed>> */
    public function runs(int $page, int $perPage, ?string $status = null): Paginated
    {
        $base = DB::table('search_runs')->join('search_queries', 'search_runs.search_query_id', '=', 'search_queries.id');

        if ($status !== null) {
            $base->where('search_runs.status', $status);
        }

        $total = (clone $base)->count('search_runs.id');

        $rows = $base
            ->select('search_runs.id', 'search_runs.status', 'search_runs.started_at', 'search_runs.finished_at',
                'search_runs.posts_returned', 'search_runs.posts_new', 'search_runs.billable_resources',
                'search_runs.cost_usd', 'search_runs.error_class', 'search_runs.error_message',
                'search_queries.name as query_name', 'search_queries.family', 'search_queries.version')
            ->orderByDesc('search_runs.started_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $items = $rows->map(fn ($r) => [
            'id' => (int) $r->id,
            'query' => ['name' => (string) $r->query_name, 'family' => (string) $r->family, 'version' => (int) $r->version],
            'status' => (string) $r->status,
            'started_at' => (string) $r->started_at,
            'finished_at' => $r->finished_at === null ? null : (string) $r->finished_at,
            'posts_returned' => (int) $r->posts_returned,
            'posts_new' => (int) $r->posts_new,
            // The gap between returned and new is the duplicate rate, which
            // is what decides whether a query is worth keeping.
            'duplicates' => max(0, (int) $r->posts_returned - (int) $r->posts_new),
            'billable_resources' => (int) $r->billable_resources,
            'cost_usd' => (float) $r->cost_usd,
            'error' => $r->error_class === null ? null : [
                'class' => (string) $r->error_class,
                'message' => $r->error_message === null ? null : (string) $r->error_message,
            ],
        ])->all();

        return new Paginated($items, $total, $page, $perPage);
    }

    /** @return array<string, mixed> */
    public function pipelineHealth(int $windowHours): array
    {
        $since = now()->subHours($windowHours);

        $stages = DB::table('stage_runs')
            ->where('started_at', '>=', $since)
            ->groupBy('stage')
            ->selectRaw("
                stage,
                count(*) as runs,
                count(*) filter (where status = 'failed') as failed,
                count(*) filter (where status = 'abandoned') as abandoned,
                max(finished_at) filter (where status = 'succeeded') as last_success,
                max(duration_ms) as slowest_ms
            ")
            ->get()
            ->map(fn ($s) => [
                'stage' => (string) $s->stage,
                'runs' => (int) $s->runs,
                'failed' => (int) $s->failed,
                'abandoned' => (int) $s->abandoned,
                'last_success' => $s->last_success === null ? null : (string) $s->last_success,
                'slowest_ms' => $s->slowest_ms === null ? null : (int) $s->slowest_ms,
                // A stage that has not succeeded in the window is the thing
                // worth noticing, whether it failed loudly or simply stopped
                // being scheduled.
                'healthy' => $s->last_success !== null,
            ])->all();

        $spend = DB::table('search_runs')
            ->where('started_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');

        return [
            'window_hours' => $windowHours,
            'stages' => $stages,
            'cycle_spend_usd' => round((float) $spend, 5),
        ];
    }
}
