<?php

declare(strict_types=1);

use App\Jobs\ClassifyTweetsJob;
use App\Jobs\CollectTweetsJob;
use App\Jobs\ComplyJob;
use App\Jobs\DeduplicateTweetsJob;
use App\Jobs\EnrichRepositoriesJob;
use App\Jobs\ExtractProjectsJob;
use App\Jobs\FilterTweetsJob;
use App\Jobs\NormalizeTweetsJob;
use App\Jobs\ScoreProjectsJob;
use DevRadar\Domain\Port\StageRunRecorderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Pipeline schedule
|--------------------------------------------------------------------------
|
| Each stage is scheduled INDEPENDENTLY and drains its own input state. No
| stage waits on another, which is what lets the pipeline keep running when
| one of them breaks.
|
| Three guards apply to every stage, and each prevents a different failure:
|
|   withoutOverlapping - a second tick cannot start while the first is still
|                        running. Belt to ShouldBeUnique's braces: the job
|                        lock stops duplicate jobs, this stops duplicate
|                        dispatches before a job is even queued.
|
|   onOneServer        - with several schedulers running, exactly one fires.
|                        Without it, three app servers means three collection
|                        runs and three times the bill.
|
|   NOT runInBackground - Laravel forbids it on Schedule::job(), which builds a
|                        CallbackEvent. It was also never needed: Schedule::job
|                        only DISPATCHES to the queue and returns in
|                        milliseconds; the actual work already happens in a
|                        worker process, so there is nothing to push into the
|                        background.
|
*/

$schedule = fn (string $stage) => (string) config("pipeline.schedule.{$stage}");

Schedule::job(new CollectTweetsJob())
    ->cron($schedule('collect'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('pipeline:collect');

Schedule::job(new NormalizeTweetsJob())
    ->cron($schedule('normalize'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('pipeline:normalize');

Schedule::job(new DeduplicateTweetsJob())
    ->cron($schedule('deduplicate'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('pipeline:deduplicate');

Schedule::job(new FilterTweetsJob())
    ->cron($schedule('filter'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('pipeline:filter');

Schedule::job(new ClassifyTweetsJob())
    ->cron($schedule('classify'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('pipeline:classify');

Schedule::job(new ExtractProjectsJob())
    ->cron($schedule('extract'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('pipeline:extract');

Schedule::job(new EnrichRepositoriesJob())
    ->cron($schedule('enrich'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('pipeline:enrich');

Schedule::job(new ScoreProjectsJob())
    ->cron($schedule('score'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('pipeline:score');

/*
| Compliance.
|
| A LEGAL OBLIGATION, not a discovery step. X's developer policy requires
| stored content to reflect the current state of content on X, and each run
| advances the asynchronous batch cycle by one step. Scheduled frequently so
| a full four-step cycle completes well inside the 24-hour window even when a
| step has to be retried.
|
| onOneServer matters more here than anywhere else: X permits one concurrent
| compliance job, and two schedulers would have one of them rejected.
*/
Schedule::job(new ComplyJob())
    ->cron((string) config('compliance.cron'))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('pipeline:comply');

/*
| Housekeeping.
|
| Reaping matters more than it looks: a worker killed mid-stage leaves a row
| marked 'running' forever, and the health query "when did this stage last
| succeed" then reads as though the stage is still going. A stage that has
| silently stopped would look busy.
*/
Schedule::call(function () {
    $reaped = app(StageRunRecorderInterface::class)
        ->reapStale((int) config('pipeline.reap_stale_after_minutes'));

    if ($reaped > 0) {
        Log::warning('pipeline.stale_runs_reaped', ['count' => $reaped]);
    }
})->name('pipeline:reap-stale')->everyFifteenMinutes()->onOneServer();

Schedule::command('model:prune', ['--hours' => 24 * (int) config('pipeline.prune_stage_runs_after_days')])
    ->name('pipeline:prune')
    ->daily()
    ->onOneServer();
