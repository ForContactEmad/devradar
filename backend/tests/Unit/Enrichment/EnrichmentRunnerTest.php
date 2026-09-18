<?php

declare(strict_types=1);

use DevRadar\Application\Enrichment\EnrichmentRunner;
use DevRadar\Application\Enrichment\RepositoryAnalyzer;
use DevRadar\Domain\Enrichment\EnrichmentTarget;
use DevRadar\Domain\Enrichment\RepositoryFacts;
use DevRadar\Domain\Enrichment\RepositoryLookup;
use DevRadar\Domain\Enrichment\RepositoryRef;
use Tests\Fake\FakeRepositoryProvider;
use Tests\Fake\InMemoryEnrichmentRepository;
use Tests\Fake\RecordingLogger;

function enrichmentTarget(int $id, ?string $etag = null): EnrichmentTarget
{
    return new EnrichmentTarget($id, RepositoryRef::of('acme', "repo{$id}"), $etag);
}

function facts(int $stars = 100): RepositoryFacts
{
    return new RepositoryFacts(
        ref: RepositoryRef::of('acme', 'repo1'),
        stars: $stars,
        forks: 10,
        openIssues: 3,
        primaryLanguage: 'Rust',
        etag: 'W/"new"',
    );
}

/** @return array{0: EnrichmentRunner, 1: InMemoryEnrichmentRepository, 2: FakeRepositoryProvider, 3: RecordingLogger} */
function enrichmentRunner(array $targets, FakeRepositoryProvider $provider, int $reserve = 50): array
{
    $repo = new InMemoryEnrichmentRepository();
    $repo->due = $targets;
    $logger = new RecordingLogger();

    $runner = new EnrichmentRunner(
        $repo,
        new RepositoryAnalyzer($provider, $repo, $logger),
        $logger,
        refreshAfterMinutes: 720,
        maxFailures: 5,
        quotaReserve: $reserve,
    );

    return [$runner, $repo, $provider, $logger];
}

it('stores facts for a repository that was found', function () {
    $provider = (new FakeRepositoryProvider())->queue(RepositoryLookup::found(facts(3200), 4990));
    [$runner, $repo] = enrichmentRunner([enrichmentTarget(1)], $provider);

    $stats = $runner->run();

    expect($stats['found'])->toBe(1)
        ->and($repo->stored[1]->stars)->toBe(3200);
});

it('sends a stored etag so a refresh can come back unchanged', function () {
    $provider = (new FakeRepositoryProvider())->queue(RepositoryLookup::unchanged(4999));
    [$runner, $repo] = enrichmentRunner([enrichmentTarget(1, 'W/"abc"')], $provider);

    $stats = $runner->run();

    expect($provider->calls[0]['etag'])->toBe('W/"abc"')
        ->and($stats['unchanged'])->toBe(1)
        // Only freshness moves; rewriting identical values would churn the
        // row and lose the fact that nothing changed.
        ->and($repo->touched)->toBe([1])
        ->and($repo->stored)->toHaveCount(0);
});

it('does not count an unchanged lookup against quota', function () {
    $provider = (new FakeRepositoryProvider())->queue(
        RepositoryLookup::unchanged(4999),
        RepositoryLookup::found(facts(), 4998),
    );
    [$runner] = enrichmentRunner([enrichmentTarget(1, 'W/"a"'), enrichmentTarget(2)], $provider);

    $stats = $runner->run();

    // Two lookups, one billable. This ratio is what makes a twice-daily
    // refresh of hundreds of repositories affordable.
    expect($stats['claimed'])->toBe(2)
        ->and($stats['requests_counted'])->toBe(1);
});

it('takes a deleted repository out of the queue permanently', function () {
    $provider = (new FakeRepositoryProvider())->queue(RepositoryLookup::notFound('deleted'));
    [$runner, $repo] = enrichmentRunner([enrichmentTarget(1)], $provider);

    $stats = $runner->run();

    expect($stats['not_found'])->toBe(1)
        ->and($repo->gone)->toHaveKey(1)
        ->and($repo->failures)->toHaveCount(0);
});

it('takes a private repository out of the queue without calling it a failure', function () {
    $provider = (new FakeRepositoryProvider())->queue(RepositoryLookup::private_('forbidden'));
    [$runner, $repo] = enrichmentRunner([enrichmentTarget(1)], $provider);

    expect($runner->run()['private'])->toBe(1)
        ->and($repo->gone)->toHaveKey(1);
});

it('counts a transient failure against the retry budget', function () {
    $provider = (new FakeRepositoryProvider())->queue(RepositoryLookup::failed('server error'));
    [$runner, $repo] = enrichmentRunner([enrichmentTarget(1)], $provider);

    expect($runner->run()['failed'])->toBe(1)
        ->and($repo->failures)->toHaveKey(1)
        ->and($repo->gone)->toHaveCount(0);
});

it('stops the batch on a rate limit instead of grinding through it', function () {
    $provider = (new FakeRepositoryProvider())->queue(
        RepositoryLookup::found(facts(), 4000),
        RepositoryLookup::rateLimited('exhausted', time() + 600),
    );

    [$runner] = enrichmentRunner([enrichmentTarget(1), enrichmentTarget(2), enrichmentTarget(3)], $provider);
    $stats = $runner->run();

    // Continuing would turn one throttled minute into a batch of failures
    // that look like broken repositories.
    expect($provider->callCount())->toBe(2)
        ->and($stats['stopped_early'])->toBeTrue()
        ->and($stats['quota_resets_in_seconds'])->toBeGreaterThan(500);
});

it('does not blame a repository for a rate limit', function () {
    $provider = (new FakeRepositoryProvider())->queue(RepositoryLookup::rateLimited('exhausted'));
    [$runner, $repo] = enrichmentRunner([enrichmentTarget(1)], $provider);

    $runner->run();

    // A single throttled hour would otherwise blacklist everything in the
    // batch.
    expect($repo->failures)->toHaveCount(0)
        ->and($repo->gone)->toHaveCount(0);
});

it('stops while quota remains so other work is not starved', function () {
    $provider = (new FakeRepositoryProvider())->queue(
        RepositoryLookup::found(facts(), 60),
        RepositoryLookup::found(facts(), 45),
    );

    [$runner, , , $logger] = enrichmentRunner(
        [enrichmentTarget(1), enrichmentTarget(2), enrichmentTarget(3)],
        $provider,
        reserve: 50,
    );

    $stats = $runner->run();

    expect($provider->callCount())->toBe(2)
        ->and($stats['stopped_early'])->toBeTrue()
        ->and($logger->withMessage('enrichment.quota_reserve_reached'))->toHaveCount(1);
});

it('continues the batch when one repository fails to persist', function () {
    $repo = new InMemoryEnrichmentRepository();
    $repo->due = [enrichmentTarget(1), enrichmentTarget(2)];
    $repo->failOnStore = true;

    $provider = (new FakeRepositoryProvider())->queue(
        RepositoryLookup::found(facts(), 4000),
        RepositoryLookup::found(facts(), 3999),
    );

    $logger = new RecordingLogger();
    $runner = new EnrichmentRunner($repo, new RepositoryAnalyzer($provider, $repo, $logger), $logger);

    $stats = $runner->run();

    // A storage failure is logged, not thrown: enrichment must never stop
    // the pipeline.
    expect($stats['claimed'])->toBe(2)
        ->and($provider->callCount())->toBe(2)
        ->and($logger->withMessage('enrichment.persistence_failed'))->toHaveCount(2);
});

it('does nothing when nothing is due', function () {
    $provider = new FakeRepositoryProvider();
    [$runner] = enrichmentRunner([], $provider);

    expect($runner->run()['claimed'])->toBe(0)
        ->and($provider->callCount())->toBe(0);
});

it('links projects to repository rows before fetching', function () {
    $repo = new InMemoryEnrichmentRepository();
    $repo->linkedCount = 4;

    $logger = new RecordingLogger();
    $runner = new EnrichmentRunner($repo, new RepositoryAnalyzer(new FakeRepositoryProvider(), $repo, $logger), $logger);

    expect($runner->run()['linked'])->toBe(4);
});
