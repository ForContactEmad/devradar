<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DevRadar\Application\Enrichment\EnrichmentRunner;
use Illuminate\Console\Command;

/**
 * Entry point for GitHub enrichment.
 *
 * Runs only for projects whose URL parses as a GitHub repository, and only
 * for projects still inside the rolling window. A project that has aged out
 * of the feed is not worth a request.
 */
final class EnrichRepositoriesCommand extends Command
{
    protected $signature = 'devradar:enrich {--limit= : Override the configured batch size}';

    protected $description = 'Fetch and refresh GitHub repository data for published projects.';

    public function handle(EnrichmentRunner $runner): int
    {
        if ((string) config('github.token', '') === '') {
            // 60/hour, and conditional requests do not save quota without a
            // token. Worth saying out loud before someone wonders why the
            // sweep keeps stopping.
            $this->warn('No GITHUB_TOKEN set: 60 requests/hour, and 304 responses still cost quota.');
        }

        $limit = $this->option('limit');
        $stats = $runner->run($limit !== null ? (int) $limit : (int) config('github.refresh.batch_size'));

        $this->table(
            ['Linked', 'Claimed', 'Found', 'Unchanged', 'Gone', 'Private', 'Failed', 'Billable'],
            [[
                $stats['linked'], $stats['claimed'], $stats['found'], $stats['unchanged'],
                $stats['not_found'], $stats['private'], $stats['failed'], $stats['requests_counted'],
            ]],
        );

        if ($stats['rate_limited'] > 0) {
            $this->warn(sprintf(
                'Rate limit reached; the window reopens in %s seconds.',
                $stats['quota_resets_in_seconds'] ?? 'unknown',
            ));
        } elseif ($stats['stopped_early']) {
            $this->warn('Stopped early to preserve the quota reserve.');
        }

        return self::SUCCESS;
    }
}
