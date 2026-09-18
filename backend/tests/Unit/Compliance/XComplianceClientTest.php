<?php

declare(strict_types=1);

use DevRadar\Domain\Compliance\ComplianceEvent;
use DevRadar\Domain\Compliance\ComplianceJob;
use DevRadar\Domain\Compliance\ComplianceJobState;
use DevRadar\Infrastructure\Http\HttpResponse;
use DevRadar\Infrastructure\Http\HttpTransportException;
use DevRadar\Infrastructure\X\XComplianceClient;
use Tests\Fake\FakeHttpClient;

/**
 * The compliance transport, which had no tests at all.
 *
 * This is the integration whose failure is a legal exposure rather than a
 * missing feature: when it misreads a results file, content we are required
 * to stop displaying stays on the page. It parses JSON Lines from an external
 * API -- exactly the "malformed external API response" case -- and nothing
 * was exercising that.
 */

function complianceClient(FakeHttpClient $http): XComplianceClient
{
    return new XComplianceClient($http, 'test-bearer-token');
}

function jobWithDownload(string $url = 'https://download.example/results'): ComplianceJob
{
    return new ComplianceJob(id: 1, state: ComplianceJobState::Complete, providerJobId: 'job-1', downloadUrl: $url);
}

// ------------------------------------------------------------ job lifecycle

it('creates a job and keeps the pre-signed URLs', function () {
    $body = json_encode(['data' => [
        'id' => 'job-abc',
        'status' => 'created',
        'upload_url' => 'https://upload.example/abc?sig=x',
        'download_url' => 'https://download.example/abc?sig=y',
        'upload_expires_at' => '2026-09-12T12:15:00Z',
        'download_expires_at' => '2026-09-19T12:00:00Z',
    ]]);

    $job = complianceClient((new FakeHttpClient())->queue(new HttpResponse(200, $body)))->createJob('run-1');

    // There is no way to derive these again; losing them loses the results.
    expect($job->providerJobId)->toBe('job-abc')
        ->and($job->uploadUrl)->toContain('upload.example')
        ->and($job->downloadUrl)->toContain('download.example')
        ->and($job->downloadExpiresAt)->not->toBeNull();
});

it('sends the documented job type', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, json_encode(['data' => ['id' => 'j']])));
    complianceClient($http)->createJob('run-1');

    expect($http->lastBody()['type'])->toBe('tweets')
        ->and($http->requests[0]['headers']['Authorization'])->toBe('Bearer test-bearer-token');
});

it('refuses a creation response with no job id', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, json_encode(['data' => []])));

    // Silently continuing would leave the pipeline believing a job exists.
    expect(fn () => complianceClient($http)->createJob('x'))->toThrow(RuntimeException::class);
});

it('maps every documented job state', function () {
    foreach (['created', 'in_progress', 'complete', 'failed', 'expired'] as $state) {
        $http = (new FakeHttpClient())->queue(new HttpResponse(200, json_encode(['data' => ['id' => 'j', 'status' => $state]])));

        expect(complianceClient($http)->jobStatus('j')->state->value)->toBe($state);
    }
});

it('falls back to created for an unrecognised state', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, json_encode(['data' => ['id' => 'j', 'status' => 'wat']])));

    // The safe direction: an unknown state keeps the job open rather than
    // closing it and abandoning results we may still need.
    expect(complianceClient($http)->jobStatus('j')->state)->toBe(ComplianceJobState::Created);
});

// ------------------------------------------------------------------ upload

it('uploads ids one per line as text', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, ''));
    $job = new ComplianceJob(id: 1, state: ComplianceJobState::Created, uploadUrl: 'https://upload.example/x');

    expect(complianceClient($http)->uploadIds($job, ['100', '200', '300']))->toBeTrue();
});

it('reports a rejected upload rather than pretending it worked', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(403, 'denied'));
    $job = new ComplianceJob(id: 1, state: ComplianceJobState::Created, uploadUrl: 'https://upload.example/x');

    expect(complianceClient($http)->uploadIds($job, ['100']))->toBeFalse();
});

it('refuses to upload without an upload URL', function () {
    $job = new ComplianceJob(id: 1, state: ComplianceJobState::Created);

    expect(fn () => complianceClient(new FakeHttpClient())->uploadIds($job, ['1']))
        ->toThrow(RuntimeException::class);
});

// --------------------------------------------------- results parsing

it('parses JSON Lines results', function () {
    $body = implode("\n", [
        json_encode(['id' => '100', 'action' => 'delete', 'reason' => 'deleted']),
        json_encode(['id' => '200', 'action' => 'delete', 'reason' => 'suspended']),
    ]);

    $findings = complianceClient((new FakeHttpClient())->queue(new HttpResponse(200, $body)))
        ->downloadResults(jobWithDownload());

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->postId)->toBe('100')
        ->and($findings[0]->event)->toBe(ComplianceEvent::Deleted)
        ->and($findings[1]->event)->toBe(ComplianceEvent::Suspended);
});

it('treats an empty results file as the ordinary case', function () {
    // Every uploaded id is still live. This is the answer most of the time,
    // and reading it as a failure would stall the whole cycle.
    $findings = complianceClient((new FakeHttpClient())->queue(new HttpResponse(200, '')))
        ->downloadResults(jobWithDownload());

    expect($findings)->toHaveCount(0);
});

it('skips a malformed line instead of abandoning the file', function () {
    $body = implode("\n", [
        json_encode(['id' => '100', 'reason' => 'deleted']),
        '{not valid json',
        '',
        json_encode(['id' => '300', 'reason' => 'deleted']),
    ]);

    $findings = complianceClient((new FakeHttpClient())->queue(new HttpResponse(200, $body)))
        ->downloadResults(jobWithDownload());

    // The surviving lines name posts we are legally required to stop showing.
    // Throwing the file away over one bad line would leave them up.
    expect($findings)->toHaveCount(2)
        ->and(array_map(fn ($f) => $f->postId, $findings))->toBe(['100', '300']);
});

it('drops a record with an unrecognised event rather than assuming deletion', function () {
    $body = json_encode(['id' => '100', 'reason' => 'something_new']);

    // Removing content on an event nobody defined is worse than leaving it
    // and re-checking next cycle.
    expect(complianceClient((new FakeHttpClient())->queue(new HttpResponse(200, $body)))
        ->downloadResults(jobWithDownload()))->toHaveCount(0);
});

it('drops a record with a malformed post id', function () {
    $body = implode("\n", [
        json_encode(['id' => 'not-a-number', 'reason' => 'deleted']),
        json_encode(['id' => '', 'reason' => 'deleted']),
        json_encode(['reason' => 'deleted']),
    ]);

    expect(complianceClient((new FakeHttpClient())->queue(new HttpResponse(200, $body)))
        ->downloadResults(jobWithDownload()))->toHaveCount(0);
});

it('distinguishes a geo scrub, which must not purge', function () {
    $body = json_encode(['id' => '100', 'reason' => 'scrub_geo']);

    $findings = complianceClient((new FakeHttpClient())->queue(new HttpResponse(200, $body)))
        ->downloadResults(jobWithDownload());

    // Flattening this into a deletion pulls a live project from the feed.
    expect($findings[0]->event->requiresRemoval())->toBeFalse();
});

// ---------------------------------------------------------- failure modes

it('throws on a failed download rather than reporting no findings', function () {
    // An empty result and a failed request look identical to a caller that
    // does not distinguish them -- and one means "nothing to remove".
    $http = (new FakeHttpClient())->queue(new HttpResponse(500, 'upstream error'));

    expect(fn () => complianceClient($http)->downloadResults(jobWithDownload()))
        ->toThrow(RuntimeException::class);
});

it('throws on a transport failure', function () {
    $http = (new FakeHttpClient())->queue(new HttpTransportException('connection reset'));

    expect(fn () => complianceClient($http)->downloadResults(jobWithDownload()))
        ->toThrow(RuntimeException::class);
});

it('refuses to download without a download URL', function () {
    $job = new ComplianceJob(id: 1, state: ComplianceJobState::Complete);

    expect(fn () => complianceClient(new FakeHttpClient())->downloadResults($job))
        ->toThrow(RuntimeException::class);
});

it('never leaks the bearer token through an error message', function () {
    $http = (new FakeHttpClient())->queue(new HttpTransportException('failed for Bearer test-bearer-token'));

    try {
        complianceClient($http)->downloadResults(jobWithDownload());
        $message = '';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toContain('test-bearer-token');
});
