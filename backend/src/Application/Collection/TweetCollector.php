<?php

declare(strict_types=1);

namespace DevRadar\Application\Collection;

use DevRadar\Domain\Collection\CollectionReport;
use DevRadar\Domain\Collection\SearchPlan;
use DevRadar\Domain\Port\PostProviderInterface;
use DevRadar\Domain\Port\SearchRunLedgerInterface;
use DevRadar\Domain\Port\TweetRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Collects one search plan: fetch, persist, close the ledger.
 *
 * Three collaborators, one job. It does not decide what to search (the
 * strategy does), does not know how to reach a provider (the provider does),
 * and does not know how rows are stored (the repository does). What it owns
 * is the ORDER of those steps and what happens when one of them fails.
 *
 * ORDERING IS DELIBERATE:
 *
 *   1. Open the ledger row FIRST. If the process dies mid-fetch, a row stuck
 *      in 'running' is visible evidence that money may have been spent. No
 *      row at all would be an invisible loss.
 *   2. Fetch.
 *   3. Persist BEFORE closing the ledger. Data already paid for must reach
 *      the database even if the ledger write then fails; the reverse ordering
 *      would let a crash discard a page that has already been charged for.
 *   4. Close the ledger.
 *
 * A persistence failure after a successful fetch is the worst case here: the
 * money is spent and the data is lost. It is logged at error level with the
 * billable count, so the loss is at least measurable.
 */
final readonly class TweetCollector
{
    public function __construct(
        private PostProviderInterface $provider,
        private TweetRepositoryInterface $repository,
        private SearchRunLedgerInterface $ledger,
        private LoggerInterface $logger,
        private float $postReadPriceUsd = 0.005,
    ) {}

    public function collect(SearchPlan $plan): CollectionReport
    {
        $definition = $plan->definition;
        $runId = $this->ledger->begin($definition, $plan->criteria->sinceId);

        $this->logger->info('collection.query.start', [
            'query' => $definition->label(),
            'family' => $definition->family,
            'run_id' => $runId,
            'mode' => $plan->criteria->sinceId !== null ? 'incremental' : 'window',
            'window_start' => $plan->window->start->format(DATE_ATOM),
            'window_end' => $plan->window->end->format(DATE_ATOM),
        ]);

        try {
            $batch = $this->provider->search($plan->criteria);
        } catch (Throwable $e) {
            return $this->recordFailure($runId, $definition->label(), $e);
        }

        // An empty result is a normal outcome, not an error. A query that
        // finds nothing this hour is a query working correctly on a quiet
        // hour -- and it still consumed a request, so it is still recorded.
        $cost = $batch->billableResources * $this->postReadPriceUsd;

        try {
            $stored = $this->repository->store($batch, $runId);
        } catch (Throwable $e) {
            $this->logger->error('collection.persistence_failed', [
                'query' => $definition->label(),
                'run_id' => $runId,
                'billable_resources' => $batch->billableResources,
                'posts_lost' => count($batch->posts),
                'error' => $e->getMessage(),
            ]);

            $this->ledger->fail($runId, 'data', $e->getMessage(), $batch->billableResources, $cost);

            return new CollectionReport(
                queryLabel: $definition->label(),
                searchRunId: $runId,
                status: 'failed',
                postsReturned: count($batch->posts),
                postsStored: 0,
                authorsStored: 0,
                requestCount: $batch->requestCount,
                billableResources: $batch->billableResources,
                newestId: $batch->newestId,
                stopReason: $batch->stopReason,
                errorClass: 'data',
                errorMessage: $e->getMessage(),
            );
        }

        // A run halted by the budget guard completed cleanly but did not
        // finish its work. Recording it as 'completed' would hide the fact
        // that coverage was cut short by spend rather than by exhaustion.
        $status = $batch->stopReason === \DevRadar\Domain\Ingestion\StopReason::BudgetRefused
            ? 'aborted_budget'
            : 'completed';

        $this->ledger->complete(
            runId: $runId,
            postsReturned: count($batch->posts),
            postsNew: $stored->postsStored,
            billableResources: $batch->billableResources,
            costUsd: $cost,
            maxIdSeen: $batch->newestId,
            status: $status,
        );

        $this->logger->info('collection.query.complete', [
            'query' => $definition->label(),
            'run_id' => $runId,
            'status' => $status,
            'posts_returned' => count($batch->posts),
            'posts_stored' => $stored->postsStored,
            'duplicates' => $stored->duplicates,
            'authors_stored' => $stored->authorsStored,
            'requests' => $batch->requestCount,
            'billable_resources' => $batch->billableResources,
            'cost_usd' => round($cost, 5),
            'stop_reason' => $batch->stopReason->value,
            'partial_errors' => count($batch->partialErrors),
        ]);

        return new CollectionReport(
            queryLabel: $definition->label(),
            searchRunId: $runId,
            status: $status,
            postsReturned: count($batch->posts),
            postsStored: $stored->postsStored,
            authorsStored: $stored->authorsStored,
            requestCount: $batch->requestCount,
            billableResources: $batch->billableResources,
            newestId: $batch->newestId,
            stopReason: $batch->stopReason,
        );
    }

    private function recordFailure(int $runId, string $label, Throwable $e): CollectionReport
    {
        $errorClass = property_exists($e, 'errorClass') ? (string) $e->errorClass : 'transient';

        $this->logger->error('collection.query.failed', [
            'query' => $label,
            'run_id' => $runId,
            'error_class' => $errorClass,
            // Provider exceptions redact credentials in their constructor.
            'error' => $e->getMessage(),
        ]);

        $this->ledger->fail($runId, $errorClass, $e->getMessage());

        return new CollectionReport(
            queryLabel: $label,
            searchRunId: $runId,
            status: 'failed',
            postsReturned: 0,
            postsStored: 0,
            authorsStored: 0,
            requestCount: 0,
            billableResources: 0,
            newestId: null,
            stopReason: null,
            errorClass: $errorClass,
            errorMessage: $e->getMessage(),
        );
    }
}
