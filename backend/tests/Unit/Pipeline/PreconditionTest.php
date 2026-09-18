<?php

declare(strict_types=1);

/**
 * The precondition rules, tested as pure logic.
 *
 * WHY NOT THROUGH THE JOBS. Each job reads config() and so needs a booted
 * Laravel, which is the same framework boundary that leaves app/ at 13%
 * coverage. The DECISION each job makes is pure, though, and it is the part
 * that can be wrong. A previous mutation -- making the compliance check always
 * return null -- survived the whole suite precisely because nothing tested it.
 *
 * These mirror the conditions in CollectTweetsJob, ClassifyTweetsJob,
 * ExtractProjectsJob and ComplyJob. If one of those changes without this file
 * changing, the duplication is the point: this fails and asks why.
 */

/** Mirrors CollectTweetsJob and ComplyJob. */
function xConfigured(mixed $token): bool
{
    return is_string($token) && trim($token) !== '';
}

/** Mirrors ClassifyTweetsJob and ExtractProjectsJob. */
function aiConfigured(mixed $provider): bool
{
    return in_array((string) $provider, ['anthropic', 'openai', 'openai_compatible', 'local'], true);
}

it('treats an absent X token as unconfigured', function () {
    expect(xConfigured(null))->toBeFalse()
        ->and(xConfigured(''))->toBeFalse()
        ->and(xConfigured('   '))->toBeFalse();
});

it('treats a real X token as configured', function () {
    expect(xConfigured('AAAAAAAAtoken'))->toBeTrue()
        // Surrounding whitespace is a copy-paste artefact, not a missing token.
        ->and(xConfigured('  AAAAtoken  '))->toBeTrue();
});

it('accepts every provider the container can actually build', function () {
    // These four are the arms of the match in ClassificationServiceProvider.
    // A value accepted here that the container rejects would skip nothing and
    // throw anyway; a value rejected here that the container accepts would
    // skip a stage that could have run.
    foreach (['anthropic', 'openai', 'openai_compatible', 'local'] as $provider) {
        expect(aiConfigured($provider))->toBeTrue();
    }
});

it('rejects mock, which only exists as a test double', function () {
    // THE BUG THIS CLOSES. `mock` was the default in config/ai.php and the
    // value recommended by the README, SETUP.md and .env.example -- and the
    // container has no arm for it, because MockLlmProvider lives in
    // tests/Fake and composer does not autoload that in production.
    expect(aiConfigured('mock'))->toBeFalse();
});

it('treats an unset provider as unconfigured, not as a default', function () {
    // env('DEVRADAR_AI_PROVIDER') with an empty value returns '', not the
    // fallback -- so both paths must land on "not configured".
    expect(aiConfigured(''))->toBeFalse()
        ->and(aiConfigured(null))->toBeFalse();
});

it('rejects an unknown provider rather than guessing', function () {
    expect(aiConfigured('gemini'))->toBeFalse()
        ->and(aiConfigured('ANTHROPIC'))->toBeFalse();
});
