<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DevRadar\Application\Collection\CollectionRunner;
use Illuminate\Console\Command;

/**
 * Entry point for a collection cycle.
 *
 * A thin adapter: parse, invoke one use case, format the result. All of the
 * decisions live in the strategy, the collector and the runner.
 *
 * Scheduling this command is a later phase. Run it by hand first, watch what
 * it costs, and only then put it on a timer.
 */
final class CollectTweetsCommand extends Command
{
    protected $signature = 'devradar:collect
                            {--dry-run : Show the planned queries and exit without spending anything}';

    protected $description = 'Run one collection cycle over the rolling window.';

    /**
     * Why this command cannot run, or null.
     *
     * Mirrors CollectTweetsJob. The command resolves its runner through the
     * container, which builds XApiConfig, which throws on an empty token by
     * design -- so the check must happen before resolution, not inside it.
     */
    private function unmetPrecondition(): ?string
    {
        $token = config('x.bearer_token');

        if (is_string($token) && trim($token) !== '') {
            return null;
        }

        return 'X_API_BEARER_TOKEN is not configured; there is nothing to collect from.';
    }

    public function handle(): int
    {
        if ($reason = $this->unmetPrecondition()) {
            $this->warn('Skipped: ' . $reason);

            return self::SUCCESS;
        }

        $runner = app(CollectionRunner::class);
        $strategy = app(\DevRadar\Domain\Port\SearchStrategyInterface::class);

        if ($this->option('dry-run')) {
            $plans = $strategy->plan();

            $this->info(sprintf('%d query/queries planned. Nothing was requested.', count($plans)));

            $this->table(
                ['Query', 'Family', 'Mode', 'Since / Window start', 'Expression'],
                array_map(fn ($p) => [
                    $p->definition->label(),
                    $p->definition->family,
                    $p->criteria->sinceId !== null ? 'incremental' : 'window',
                    $p->criteria->sinceId ?? $p->criteria->startTime?->format('Y-m-d H:i'),
                    mb_substr($p->criteria->query, 0, 60) . (mb_strlen($p->criteria->query) > 60 ? '…' : ''),
                ], $plans),
            );

            return self::SUCCESS;
        }

        $summary = $runner->run();

        $this->table(
            ['Query', 'Status', 'Returned', 'Stored', 'Dupes', 'Reqs', 'Billable'],
            array_map(fn ($r) => [
                $r->queryLabel, $r->status, $r->postsReturned, $r->postsStored,
                $r->duplicateCount(), $r->requestCount, $r->billableResources,
            ], $summary->reports),
        );

        $this->info(sprintf(
            'Stored %d new post(s) from %d request(s); %d billable resource(s).',
            $summary->totalPostsStored(),
            $summary->totalRequests(),
            $summary->totalBillableResources(),
        ));

        foreach ($summary->failures() as $failure) {
            $this->error(sprintf('%s failed (%s): %s', $failure->queryLabel, $failure->errorClass, $failure->errorMessage));
        }

        // A cycle where every query failed is a failure, so a scheduler or CI
        // step can notice. Partial failure is not.
        return $summary->allFailed() ? self::FAILURE : self::SUCCESS;
    }
}
