<?php

declare(strict_types=1);

use DevRadar\Application\Collection\CollectionRunner;
use DevRadar\Application\Collection\TweetCollector;
use DevRadar\Application\Collection\RecentWindowStrategy;
use DevRadar\Domain\Collection\SearchQueryDefinition;
use DevRadar\Domain\Collection\WindowResolver;
use DevRadar\Infrastructure\Http\HttpResponse;
use DevRadar\Infrastructure\X\RetryPolicy;
use DevRadar\Infrastructure\X\XApiConfig;
use DevRadar\Infrastructure\X\XErrorClassifier;
use DevRadar\Infrastructure\X\XPostMapper;
use DevRadar\Infrastructure\X\XPostProvider;
use DevRadar\Infrastructure\X\XRequestBuilder;
use Tests\Fake\FakeBudgetGuard;
use Tests\Fake\FakeHttpClient;
use Tests\Fake\FixedClock;
use Tests\Fake\InMemoryTweetRepository;
use Tests\Fake\RecordingLogger;
use Tests\Fake\RecordingSearchRunLedger;
use Tests\Fake\StaticSearchQuerySource;

function runnerFixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/../../Fixtures/' . $name . '.json');
}

/** @param list<HttpResponse> $responses */
function makeRunner(array $definitions, array $responses): array
{
    $config = new XApiConfig(bearerToken: 't');
    $http = new FakeHttpClient();

    foreach ($responses as $response) {
        $http->queue($response);
    }

    $provider = new XPostProvider(
        $http, $config, new XRequestBuilder($config), new XPostMapper(),
        new XErrorClassifier(), new RetryPolicy(maxAttempts: 1, baseSeconds: 0.001),
        new FakeBudgetGuard(), new RecordingLogger(), static fn (float $s) => null,
    );

    $repo = new InMemoryTweetRepository();
    $ledger = new RecordingSearchRunLedger();

    $strategy = new RecentWindowStrategy(
        queries: new StaticSearchQuerySource($definitions),
        windows: new WindowResolver(new FixedClock()),
        repository: $repo,
        maxPagesPerQuery: 1,
    );

    $runner = new CollectionRunner(
        $strategy,
        new TweetCollector($provider, $repo, $ledger, new RecordingLogger()),
        new RecordingLogger(),
    );

    return [$runner, $repo, $ledger, $http];
}

function def(int $id, string $name): SearchQueryDefinition
{
    return new SearchQueryDefinition(id: $id, name: $name, family: 'A', expression: "({$name})");
}

it('runs every planned query in one cycle', function () {
    [$runner, , , $http] = makeRunner(
        [def(1, 'one'), def(2, 'two'), def(3, 'three')],
        array_fill(0, 3, new HttpResponse(200, runnerFixture('x-search-page-2'))),
    );

    $summary = $runner->run();

    expect($summary->reports)->toHaveCount(3)
        ->and($http->requestCount())->toBe(3);
});

it('continues the cycle when one query fails', function () {
    [$runner, $repo] = makeRunner(
        [def(1, 'ok-one'), def(2, 'broken'), def(3, 'ok-two')],
        [
            new HttpResponse(200, runnerFixture('x-search-page-2')),
            new HttpResponse(400, runnerFixture('x-error-400')),
            new HttpResponse(200, runnerFixture('x-search-page-1')),
        ],
    );

    $summary = $runner->run();

    // One malformed expression must not cost the whole cycle's coverage.
    expect($summary->reports)->toHaveCount(3)
        ->and($summary->failures())->toHaveCount(1)
        ->and($summary->failures()[0]->queryLabel)->toContain('broken')
        ->and($repo->storedPostIds)->toHaveCount(3);
});

it('stops the cycle on an auth failure instead of burning requests', function () {
    [$runner, , , $http] = makeRunner(
        [def(1, 'first'), def(2, 'second'), def(3, 'third')],
        [
            new HttpResponse(401, runnerFixture('x-error-401')),
            new HttpResponse(200, runnerFixture('x-search-page-2')),
            new HttpResponse(200, runnerFixture('x-search-page-2')),
        ],
    );

    $summary = $runner->run();

    // A rejected credential rejects every subsequent query identically, so
    // continuing just burns round trips against a wall.
    expect($summary->reports)->toHaveCount(1)
        ->and($http->requestCount())->toBe(1);
});

it('aggregates spend and yield across the cycle', function () {
    [$runner] = makeRunner(
        [def(1, 'one'), def(2, 'two')],
        [
            new HttpResponse(200, runnerFixture('x-search-page-1')),
            new HttpResponse(200, runnerFixture('x-search-page-2')),
        ],
    );

    $summary = $runner->run();

    // page-1: 2 posts + 2 users = 4; page-2: 1 post + 1 user = 2.
    expect($summary->totalBillableResources())->toBe(6)
        ->and($summary->totalPostsStored())->toBe(3)
        ->and($summary->totalRequests())->toBe(2);
});

it('deduplicates overlapping queries within one cycle', function () {
    [$runner, $repo] = makeRunner(
        [def(1, 'overlap-a'), def(2, 'overlap-b')],
        [
            new HttpResponse(200, runnerFixture('x-search-page-2')),
            new HttpResponse(200, runnerFixture('x-search-page-2')),
        ],
    );

    $summary = $runner->run();

    // Overlapping query families surfacing the same project is the normal
    // case, not an anomaly. Both requests are still charged for.
    expect($repo->storedPostIds)->toHaveCount(1)
        ->and($summary->totalPostsStored())->toBe(1)
        ->and($summary->reports[1]->duplicateCount())->toBe(1);
});

it('reports a cycle where everything failed', function () {
    [$runner] = makeRunner(
        [def(1, 'a'), def(2, 'b')],
        [new HttpResponse(500, '{}'), new HttpResponse(500, '{}')],
    );

    expect($runner->run()->allFailed())->toBeTrue();
});

it('does nothing when no query is active', function () {
    [$runner, , , $http] = makeRunner([], []);
    $summary = $runner->run();

    expect($summary->reports)->toHaveCount(0)
        ->and($http->requestCount())->toBe(0)
        ->and($summary->allFailed())->toBeFalse();
});
