<?php

declare(strict_types=1);

use DevRadar\Domain\Pipeline\PipelineStage;
use DevRadar\Domain\Pipeline\StageOutcome;
use Tests\Fake\RecordingLogger;
use Tests\Fake\RecordingStageRunRecorder;

/**
 * A stage whose precondition is unmet must decline, not fail.
 *
 * THE BUG THIS CLOSES. XApiConfig throws on an empty bearer token, by design.
 * ComplyJob resolved a compliance client through that config, so an operator
 * running DevRadar without X credentials got a repeating
 * MaxAttemptsExceededException from a stage that had nothing to do -- while
 * every other stage was healthy.
 *
 * Retrying an unconfigured provider cannot succeed. Eight attempts and an
 * abandoned job is the queue doing exactly what it was told, to no purpose.
 */

function skipExecutor(): array
{
    $recorder = new RecordingStageRunRecorder();
    $logger = new RecordingLogger();

    return [new DevRadar\Application\Pipeline\StageExecutor($recorder, $logger, null, false), $recorder, $logger];
}

it('records a skipped run rather than a failed one', function () {
    [$executor, $recorder] = skipExecutor();

    $outcome = $executor->skip(PipelineStage::Comply, 'no credentials');

    // `skipped` is a first-class status in stage_runs precisely so an operator
    // can tell "did not run" from "ran and found nothing".
    expect($outcome->status)->toBe(StageOutcome::SKIPPED)
        ->and($outcome->isSuccess())->toBeFalse()
        ->and($recorder->calls)->toHaveCount(2);
});

it('carries the reason, so the log says why', function () {
    [$executor, , $logger] = skipExecutor();

    $executor->skip(PipelineStage::Comply, 'X_API_BEARER_TOKEN is not configured');

    $skips = $logger->withMessage('pipeline.stage.skipped');

    // Silence would make an unconfigured compliance sweep look identical to a
    // clean one. For a legal obligation that is the wrong default.
    expect($skips)->toHaveCount(1)
        ->and($skips[0]['context']['reason'])->toContain('X_API_BEARER_TOKEN');
});

it('produces no exception, so the queue does not retry', function () {
    [$executor] = skipExecutor();

    expect($executor->skip(PipelineStage::Comply, 'unconfigured')->exception)->toBeNull();
});

it('still opens and closes a run, so the skip is visible in the ledger', function () {
    [$executor, $recorder] = skipExecutor();

    $executor->skip(PipelineStage::Comply, 'unconfigured');

    // begin() then finish(), so the skip has a row an operator can query.
    expect($recorder->calls)->toHaveCount(2)
        ->and($recorder->calls[0]['op'])->toBe('begin')
        ->and($recorder->calls[1]['op'])->toBe('finish')
        ->and($recorder->calls[1]['status'])->toBe(StageOutcome::SKIPPED);
});
