<?php

declare(strict_types=1);

namespace App\Jobs;

use DevRadar\Application\Collection\CollectionRunner;
use DevRadar\Domain\Pipeline\PipelineStage;

/**
 * Runs one collection cycle. The only job on the ingestion queue, which has a single worker: two concurrent runs mean duplicate paid fetches.
 */
final class CollectTweetsJob extends PipelineJob
{
    public function stage(): PipelineStage
    {
        return PipelineStage::Collect;
    }

    protected function batchSize(): int
    {
        // Collection is bounded by the query set and the per-query page caps,
        // not by a row count, so there is no batch size to pass.
        return 0;
    }

    /**
     * Collection requires X credentials.
     *
     * XApiConfig throws on an empty bearer token BY DESIGN, and that is not
     * weakened here. This checks the same value before anything tries to build
     * a client, so an unconfigured deployment skips the stage and says why --
     * instead of the documentation's claim that "collection does nothing",
     * which was never true: it threw.
     */
    protected function unmetPrecondition(): ?string
    {
        $token = config('x.bearer_token');

        if (is_string($token) && trim($token) !== '') {
            return null;
        }

        return 'X_API_BEARER_TOKEN is not configured; there is nothing to collect from.';
    }

    protected function runStage(): array
    {
        $summary = app(CollectionRunner::class)->run();

        // The runner returns a summary object; the executor records counters.
        return [
            'claimed' => count($summary->reports),
            'posts_stored' => $summary->totalPostsStored(),
            'requests' => $summary->totalRequests(),
            'billable_resources' => $summary->totalBillableResources(),
            'failures' => count($summary->failures()),
        ];
    }
}
