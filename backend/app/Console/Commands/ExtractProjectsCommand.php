<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DevRadar\Application\Extraction\ExtractionRunner;
use Illuminate\Console\Command;

/**
 * Entry point for project extraction.
 *
 * Runs only on posts already classified as launches, so its volume is a small
 * fraction of what the classifier sees.
 */
final class ExtractProjectsCommand extends Command
{
    protected $signature = 'devradar:extract {--limit= : Override the configured batch size}';

    protected $description = 'Extract structured project data from classified posts.';

    /**
     * Why this command cannot run, or null.
     *
     * Mirrors the job's precondition. The two paths are separate: the queue
     * runs PipelineJob, an operator runs this, and only the job was guarded --
     * so `artisan devradar:classify` still threw from the container while the
     * scheduled stage skipped cleanly. Guarding one path and not the other is
     * worse than guarding neither, because it looks fixed.
     */
    private function unmetPrecondition(): ?string
    {
        $provider = (string) config('ai.provider');

        if (in_array($provider, ['anthropic', 'openai', 'openai_compatible', 'local'], true)) {
            return null;
        }

        return sprintf(
            'No AI provider configured (DEVRADAR_AI_PROVIDER=%s); set it to anthropic or openai_compatible.',
            $provider === '' ? '(empty)' : $provider,
        );
    }

    public function handle(): int
    {
        if ($reason = $this->unmetPrecondition()) {
            $this->warn('Skipped: ' . $reason);

            return self::SUCCESS;
        }

        $runner = app(ExtractionRunner::class);

        $limit = $this->option('limit');
        $stats = $runner->run($limit !== null ? (int) $limit : (int) config('extraction.batch_size'));

        $this->table(
            ['Claimed', 'Published', 'Rejected', 'Failed', 'Corrected'],
            [[$stats['claimed'], $stats['published'], $stats['rejected'], $stats['failed'], $stats['corrected']]],
        );

        foreach ($stats['reject_reasons'] ?? [] as $reason => $count) {
            $this->line(sprintf('  %-28s %d', $reason, $count));
        }

        if ($stats['corrected'] > 0) {
            // A rising correction rate means the prompt is drifting from what
            // the validator accepts, and it shows here before it shows in the
            // feed.
            $this->warn(sprintf(
                '%d extraction(s) needed correction. Check extraction.corrections_applied in the logs.',
                $stats['corrected'],
            ));
        }

        if ($stats['budget_halted']) {
            $this->warn('Stopped early: the budget ceiling was reached.');
        }

        return self::SUCCESS;
    }
}
