<?php

declare(strict_types=1);

namespace App\Jobs;

use DevRadar\Application\Filtering\FilterRunner;
use DevRadar\Domain\Pipeline\PipelineStage;

/**
 * Applies the free pre-filter. Its pass rate is the direct multiplier on AI spend.
 */
final class FilterTweetsJob extends PipelineJob
{
    public function stage(): PipelineStage
    {
        return PipelineStage::Filter;
    }

    protected function batchSize(): int
    {
        return (int) config('filtering.batch_size');
    }

    protected function runStage(): array
    {
        return app(FilterRunner::class)->run($this->batchSize());
    }
}
