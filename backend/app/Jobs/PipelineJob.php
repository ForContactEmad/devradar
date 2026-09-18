<?php

declare(strict_types=1);

namespace App\Jobs;

use DevRadar\Application\Pipeline\StageExecutor;
use DevRadar\Domain\Pipeline\PipelineStage;
use DevRadar\Domain\Pipeline\StageOutcome;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Base for every pipeline job.
 *
 * Jobs are wrappers. All of them together hold no business logic: each one
 * names its stage, resolves a runner, and hands both to StageExecutor. That
 * is why the interesting behaviour is testable without booting an
 * application.
 *
 * ShouldBeUnique IS THE ANSWER TO DUPLICATE DISPATCH. The scheduler firing
 * while a previous run is still going, a follow-on nudge arriving alongside a
 * scheduled tick, an operator running the command by hand -- all produce a
 * second job for the same stage. Without a lock, two workers claim the same
 * rows and, for the paid stages, buy the same data twice.
 *
 * The lock is held for the stage's timeout plus a margin, so a worker killed
 * without running failed() cannot leave the stage locked forever.
 */
abstract class PipelineJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue($this->stage()->queue());
    }

    abstract public function stage(): PipelineStage;

    /**
     * How much work to claim. Batch size rather than "everything" so a stage
     * has a bounded runtime and a crash loses a bounded amount of work.
     */
    abstract protected function batchSize(): int;

    /** @return array<string, mixed> */
    abstract protected function runStage(): array;

    /**
     * Why this stage cannot run right now, or null when it can.
     *
     * A PRECONDITION IS NOT A FAILURE. A stage that depends on a provider the
     * operator has not configured should decline once and say so, not throw
     * and be retried until the queue abandons it. The default is null: most
     * stages have no external precondition.
     */
    protected function unmetPrecondition(): ?string
    {
        return null;
    }

    /** One job per stage at a time. */
    public function uniqueId(): string
    {
        return 'pipeline:' . $this->stage()->value;
    }

    public function uniqueFor(): int
    {
        // Outlive the timeout, so a worker killed hard cannot leave the stage
        // permanently locked.
        return $this->stage()->timeoutSeconds() + 120;
    }

    public function tries(): int
    {
        // Paid stages attempt once: a retry buys the same data twice.
        return $this->stage()->maxAttempts();
    }

    public function retryUntil(): \DateTimeInterface
    {
        // A bound on total retry time as well as attempt count. Without it, a
        // stage that keeps failing with long backoff can still be retrying
        // hours later, long after the window it was working on has moved on.
        return now()->addMinutes(30);
    }

    /** Exponential backoff between attempts, in seconds. */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function timeout(): int
    {
        return $this->stage()->timeoutSeconds();
    }

    public function handle(StageExecutor $executor): void
    {
        /*
         * The queue's own job id, so a log line can be traced back to the
         * failed_jobs row that holds its payload. `job()` is null when a job
         * runs synchronously, which is why this is guarded.
         */
        app(\DevRadar\Application\Observability\LogContext::class)
            ->set('job_id', $this->job?->getJobId());

        // Checked before the runner is resolved, because resolving it is what
        // throws when a provider is unconfigured.
        $reason = $this->unmetPrecondition();

        if ($reason !== null) {
            $executor->skip($this->stage(), $reason);

            return;
        }

        $outcome = $executor->execute($this->stage(), fn () => $this->runStage());

        // The executor never throws; the job decides whether the queue should
        // retry. Free stages rethrow so the queue's backoff applies. Paid
        // stages do not: the failure is recorded and a human looks at it,
        // because an automatic retry is another purchase.
        if (! $outcome->isSuccess() && ! $this->stage()->isPaid() && $outcome->exception !== null) {
            throw $outcome->exception;
        }
    }

    /**
     * Called by the queue when every attempt has failed.
     *
     * Deliberately does nothing but log: the stage recorder has already
     * written the failure, and the failed_jobs row preserves the payload for
     * replay. Attempting recovery here would run outside the stage's own
     * error handling.
     */
    public function failed(?\Throwable $e): void
    {
        Log::error('pipeline.job.abandoned', [
            'stage' => $this->stage()->value,
            'queue' => $this->stage()->queue(),
            'attempts' => $this->attempts(),
            'error' => $e?->getMessage(),
        ]);
    }

    /** @return list<string> shown in queue monitoring */
    public function tags(): array
    {
        return ['pipeline', 'stage:' . $this->stage()->value, $this->stage()->isPaid() ? 'paid' : 'free'];
    }

    protected function outcomeStats(StageOutcome $outcome): array
    {
        return $outcome->stats;
    }
}
