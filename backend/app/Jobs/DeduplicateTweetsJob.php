<?php

declare(strict_types=1);

namespace App\Jobs;

use DevRadar\Application\Processing\DeduplicationRunner;
use DevRadar\Domain\Pipeline\PipelineStage;

/**
 * Collapses duplicates across the batch and against stored survivors.
 */
final class DeduplicateTweetsJob extends PipelineJob
{
    public function stage(): PipelineStage
    {
        return PipelineStage::Deduplicate;
    }

    protected function batchSize(): int
    {
        return (int) config('processing.batch_size.deduplicate');
    }

    protected function runStage(): array
    {
        return app(DeduplicationRunner::class)->run($this->batchSize());
    }
}
