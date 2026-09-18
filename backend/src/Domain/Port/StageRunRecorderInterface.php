<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Pipeline\PipelineStage;
use DevRadar\Domain\Pipeline\StageOutcome;

/**
 * Records what each pipeline stage did.
 *
 * The row opens BEFORE the stage runs, so a worker killed mid-stage leaves a
 * row stuck in 'running'. That is visible evidence; no row at all is an
 * invisible gap, and invisible gaps in a pipeline that spends money are the
 * dangerous kind.
 *
 * Complements search_runs rather than replacing it: that table is the spend
 * ledger for ingestion specifically, this one is the operational record for
 * every stage.
 */
interface StageRunRecorderInterface
{
    /** Open a run and return its id. */
    public function begin(PipelineStage $stage): int;

    public function finish(int $runId, StageOutcome $outcome): void;

    /** Close runs left 'running' by a worker that died. */
    public function reapStale(int $olderThanMinutes): int;
}
