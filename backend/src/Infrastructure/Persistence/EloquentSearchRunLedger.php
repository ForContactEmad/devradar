<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DevRadar\Domain\Collection\SearchQueryDefinition;
use DevRadar\Domain\Port\SearchRunLedgerInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes the spend ledger.
 *
 * A query definition with no id has never been persisted -- which means the
 * seed has not run. Opening a run against it would violate the foreign key
 * that deliberately RESTRICTs deletion of queries with spend history, so this
 * fails loudly rather than writing an orphan row.
 */
final readonly class EloquentSearchRunLedger implements SearchRunLedgerInterface
{
    public function begin(SearchQueryDefinition $definition, ?string $sinceId): int
    {
        if ($definition->id === null) {
            throw new RuntimeException(sprintf(
                'Query "%s" has no database id. Seed the query set before collecting; '
                . 'spend must always be attributable to a stored, versioned query.',
                $definition->name,
            ));
        }

        return (int) DB::table('search_runs')->insertGetId([
            'search_query_id' => $definition->id,
            'started_at' => now(),
            'status' => 'running',
            'since_id' => $sinceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function complete(
        int $runId,
        int $postsReturned,
        int $postsNew,
        int $billableResources,
        float $costUsd,
        ?string $maxIdSeen,
        string $status = 'completed',
    ): void {
        DB::table('search_runs')->where('id', $runId)->update([
            'finished_at' => now(),
            'status' => $status,
            'posts_returned' => $postsReturned,
            'posts_new' => $postsNew,
            'billable_resources' => $billableResources,
            'cost_usd' => round($costUsd, 5),
            'max_id_seen' => $maxIdSeen,
            'updated_at' => now(),
        ]);
    }

    public function fail(
        int $runId,
        string $errorClass,
        string $errorMessage,
        int $billableResources = 0,
        float $costUsd = 0.0,
    ): void {
        DB::table('search_runs')->where('id', $runId)->update([
            'finished_at' => now(),
            // The schema CHECK allows running | completed | failed |
            // aborted_budget. A budget refusal is a clean stop, not a failure,
            // and is closed through complete() with that status instead.
            'status' => 'failed',
            'error_class' => $errorClass,
            // Provider exceptions redact credentials before they get here.
            'error_message' => mb_substr($errorMessage, 0, 2000),
            'billable_resources' => $billableResources,
            'cost_usd' => round($costUsd, 5),
            'updated_at' => now(),
        ]);
    }
}
