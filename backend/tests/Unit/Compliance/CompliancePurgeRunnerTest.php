<?php

declare(strict_types=1);

use DevRadar\Application\Compliance\CompliancePurgeRunner;
use DevRadar\Domain\Compliance\ComplianceEvent;
use DevRadar\Domain\Compliance\ComplianceFinding;
use DevRadar\Domain\Compliance\ComplianceJob;
use DevRadar\Domain\Compliance\ComplianceJobState;
use Tests\Fake\FakeComplianceProvider;
use Tests\Fake\InMemoryComplianceRepository;
use Tests\Fake\RecordingLogger;

function complianceNow(string $moment = '2026-09-10 12:00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
}

/** @return array{0: CompliancePurgeRunner, 1: FakeComplianceProvider, 2: InMemoryComplianceRepository, 3: RecordingLogger} */
function complianceRunner(
    ?FakeComplianceProvider $provider = null,
    ?InMemoryComplianceRepository $repo = null,
    string $now = '2026-09-10 12:00:00',
): array {
    $provider ??= new FakeComplianceProvider();
    $repo ??= new InMemoryComplianceRepository();
    $logger = new RecordingLogger();

    $runner = new CompliancePurgeRunner(
        $provider, $repo, $logger,
        batchSize: 1000,
        recheckAfterHours: 24,
        clock: static fn () => complianceNow($now),
    );

    return [$runner, $provider, $repo, $logger];
}

function openJob(ComplianceJobState $state, ?string $uploadExpires = null, ?string $downloadExpires = null): ComplianceJob
{
    return new ComplianceJob(
        id: 1,
        state: $state,
        providerJobId: 'job-abc',
        uploadUrl: 'https://upload.example/abc',
        downloadUrl: 'https://download.example/abc',
        uploadExpiresAt: $uploadExpires === null ? null : complianceNow($uploadExpires),
        downloadExpiresAt: $downloadExpires === null ? null : complianceNow($downloadExpires),
        idCount: 500,
    );
}

// ============================================================ STARTING A CYCLE

it('does nothing when every post has been checked recently', function () {
    [$runner, $provider, $repo] = complianceRunner();
    $repo->due = [];

    $outcome = $runner->run();

    // The healthy state, not a problem. Creating a job with nothing to check
    // would burn one of the one-at-a-time slots for no reason.
    expect($outcome->phase)->toBe('idle')
        ->and($provider->createCalls)->toBe(0);
});

it('creates a job and uploads the ids due for checking', function () {
    [$runner, $provider, $repo] = complianceRunner();
    $repo->due = ['100', '200', '300'];

    $outcome = $runner->run();

    expect($provider->createCalls)->toBe(1)
        ->and($provider->uploadedIds)->toBe(['100', '200', '300'])
        ->and($outcome->phase)->toBe('submitted')
        ->and($outcome->checked)->toBe(3);
});

it('marks posts checked at upload time, not at download', function () {
    [$runner, , $repo] = complianceRunner();
    $repo->due = ['100', '200'];

    $runner->run();

    // The provider reports only CHANGED posts, so a compliant post never
    // appears in the results. Waiting for the download to mark it checked
    // would leave it permanently due and re-uploaded every cycle.
    expect($repo->checked)->toBe(['100', '200']);
});

it('moves the job to in_progress after a successful upload', function () {
    [$runner, , $repo] = complianceRunner();
    $repo->due = ['100'];

    $runner->run();

    expect($repo->lastState())->toBe('in_progress');
});

it('records a failure when the upload is rejected', function () {
    $provider = new FakeComplianceProvider();
    $provider->uploadSucceeds = false;

    [$runner, , $repo] = complianceRunner($provider);
    $repo->due = ['100'];

    $outcome = $runner->run();

    expect($outcome->phase)->toBe('failed')
        ->and($repo->lastState())->toBe('failed')
        ->and($repo->checked)->toHaveCount(0);
});

// ============================================================ ONE JOB AT A TIME

it('never creates a second job while one is open', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::InProgress, downloadExpires: '2026-09-17 12:00:00');
    $repo->due = ['100'];

    [$runner, $provider] = complianceRunner(repo: $repo);
    $runner->run();

    // X permits exactly one concurrent job per type and rejects a second.
    expect($provider->createCalls)->toBe(0)
        ->and($provider->statusCalls)->toBe(1);
});

// ============================================================ EXPIRY WINDOWS

it('abandons a job whose upload window closed', function () {
    $repo = new InMemoryComplianceRepository();
    // Upload URL expired two minutes ago.
    $repo->open = openJob(ComplianceJobState::Created, uploadExpires: '2026-09-10 11:58:00');
    $repo->due = ['100'];

    [$runner, $provider, , $logger] = complianceRunner(repo: $repo);
    $outcome = $runner->run();

    // Retrying a dead pre-signed URL fails silently and leaves the job stuck.
    expect($outcome->phase)->toBe('failed')
        ->and($repo->lastState())->toBe('expired')
        ->and($provider->uploadedIds)->toHaveCount(0)
        ->and($logger->withMessage('compliance.upload_window_expired'))->toHaveCount(1);
});

it('applies a safety margin to the upload window', function () {
    $repo = new InMemoryComplianceRepository();
    // 30 seconds left: inside the window but not worth racing.
    $repo->open = openJob(ComplianceJobState::Created, uploadExpires: '2026-09-10 12:00:30');
    $repo->due = ['100'];

    [$runner] = complianceRunner(repo: $repo);

    expect($runner->run()->phase)->toBe('failed');
});

it('uploads when the window has comfortable time left', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::Created, uploadExpires: '2026-09-10 12:10:00');
    $repo->due = ['100', '200'];

    [$runner, $provider] = complianceRunner(repo: $repo);

    expect($runner->run()->phase)->toBe('submitted')
        ->and($provider->uploadedIds)->toHaveCount(2);
});

it('abandons a job whose results expired undownloaded', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::InProgress, downloadExpires: '2026-09-09 12:00:00');

    [$runner, , , $logger] = complianceRunner(repo: $repo);
    $outcome = $runner->run();

    expect($outcome->phase)->toBe('failed')
        ->and($repo->lastState())->toBe('expired')
        ->and($logger->withMessage('compliance.results_expired'))->toHaveCount(1);
});

// ============================================================ POLLING

it('waits while the provider is still processing', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::InProgress, downloadExpires: '2026-09-17 12:00:00');

    $provider = new FakeComplianceProvider();
    $provider->reportedStatus = ComplianceJobState::InProgress;

    [$runner] = complianceRunner($provider, $repo);
    $outcome = $runner->run();

    expect($outcome->phase)->toBe('waiting')
        ->and($provider->downloadCalls)->toBe(0);
});

it('records a provider-side job failure', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::InProgress, downloadExpires: '2026-09-17 12:00:00');

    $provider = new FakeComplianceProvider();
    $provider->reportedStatus = ComplianceJobState::Failed;

    [$runner, , , $logger] = complianceRunner($provider, $repo);

    expect($runner->run()->phase)->toBe('failed')
        ->and($repo->lastState())->toBe('failed')
        ->and($logger->withMessage('compliance.job_failed'))->toHaveCount(1);
});

// ============================================================ APPLYING RESULTS

it('removes a deleted post and closes the cycle', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::InProgress, downloadExpires: '2026-09-17 12:00:00');

    $provider = new FakeComplianceProvider();
    $provider->reportedStatus = ComplianceJobState::Complete;
    $provider->results = [new ComplianceFinding('100', ComplianceEvent::Deleted)];

    [$runner] = complianceRunner($provider, $repo);
    $outcome = $runner->run();

    expect($outcome->phase)->toBe('applied')
        ->and($outcome->removed)->toBe(1)
        ->and($outcome->scrubbed)->toBe(1)
        ->and($repo->applied[0]->event)->toBe(ComplianceEvent::Deleted)
        ->and($repo->lastState())->toBe('complete');
});

it('treats an empty result set as the healthy answer', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::InProgress, downloadExpires: '2026-09-17 12:00:00');

    $provider = new FakeComplianceProvider();
    $provider->reportedStatus = ComplianceJobState::Complete;
    $provider->results = [];

    [$runner] = complianceRunner($provider, $repo);
    $outcome = $runner->run();

    // X reports only what changed. Nothing changed means everything stored is
    // still compliant, which is the normal outcome and not a failure.
    expect($outcome->phase)->toBe('applied')
        ->and($outcome->removed)->toBe(0)
        ->and($repo->lastState())->toBe('complete');
});

it('separates permanent removals from reversible ones', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::InProgress, downloadExpires: '2026-09-17 12:00:00');

    $provider = new FakeComplianceProvider();
    $provider->reportedStatus = ComplianceJobState::Complete;
    $provider->results = [
        new ComplianceFinding('100', ComplianceEvent::Deleted),
        new ComplianceFinding('200', ComplianceEvent::Bounced),
        new ComplianceFinding('300', ComplianceEvent::Protected_),
        new ComplianceFinding('400', ComplianceEvent::Suspended),
    ];

    [$runner] = complianceRunner($provider, $repo);
    $outcome = $runner->run();

    // Deleted and bounced are gone for good, so the text is scrubbed. A
    // protected or suspended account can be reinstated, so the row is only
    // hidden -- scrubbing would mean re-purchasing the post if it came back.
    expect($outcome->removed)->toBe(4)
        ->and($outcome->scrubbed)->toBe(2)
        ->and($outcome->hidden)->toBe(2);
});

it('ignores a geo scrub, which touches data we never stored', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::InProgress, downloadExpires: '2026-09-17 12:00:00');

    $provider = new FakeComplianceProvider();
    $provider->reportedStatus = ComplianceJobState::Complete;
    $provider->results = [new ComplianceFinding('100', ComplianceEvent::ScrubGeo)];

    [$runner] = complianceRunner($provider, $repo);
    $outcome = $runner->run();

    expect($outcome->removed)->toBe(0)
        ->and($outcome->byEvent['scrub_geo'])->toBe(1)
        ->and($repo->applied)->toHaveCount(0);
});

it('counts findings by event so a spike is visible', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::InProgress, downloadExpires: '2026-09-17 12:00:00');

    $provider = new FakeComplianceProvider();
    $provider->reportedStatus = ComplianceJobState::Complete;
    $provider->results = [
        new ComplianceFinding('1', ComplianceEvent::Deleted),
        new ComplianceFinding('2', ComplianceEvent::Deleted),
        new ComplianceFinding('3', ComplianceEvent::Suspended),
    ];

    [$runner] = complianceRunner($provider, $repo);

    expect($runner->run()->byEvent)->toBe(['deleted' => 2, 'suspended' => 1]);
});

it('shrugs off a finding for a post it no longer holds', function () {
    $repo = new InMemoryComplianceRepository();
    $repo->open = openJob(ComplianceJobState::InProgress, downloadExpires: '2026-09-17 12:00:00');
    $repo->known = ['100'];

    $provider = new FakeComplianceProvider();
    $provider->reportedStatus = ComplianceJobState::Complete;
    $provider->results = [
        new ComplianceFinding('100', ComplianceEvent::Deleted),
        new ComplianceFinding('999', ComplianceEvent::Deleted),
    ];

    [$runner] = complianceRunner($provider, $repo);

    // A batch can cover IDs a previous cycle already removed. Not an error.
    expect($runner->run()->removed)->toBe(1);
});

// ============================================================ FAULT TOLERANCE

it('escalates a provider outage rather than crashing the worker', function () {
    $provider = new FakeComplianceProvider();
    $provider->createThrows = true;

    [$runner, , $repo, $logger] = complianceRunner($provider);
    $repo->due = ['100'];

    $outcome = $runner->run();

    // Compliance failing is urgent, but urgent means "alert a human", not
    // "take the worker down".
    expect($outcome->phase)->toBe('failed')
        ->and($logger->withMessage('compliance.cycle_failed'))->toHaveCount(1);
});

it('does not hide the whole feed when the provider is unreachable', function () {
    $provider = new FakeComplianceProvider();
    $provider->createThrows = true;

    [$runner, , $repo] = complianceRunner($provider);
    $repo->due = ['100', '200'];

    $runner->run();

    // Posts are presumed compliant until told otherwise, which is what the
    // policy asks for. Failing closed would blank the product on an outage.
    expect($repo->applied)->toHaveCount(0)
        ->and($repo->checked)->toHaveCount(0);
});

// ============================================================ EVENT SEMANTICS

it('knows which events require removal', function () {
    expect(ComplianceEvent::Deleted->requiresRemoval())->toBeTrue()
        ->and(ComplianceEvent::Bounced->requiresRemoval())->toBeTrue()
        ->and(ComplianceEvent::Protected_->requiresRemoval())->toBeTrue()
        ->and(ComplianceEvent::Suspended->requiresRemoval())->toBeTrue()
        ->and(ComplianceEvent::ScrubGeo->requiresRemoval())->toBeFalse();
});

it('knows which removals are permanent', function () {
    expect(ComplianceEvent::Deleted->isPermanent())->toBeTrue()
        ->and(ComplianceEvent::Bounced->isPermanent())->toBeTrue()
        ->and(ComplianceEvent::Protected_->isPermanent())->toBeFalse()
        ->and(ComplianceEvent::Suspended->isPermanent())->toBeFalse();
});

it('maps provider job statuses onto its own', function () {
    expect(ComplianceJobState::fromProviderStatus('complete'))->toBe(ComplianceJobState::Complete)
        ->and(ComplianceJobState::fromProviderStatus('failed'))->toBe(ComplianceJobState::Failed)
        ->and(ComplianceJobState::fromProviderStatus('in_progress'))->toBe(ComplianceJobState::InProgress)
        // An unrecognised status is treated as still running rather than as
        // finished: assuming completion would close a job whose results were
        // never read.
        ->and(ComplianceJobState::fromProviderStatus('something-new'))->toBe(ComplianceJobState::InProgress);
});

it('parses event labels case-insensitively and rejects unknown ones', function () {
    expect(ComplianceEvent::tryFromLabel('DELETED'))->toBe(ComplianceEvent::Deleted)
        ->and(ComplianceEvent::tryFromLabel(' suspended '))->toBe(ComplianceEvent::Suspended)
        ->and(ComplianceEvent::tryFromLabel('nonsense'))->toBeNull()
        ->and(ComplianceEvent::tryFromLabel(null))->toBeNull();
});
