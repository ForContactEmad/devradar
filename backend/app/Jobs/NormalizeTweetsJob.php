<?php

declare(strict_types=1);

namespace App\Jobs;

use DevRadar\Application\Processing\NormalizationRunner;
use DevRadar\Domain\Pipeline\PipelineStage;

/**
 * Normalizes newly collected posts. Free to re-run: only ingestion costs money to repeat.
 */
final class NormalizeTweetsJob extends PipelineJob
{
    public function stage(): PipelineStage
    {
        return PipelineStage::Normalize;
    }

    protected function batchSize(): int
    {
        return (int) config('processing.batch_size.normalize');
    }

    protected function runStage(): array
    {
        return app(NormalizationRunner::class)->run($this->batchSize());
    }
}
