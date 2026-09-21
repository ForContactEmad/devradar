<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DevRadar\Domain\Pipeline\PipelineStage;
use DevRadar\Domain\Pipeline\StageOutcome;
use DevRadar\Domain\Port\StageRunRecorderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Records every stage run in stage_runs: when it started, how it ended, and why.
 *
 * This table is the first place to look when a stage misbehaves. The `error`
 * column holds the exception message, which is what identified the compliance
 * provider's wrong constructor arguments after 193 unexplained failures.
 *
 * reapStale() closes runs whose worker died mid-flight, so a crash leaves an
 * `abandoned` row rather than one that claims to be running forever.
 */
final readonly class EloquentStageRunRecorder implements StageRunRecorderInterface
{
    public function begin(PipelineStage $stage): int
    {
        return (int) DB::table('stage_runs')->insertGetId([
            'stage' => $stage->value,
            'started_at' => now(),
            'status' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function finish(int $runId, StageOutcome $outcome): void
    {
        DB::table('stage_runs')->where('id', $runId)->update([
            'finished_at' => now(),
            'status' => $outcome->status,
            'duration_ms' => (int) round($outcome->durationSeconds * 1000),
            'stats' => json_encode($outcome->stats),
            // Bounded: an exception message can carry a whole payload, and
            // this is the highest-volume table in the system.
            'error' => $outcome->error === null ? null : mb_substr($outcome->error, 0, 2000),
            'updated_at' => now(),
        ]);
    }

    /**
     * Close runs abandoned by a worker that died.
     *
     * Without this, a killed worker leaves a row 'running' forever and the
     * health query "when did this stage last succeed" reads as though the
     * stage is still going. 'abandoned' is distinct from 'failed': nobody
     * observed the failure, and that is worth knowing.
     */
    public function reapStale(int $olderThanMinutes): int
    {
        return DB::table('stage_runs')
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes($olderThanMinutes))
            ->update([
                'status' => 'abandoned',
                'finished_at' => now(),
                'error' => 'Worker did not report completion; run abandoned.',
                'updated_at' => now(),
            ]);
    }
}
