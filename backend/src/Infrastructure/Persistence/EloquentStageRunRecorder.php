<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DevRadar\Domain\Pipeline\PipelineStage;
use DevRadar\Domain\Pipeline\StageOutcome;
use DevRadar\Domain\Port\StageRunRecorderInterface;
use Illuminate\Support\Facades\DB;

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
