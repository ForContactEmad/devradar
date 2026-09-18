<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DevRadar\Domain\Pipeline\PipelineStage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The operator's view of the pipeline.
 *
 * Answers the question that matters when something has gone quiet: which
 * stage stopped, and how long ago. Reads stage_runs; makes no API calls and
 * costs nothing.
 */
final class PipelineStatusCommand extends Command
{
    protected $signature = 'devradar:status {--hours=24 : Window to summarise}';

    protected $description = 'Show the health of each pipeline stage.';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $since = now()->subHours($hours);
        $rows = [];
        $unhealthy = 0;

        foreach (PipelineStage::cases() as $stage) {
            $runs = DB::table('stage_runs')
                ->where('stage', $stage->value)
                ->where('started_at', '>=', $since)
                ->selectRaw("
                    count(*) as total,
                    count(*) filter (where status = 'succeeded') as succeeded,
                    count(*) filter (where status = 'failed') as failed,
                    count(*) filter (where status = 'abandoned') as abandoned,
                    max(duration_ms) as slowest_ms
                ")
                ->first();

            $lastSuccess = DB::table('stage_runs')
                ->where('stage', $stage->value)
                ->where('status', 'succeeded')
                ->max('finished_at');

            // A stage that has never succeeded in the window is the thing
            // worth noticing, whether it failed loudly or simply stopped
            // being scheduled.
            $healthy = $lastSuccess !== null && (int) ($runs->succeeded ?? 0) > 0;

            if (! $healthy) {
                $unhealthy++;
            }

            $rows[] = [
                $healthy ? 'ok' : 'STALLED',
                $stage->value,
                $stage->isPaid() ? 'paid' : 'free',
                (int) ($runs->total ?? 0),
                (int) ($runs->failed ?? 0),
                (int) ($runs->abandoned ?? 0),
                $runs->slowest_ms === null ? '-' : round($runs->slowest_ms / 1000, 1) . 's',
                $lastSuccess === null ? 'never' : $lastSuccess,
            ];
        }

        $this->table(
            ['', 'Stage', 'Cost', 'Runs', 'Failed', 'Abandoned', 'Slowest', 'Last success'],
            $rows,
        );

        if ($unhealthy > 0) {
            $this->warn(sprintf('%d stage(s) have not succeeded in the last %d hours.', $unhealthy, $hours));

            return self::FAILURE;
        }

        $this->info(sprintf('All stages healthy over the last %d hours.', $hours));

        return self::SUCCESS;
    }
}
