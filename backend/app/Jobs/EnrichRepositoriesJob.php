<?php

declare(strict_types=1);

namespace App\Jobs;

use DevRadar\Application\Enrichment\EnrichmentRunner;
use DevRadar\Domain\Pipeline\PipelineStage;

/**
 * Fetches GitHub data. Off the critical path: its failure blocks nothing.
 */
final class EnrichRepositoriesJob extends PipelineJob
{
    public function stage(): PipelineStage
    {
        return PipelineStage::Enrich;
    }

    protected function batchSize(): int
    {
        return (int) config('github.refresh.batch_size');
    }

    protected function runStage(): array
    {
        return app(EnrichmentRunner::class)->run($this->batchSize());
    }
}
