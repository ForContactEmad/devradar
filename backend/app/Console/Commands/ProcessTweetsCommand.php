<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DevRadar\Application\Processing\DeduplicationRunner;
use DevRadar\Application\Processing\NormalizationRunner;
use Illuminate\Console\Command;

/**
 * Entry point for the processing stages.
 *
 * A thin adapter: parse, invoke, format. Both stages are free to re-run --
 * only ingestion costs money -- so this command is safe to invoke repeatedly
 * while tuning normalization rules.
 */
final class ProcessTweetsCommand extends Command
{
    protected $signature = 'devradar:process
                            {--stage=all : normalize, deduplicate, or all}
                            {--limit= : Override the configured batch size}';

    protected $description = 'Normalize and deduplicate collected posts.';

    public function handle(NormalizationRunner $normalization, DeduplicationRunner $deduplication): int
    {
        $stage = (string) $this->option('stage');
        $limit = $this->option('limit');

        if (in_array($stage, ['all', 'normalize'], true)) {
            $stats = $normalization->run($limit !== null ? (int) $limit : (int) config('processing.batch_size.normalize'));

            $this->info(sprintf(
                'Normalize: %d claimed, %d normalized, %d rejected, %d failed.',
                $stats['claimed'], $stats['normalized'], $stats['rejected'], $stats['failed'],
            ));
        }

        if (in_array($stage, ['all', 'deduplicate'], true)) {
            $stats = $deduplication->run($limit !== null ? (int) $limit : (int) config('processing.batch_size.deduplicate'));

            $this->info(sprintf(
                'Deduplicate: %d claimed, %d unique, %d duplicates.',
                $stats['claimed'], $stats['unique'], $stats['duplicates'],
            ));
        }

        return self::SUCCESS;
    }
}
