<?php

declare(strict_types=1);

namespace DevRadar\Application\Collection;

use DevRadar\Domain\Collection\CollectionSummary;
use DevRadar\Domain\Port\SearchStrategyInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a full collection cycle across every planned query.
 *
 * PARTIAL FAILURE IS THE DESIGN POINT. One query failing must not abandon the
 * others: a single malformed expression, or one query hitting an auth wall,
 * would otherwise cost the entire cycle's coverage. Each query is isolated
 * and its outcome recorded independently.
 *
 * The exception is an auth failure, which is not a per-query problem at all.
 * A rejected credential will reject every subsequent query too, so continuing
 * just burns round trips against a wall. The cycle stops.
 */
final readonly class CollectionRunner
{
    public function __construct(
        private SearchStrategyInterface $strategy,
        private TweetCollector $collector,
        private LoggerInterface $logger,
    ) {}

    public function run(): CollectionSummary
    {
        $plans = $this->strategy->plan();

        $this->logger->info('collection.cycle.start', ['planned_queries' => count($plans)]);

        $reports = [];

        foreach ($plans as $plan) {
            $report = $this->collector->collect($plan);
            $reports[] = $report;

            if ($report->errorClass === 'auth') {
                $this->logger->error('collection.cycle.aborted', [
                    'reason' => 'credential rejected; remaining queries would fail identically',
                    'completed_queries' => count($reports),
                    'skipped_queries' => count($plans) - count($reports),
                ]);

                break;
            }
        }

        $summary = new CollectionSummary($reports);

        $this->logger->info('collection.cycle.complete', [
            'queries_run' => count($reports),
            'posts_stored' => $summary->totalPostsStored(),
            'requests' => $summary->totalRequests(),
            'billable_resources' => $summary->totalBillableResources(),
            'failures' => count($summary->failures()),
        ]);

        return $summary;
    }
}
