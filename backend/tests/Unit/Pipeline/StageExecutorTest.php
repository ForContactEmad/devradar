<?php

declare(strict_types=1);

use DevRadar\Application\Pipeline\StageExecutor;
use DevRadar\Domain\Pipeline\PipelineStage;
use DevRadar\Domain\Pipeline\StageOutcome;
use Tests\Fake\RecordingLogger;
use Tests\Fake\RecordingStageRunRecorder;

/**
 * A by-reference array does not survive array destructuring, so dispatches
 * are collected in a shared object instead.
 *
 * @return array{0: StageExecutor, 1: RecordingStageRunRecorder, 2: RecordingLogger, 3: ArrayObject}
 */
function executor(bool $followOn = true, ?RecordingStageRunRecorder $recorder = null): array
{
    $recorder ??= new RecordingStageRunRecorder();
    $logger = new RecordingLogger();
    $dispatched = new ArrayObject();

    $exec = new StageExecutor(
        recorder: $recorder,
        logger: $logger,
        dispatcher: function (PipelineStage $stage) use ($dispatched) { $dispatched->append($stage->value); },
        followOnEnabled: $followOn,
        clock: (function () {
            $t = 1000.0;

            return function () use (&$t) { $t += 1.5; return $t; };
        })(),
    );

    return [$exec, $recorder, $logger, $dispatched];
}

// ------------------------------------------------------------- successful job

it('runs a stage and records the outcome', function () {
    [$exec, $recorder] = executor();

    $outcome = $exec->execute(PipelineStage::Normalize, fn () => ['claimed' => 12, 'normalized' => 10]);

    expect($outcome->isSuccess())->toBeTrue()
        ->and($outcome->stats['normalized'])->toBe(10)
        ->and($recorder->ops('begin'))->toHaveCount(1)
        ->and($recorder->ops('finish')[0]['status'])->toBe(StageOutcome::SUCCEEDED);
});

it('opens the record before running, so a killed worker leaves evidence', function () {
    [$exec, $recorder] = executor();

    $exec->execute(PipelineStage::Filter, fn () => ['claimed' => 1]);

    expect($recorder->calls[0]['op'])->toBe('begin')
        ->and($recorder->calls[1]['op'])->toBe('finish');
});

it('measures how long a stage took', function () {
    [$exec] = executor();

    // Clock injected, so this is deterministic rather than timing-dependent.
    expect($exec->execute(PipelineStage::Score, fn () => ['scored' => 5])->durationSeconds)->toBe(1.5);
});

// ----------------------------------------------------------------- failed job

it('records a failure without throwing', function () {
    [$exec, $recorder, $logger] = executor();

    // A stage that fails must not take the worker down with it.
    $outcome = $exec->execute(PipelineStage::Classify, function () {
        throw new RuntimeException('model provider unreachable');
    });

    expect($outcome->isSuccess())->toBeFalse()
        ->and($outcome->error)->toBe('model provider unreachable')
        ->and($recorder->ops('finish')[0]['status'])->toBe(StageOutcome::FAILED)
        ->and($logger->withMessage('pipeline.stage.failed'))->toHaveCount(1);
});

it('preserves the exception so the caller can decide about retrying', function () {
    [$exec] = executor();
    $thrown = new LogicException('bad config');

    $outcome = $exec->execute(PipelineStage::Normalize, function () use ($thrown) { throw $thrown; });

    // The executor never throws; the job rethrows deliberately for free
    // stages so the queue's backoff applies.
    expect($outcome->exception)->toBe($thrown);
});

it('records a failure even when the stage failed instantly', function () {
    [$exec, $recorder] = executor();

    $exec->execute(PipelineStage::Enrich, function () { throw new RuntimeException('x'); });

    expect($recorder->finished[1]->durationSeconds)->toBeGreaterThan(0.0);
});

// --------------------------------------------------------------- partial failure

it('keeps stage failures independent of each other', function () {
    [$exec, , , $dispatched] = executor();

    // Classification failing must not stop scoring: the stages share no
    // state beyond the database rows each one claims.
    $failed = $exec->execute(PipelineStage::Classify, function () { throw new RuntimeException('AI down'); });
    $succeeded = $exec->execute(PipelineStage::Score, fn () => ['scored' => 40]);

    expect($failed->isSuccess())->toBeFalse()
        ->and($succeeded->isSuccess())->toBeTrue();
});

it('does not nudge the next stage when the current one failed', function () {
    [$exec, , , $dispatched] = executor();

    $exec->execute(PipelineStage::Filter, function () { throw new RuntimeException('boom'); });

    expect($dispatched->count())->toBe(0);
});

it('survives an unavailable recorder rather than losing the work', function () {
    $recorder = new RecordingStageRunRecorder();
    $recorder->failOnBegin = true;

    [$exec, , $logger] = executor(recorder: $recorder);

    // Losing observability must not lose the work.
    $outcome = $exec->execute(PipelineStage::Normalize, fn () => ['claimed' => 3]);

    expect($outcome->isSuccess())->toBeTrue()
        ->and($logger->withMessage('pipeline.recorder_unavailable'))->toHaveCount(1);
});

it('survives a recorder that cannot write the result', function () {
    $recorder = new RecordingStageRunRecorder();
    $recorder->failOnFinish = true;

    [$exec, , $logger] = executor(recorder: $recorder);
    $outcome = $exec->execute(PipelineStage::Score, fn () => ['scored' => 1]);

    expect($outcome->isSuccess())->toBeTrue()
        ->and($logger->withMessage('pipeline.recorder_finish_failed'))->toHaveCount(1);
});

// ------------------------------------------------------------- follow-on nudge

it('nudges the next stage after real work', function () {
    [$exec, , , $dispatched] = executor();

    $exec->execute(PipelineStage::Normalize, fn () => ['claimed' => 20, 'normalized' => 18]);

    expect($dispatched->getArrayCopy())->toBe(['deduplicate']);
});

it('does not nudge when the stage moved nothing', function () {
    [$exec, , , $dispatched] = executor();

    // Dispatching anyway would fill the queue with no-ops.
    $exec->execute(PipelineStage::Normalize, fn () => ['claimed' => 0, 'normalized' => 0]);

    expect($dispatched->count())->toBe(0);
});

it('does not nudge past the end of the pipeline', function () {
    [$exec, , , $dispatched] = executor();

    $exec->execute(PipelineStage::Score, fn () => ['scored' => 10]);

    expect($dispatched->count())->toBe(0);
});

it('treats a failed nudge as an optimisation, not a stage failure', function () {
    $recorder = new RecordingStageRunRecorder();
    $logger = new RecordingLogger();

    $exec = new StageExecutor(
        recorder: $recorder,
        logger: $logger,
        dispatcher: function () { throw new RuntimeException('queue unreachable'); },
    );

    $outcome = $exec->execute(PipelineStage::Filter, fn () => ['claimed' => 5]);

    // The next scheduled tick picks the work up regardless, which is exactly
    // why the follow-on is an optimisation and not the mechanism.
    expect($outcome->isSuccess())->toBeTrue()
        ->and($logger->withMessage('pipeline.follow_on_dispatch_failed'))->toHaveCount(1);
});

it('can fall back to pure scheduling', function () {
    [$exec, , , $dispatched] = executor(followOn: false);

    $exec->execute(PipelineStage::Normalize, fn () => ['claimed' => 9]);

    expect($dispatched->count())->toBe(0);
});

// -------------------------------------------------------------- idempotency

it('produces the same outcome when a stage is run twice', function () {
    [$exec, $recorder] = executor();

    $first = $exec->execute(PipelineStage::Deduplicate, fn () => ['claimed' => 5, 'unique' => 5]);
    $second = $exec->execute(PipelineStage::Deduplicate, fn () => ['claimed' => 0, 'unique' => 0]);

    // Re-running is safe: the second pass finds nothing left in the input
    // state, because the first transitioned it.
    expect($first->didWork())->toBeTrue()
        ->and($second->didWork())->toBeFalse()
        ->and($recorder->ops('begin'))->toHaveCount(2);
});

// ------------------------------------------------------- stage configuration

it('gives paid stages a single attempt', function () {
    // A retry buys the same data twice.
    foreach ([PipelineStage::Collect, PipelineStage::Classify, PipelineStage::Extract, PipelineStage::Enrich] as $stage) {
        expect($stage->isPaid())->toBeTrue()
            ->and($stage->maxAttempts())->toBe(1);
    }
});

it('lets free stages retry', function () {
    foreach ([PipelineStage::Normalize, PipelineStage::Deduplicate, PipelineStage::Filter, PipelineStage::Score] as $stage) {
        expect($stage->isPaid())->toBeFalse()
            ->and($stage->maxAttempts())->toBe(3);
    }
});

it('isolates collection on its own queue', function () {
    // Two concurrent ingestion runs mean duplicate paid fetches.
    expect(PipelineStage::Collect->queue())->toBe('ingestion');

    foreach ([PipelineStage::Normalize, PipelineStage::Classify, PipelineStage::Score] as $stage) {
        expect($stage->queue())->toBe('processing');
    }
});

it('gives slow stages longer timeouts', function () {
    expect(PipelineStage::Classify->timeoutSeconds())->toBeGreaterThan(PipelineStage::Normalize->timeoutSeconds())
        ->and(PipelineStage::Collect->timeoutSeconds())->toBeGreaterThan(PipelineStage::Filter->timeoutSeconds());
});

it('describes the pipeline order without chaining it', function () {
    expect(PipelineStage::Collect->next())->toBe(PipelineStage::Normalize)
        ->and(PipelineStage::Filter->next())->toBe(PipelineStage::Classify)
        ->and(PipelineStage::Extract->next())->toBe(PipelineStage::Enrich)
        ->and(PipelineStage::Score->next())->toBeNull();
});

// --------------------------------------------------- compliance as a stage

it('treats compliance as a pipeline stage with the same guarantees', function () {
    [$exec, $recorder] = executor();

    $outcome = $exec->execute(PipelineStage::Comply, fn () => ['phase' => 'applied', 'claimed' => 800, 'removed' => 3]);

    // A compliance sweep that silently stopped is the failure nobody
    // notices, so it is recorded and health-reported like everything else.
    expect($outcome->isSuccess())->toBeTrue()
        ->and($recorder->ops('finish')[0]['status'])->toBe(StageOutcome::SUCCEEDED)
        ->and($outcome->stats['removed'])->toBe(3);
});

it('does not chain anything after compliance', function () {
    [$exec, , , $dispatched] = executor();

    $exec->execute(PipelineStage::Comply, fn () => ['claimed' => 500]);

    // It is an obligation running on its own schedule, not a discovery step.
    expect($dispatched->count())->toBe(0);
});

it('never retries compliance automatically, but not because it costs money', function () {
    // A modelling flaw this test exposed: "costs money" and "do not retry"
    // are different properties that happened to coincide until compliance
    // arrived. It is not billed per resource, yet a retry would race the one
    // concurrent job it just started.
    expect(PipelineStage::Comply->isPaid())->toBeFalse()
        ->and(PipelineStage::Comply->maxAttempts())->toBe(1);
});

it('still retries the genuinely free stages', function () {
    expect(PipelineStage::Normalize->maxAttempts())->toBe(3)
        ->and(PipelineStage::Score->maxAttempts())->toBe(3);
});
