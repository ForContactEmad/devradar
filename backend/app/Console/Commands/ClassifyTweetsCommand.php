<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DevRadar\Application\Classification\ClassificationRunner;
use Illuminate\Console\Command;

/**
 * Entry point for AI classification.
 *
 * Unlike collection, this stage IS free to re-run: a re-classification costs
 * a model call, not a repeat purchase of data. That is why a failed call
 * leaves the post in its input state rather than marking it rejected.
 */
final class ClassifyTweetsCommand extends Command
{
    protected $signature = 'devradar:classify {--limit= : Override the configured batch size}';

    protected $description = 'Classify filtered posts as new software launches.';

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

        $runner = app(ClassificationRunner::class);

        $limit = $this->option('limit');
        $stats = $runner->run($limit !== null ? (int) $limit : (int) config('ai.batch_size'));

        $this->table(
            ['Claimed', 'Accepted', 'Rejected', 'Low conf.', 'Unparseable', 'Failed', 'Cost'],
            [[
                $stats['claimed'], $stats['accepted'], $stats['rejected'],
                $stats['low_confidence'], $stats['unparseable'], $stats['failed'],
                '$' . number_format($stats['cost_usd'], 5),
            ]],
        );

        $this->info(sprintf('Acceptance rate: %.1f%%', $stats['acceptance_rate'] * 100));

        if ($stats['budget_halted']) {
            $this->warn('Stopped early: the budget ceiling was reached.');
        }

        if ($stats['unparseable'] > 0) {
            // The earliest signal of model drift or a prompt regression.
            $this->warn(sprintf('%d response(s) could not be parsed. Check the prompt and model version.', $stats['unparseable']));
        }

        return self::SUCCESS;
    }
}
