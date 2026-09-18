<?php

declare(strict_types=1);

namespace App\Jobs;

use DevRadar\Application\Scoring\ScoringRunner;
use DevRadar\Domain\Pipeline\PipelineStage;

/**
 * Recomputes rankings. The only stage with no budget guard, because it is arithmetic over stored data.
 */
final class ScoreProjectsJob extends PipelineJob
{
    public function stage(): PipelineStage
    {
        return PipelineStage::Score;
    }

    protected function batchSize(): int
    {
        return (int) config('scoring.rescore.batch_size');
    }

    protected function runStage(): array
    {
        return app(ScoringRunner::class)->run($this->batchSize());
    }
}
