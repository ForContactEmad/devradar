<?php

declare(strict_types=1);

use DevRadar\Application\Observability\LogContext;
use DevRadar\Domain\Support\LogRedactor;

/**
 * Correlation context and redaction.
 *
 * Both are the kind of thing that works in review and fails in a long-lived
 * worker, so the tests here are mostly about state leaking between units of
 * work and about credentials leaking into context arrays.
 */

it('stamps a correlation id on the current unit of work', function () {
    $context = new LogContext();
    $context->begin('run_abc123', 'classify');

    expect($context->correlationId())->toBe('run_abc123')
        ->and($context->fields())->toBe(['correlation_id' => 'run_abc123', 'stage' => 'classify']);
});

it('discards the previous unit of work on begin', function () {
    // THE FAILURE THIS PREVENTS. A queue worker handles thousands of jobs in
    // one process. A context that accumulated would stamp the first job's
    // correlation id on every later failure, which is worse than no id at all
    // because it points the investigation at the wrong run.
    $context = new LogContext();
    $context->begin('run_first', 'collect');
    $context->set('stage_run_id', 11);

    $context->begin('run_second', 'classify');

    expect($context->correlationId())->toBe('run_second')
        ->and($context->fields())->not->toHaveKey('stage_run_id');
});

it('clears completely', function () {
    $context = new LogContext();
    $context->begin('run_abc', 'score');
    $context->clear();

    expect($context->fields())->toBe([])
        ->and($context->correlationId())->toBeNull();
});

it('carries job and run identifiers alongside the correlation id', function () {
    $context = new LogContext();
    $context->begin('classify_9f2', 'classify');
    $context->set('stage_run_id', 402);
    $context->set('job_id', 'laravel-job-771');

    $fields = $context->fields();

    // Answers "which queue job, which recorded run, which trace" from one
    // line, which is what makes a failed_jobs row findable from a log.
    expect($fields['stage_run_id'])->toBe(402)
        ->and($fields['job_id'])->toBe('laravel-job-771')
        ->and($fields['correlation_id'])->toBe('classify_9f2');
});

it('removes a field set to null rather than logging a null', function () {
    $context = new LogContext();
    $context->begin('run_a');
    $context->set('job_id', 'x');
    $context->set('job_id', null);

    // A job running synchronously has no queue id; logging "job_id": null on
    // every line is noise.
    expect($context->fields())->not->toHaveKey('job_id');
});

it('omits a null stage rather than emitting an empty one', function () {
    $context = new LogContext();
    $context->begin('req_abc');

    expect($context->fields())->toBe(['correlation_id' => 'req_abc']);
});

it('generates prefixed, greppable ids', function () {
    $id = LogContext::newId('classify');

    // Prefixed so the kind of unit is obvious in a log; short so it can be
    // quoted in a bug report and read aloud.
    expect($id)->toStartWith('classify_')
        ->and(strlen($id))->toBeLessThan(32);
});

it('does not repeat ids', function () {
    $ids = [];
    for ($i = 0; $i < 500; $i++) {
        $ids[] = LogContext::newId();
    }

    expect(count(array_unique($ids)))->toBe(500);
});

// ─────────────────────────────────────────────────────────── redaction

it('redacts a bearer token from a message', function () {
    expect(LogRedactor::text('called with Bearer sk-live-abcdef123456789'))
        ->not->toContain('abcdef123456789');
});

it('redacts a credential in a query string', function () {
    // The case that was reaching logs unredacted: a provider URL in a
    // context array.
    $redacted = LogRedactor::context([
        'url' => 'https://api.github.com/repos?access_token=ghp_realSecretValue123456',
    ]);

    expect($redacted['url'])->not->toContain('ghp_realSecretValue123456')
        ->and($redacted['url'])->toContain('api.github.com');
});

it('redacts by key name as well as by value shape', function () {
    $redacted = LogRedactor::context(['token' => 'anything-at-all', 'repository' => 'acme/tool']);

    // A secret that matches no pattern is still a secret if the key says so.
    expect($redacted['token'])->not->toContain('anything-at-all')
        ->and($redacted['repository'])->toBe('acme/tool');
});

it('leaves ordinary operational context untouched', function () {
    $context = ['stage' => 'classify', 'claimed' => 50, 'accepted' => 12, 'cost_usd' => 0.0451];

    // Redaction that mangled the counts would make the logs useless in the
    // course of making them safe.
    expect(LogRedactor::context($context))->toBe($context);
});

it('survives nested context without losing structure', function () {
    $redacted = LogRedactor::context([
        'stage' => 'enrich',
        'error' => ['class' => 'RuntimeException', 'message' => 'failed with Bearer ghp_secret123456789'],
    ]);

    expect($redacted['stage'])->toBe('enrich')
        ->and(json_encode($redacted))->not->toContain('ghp_secret123456789');
});
