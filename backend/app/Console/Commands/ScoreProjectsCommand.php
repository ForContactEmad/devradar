<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DevRadar\Application\Scoring\ScoringRunner;
use Illuminate\Console\Command;

/**
 * Entry point for the rescore sweep.
 *
 * Safe to run often: scoring is arithmetic over stored data and costs no API
 * or model calls. It is the only stage in the pipeline with no budget guard,
 * for exactly that reason.
 */
final class ScoreProjectsCommand extends Command
{
    protected $signature = 'devradar:score {--limit= : Override the configured batch size}';

    protected $description = 'Recompute project rankings for projects whose scores have gone stale.';

    public function handle(ScoringRunner $runner): int
    {
        $limit = $this->option('limit');
        $stats = $runner->run($limit !== null ? (int) $limit : (int) config('scoring.rescore.batch_size'));

        $this->info(sprintf(
            'Scored %d of %d claimed (%d failed, %d aged out). Average score %.2f.',
            $stats['scored'], $stats['claimed'], $stats['failed'], $stats['aged_out'], $stats['average_score'],
        ));

        foreach ($stats['unavailable_components'] ?? [] as $component => $count) {
            // Which signals are missing across the feed. A jump here is how
            // you notice enrichment has quietly stopped running.
            $this->line(sprintf('  %-12s unavailable for %d project(s)', $component, $count));
        }

        return self::SUCCESS;
    }
}
