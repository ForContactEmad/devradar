<?php

declare(strict_types=1);

namespace DevRadar\Application\Pipeline;

use DevRadar\Application\Observability\LogContext;
use DevRadar\Domain\Pipeline\PipelineStage;
use DevRadar\Domain\Pipeline\StageOutcome;
use DevRadar\Domain\Port\StageRunRecorderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one pipeline stage: record, execute, record, decide what happens next.
 *
 * WHY THIS EXISTS RATHER THAN LIVING IN THE JOB. A queue job is a framework
 * object: it cannot be constructed without a container, and testing it means
 * booting an application. Everything interesting about running a stage --
 * error isolation, timing, recording, the follow-on decision -- is put here
 * instead, where it is a plain class with plain arguments. The jobs become
 * about fifteen lines each and hold no logic at all.
 *
 * NOTHING THROWS OUT OF execute(). A stage that fails must not take the
 * worker down with it, and the queue's own retry is driven by the return
 * value rather than by an exception escaping. The exception is preserved on
 * the outcome so a caller that DOES want the queue to retry can rethrow it
 * deliberately.
 *
 * THE FOLLOW-ON IS A NUDGE, NOT A CHAIN. After a successful run that actually
 * moved something, the next stage is dispatched so work flows through in
 * minutes rather than waiting for the next tick. It is dispatched after the
 * fact and its failure is entirely its own: nothing about the completed stage
 * is undone if the next one fails.
 */
final readonly class StageExecutor
{
    public function __construct(
        private StageRunRecorderInterface $recorder,
        private LoggerInterface $logger,
        /**
         * Dispatches the next stage. Injected so the executor never touches
         * the queue directly and can be tested without one.
         *
         * @var (\Closure(PipelineStage): void)|null
         */
        private ?\Closure $dispatcher = null,
        private bool $followOnEnabled = true,
        /** Injected so timing is deterministic in tests. */
        private ?\Closure $clock = null,
        /**
         * Stamps a correlation id on every line this stage emits.
         *
         * Optional so the executor stays constructible without it, but when
         * present it is what makes the stage's own logs joinable to the
         * runner's, the provider's and the repository's.
         */
        private ?LogContext $logContext = null,
    ) {}

    /**
     * @param \Closure(): array<string, mixed> $runner
     */
    /**
     * Record that a stage declined to run, and why.
     *
     * A stage whose precondition is unmet has NOT failed and must not be
     * retried: retrying an unconfigured provider just burns attempts until the
     * queue abandons the job, which is how a missing X token turned into a
     * repeating MaxAttemptsExceededException while the rest of the pipeline
     * was healthy.
     *
     * It is recorded rather than silently returned, because `skipped` is a
     * first-class status in stage_runs and an operator needs to see the
     * difference between "did not run" and "ran and found nothing". Silence
     * would make an unconfigured compliance sweep look identical to a clean
     * one -- and for a legal obligation that is the wrong default.
     */
    public function skip(PipelineStage $stage, string $reason): StageOutcome
    {
        $outcome = StageOutcome::skipped($stage, $reason);

        $runId = $this->recorder->begin($stage);
        $this->recorder->finish($runId, $outcome);

        $this->logger->warning('pipeline.stage.skipped', [
            'stage' => $stage->value,
            'run_id' => $runId,
            'reason' => $reason,
        ]);

        return $outcome;
    }

    public function execute(PipelineStage $stage, \Closure $runner): StageOutcome
    {
        $startedAt = $this->now();
        $runId = null;

        /*
         * One correlation id for everything this stage does.
         *
         * Without it the stage's fifty-odd possible log events are
         * individually searchable and collectively unjoinable: there is no
         * way to ask what else happened during the run that failed. The id is
         * generated here rather than passed in because a stage is the natural
         * unit -- a follow-on dispatch starts its own.
         */
        $correlationId = LogContext::newId($stage->value);
        $this->logContext?->begin($correlationId, $stage->value);

        try {
            $runId = $this->recorder->begin($stage);
        } catch (Throwable $e) {
            // Losing observability must not lose the work. The stage runs
            // anyway and the recording gap is logged.
            $this->logger->warning('pipeline.recorder_unavailable', [
                'stage' => $stage->value,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logContext?->set('stage_run_id', $runId);

        // Answers "when did the operation start" with the id that every
        // subsequent line will carry.
        $this->logger->info('pipeline.stage.start', [
            'stage' => $stage->value,
            'run_id' => $runId,
            'correlation_id' => $correlationId,
        ]);

        try {
            $stats = $runner();
        } catch (Throwable $e) {
            $duration = $this->now() - $startedAt;
            $outcome = StageOutcome::failed($stage, $e, $duration);

            $this->record($runId, $outcome);

            $this->logger->error('pipeline.stage.failed', [
                'stage' => $stage->value,
                'run_id' => $runId,
                'correlation_id' => $correlationId,
                'duration_seconds' => round($duration, 3),
                // Provider exceptions redact credentials on construction.
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return $outcome;
        }

        $duration = $this->now() - $startedAt;
        $outcome = StageOutcome::succeeded($stage, $stats, $duration);

        $this->record($runId, $outcome);

        // The counts the operator actually asks for -- collected, rejected,
        // reached the model, published, failed -- arrive here as whatever the
        // stage's runner returned, under one correlation id.
        $this->logger->info('pipeline.stage.complete', [
            'stage' => $stage->value,
            'run_id' => $runId,
            'correlation_id' => $correlationId,
            'duration_seconds' => round($duration, 3),
            ...$stats,
        ]);

        $this->dispatchFollowOn($outcome);

        // Cleared so a long-lived worker does not attribute the next job's
        // lines to this run.
        $this->logContext?->clear();

        return $outcome;
    }

    private function dispatchFollowOn(StageOutcome $outcome): void
    {
        if (! $this->followOnEnabled || $this->dispatcher === null) {
            return;
        }

        $next = $outcome->stage->next();

        if ($next === null) {
            return;
        }

        // A run that moved nothing has given the next stage no new work, and
        // dispatching anyway would fill the queue with no-ops.
        if (! $outcome->didWork()) {
            return;
        }

        try {
            ($this->dispatcher)($next);
        } catch (Throwable $e) {
            // A failed nudge is not a failed stage. The next scheduled tick
            // picks the work up regardless, which is exactly why the
            // follow-on is an optimisation and not the mechanism.
            $this->logger->warning('pipeline.follow_on_dispatch_failed', [
                'stage' => $outcome->stage->value,
                'next' => $next->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function record(?int $runId, StageOutcome $outcome): void
    {
        if ($runId === null) {
            return;
        }

        try {
            $this->recorder->finish($runId, $outcome);
        } catch (Throwable $e) {
            $this->logger->warning('pipeline.recorder_finish_failed', [
                'stage' => $outcome->stage->value,
                'run_id' => $runId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
