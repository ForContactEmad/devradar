<?php

declare(strict_types=1);

use DevRadar\Application\Collection\TweetCollector;
use DevRadar\Domain\Collection\CollectionWindow;
use DevRadar\Domain\Collection\SearchPlan;
use DevRadar\Domain\Collection\SearchQueryDefinition;
use DevRadar\Domain\Ingestion\SearchCriteria;
use DevRadar\Domain\Ingestion\StopReason;
use DevRadar\Infrastructure\Http\HttpResponse;
use DevRadar\Infrastructure\X\RetryPolicy;
use DevRadar\Infrastructure\X\XApiConfig;
use DevRadar\Infrastructure\X\XErrorClassifier;
use DevRadar\Infrastructure\X\XPostMapper;
use DevRadar\Infrastructure\X\XPostProvider;
use DevRadar\Infrastructure\X\XRequestBuilder;
use Tests\Fake\FakeBudgetGuard;
use Tests\Fake\FakeHttpClient;
use Tests\Fake\InMemoryTweetRepository;
use Tests\Fake\RecordingLogger;
use Tests\Fake\RecordingSearchRunLedger;

/**
 * Collector tests. Scripted transport, in-memory store: no network, no
 * database, no spend.
 */
function collectorFixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/../../Fixtures/' . $name . '.json');
}

function collectorPlan(?string $sinceId = null, int $maxPages = 5): SearchPlan
{
    $window = new CollectionWindow(
        new DateTimeImmutable('2026-09-02 12:00:00', new DateTimeZone('UTC')),
        new DateTimeImmutable('2026-09-09 12:00:00', new DateTimeZone('UTC')),
    );

    return new SearchPlan(
        definition: new SearchQueryDefinition(id: 1, name: 'repo-launch', family: 'A', expression: '(a OR b)'),
        criteria: new SearchCriteria('(a OR b)', sinceId: $sinceId, maxPages: $maxPages),
        window: $window,
    );
}

/**
 * @param list<HttpResponse|Throwable> $responses
 *
 * @return array{0: TweetCollector, 1: InMemoryTweetRepository, 2: RecordingSearchRunLedger, 3: RecordingLogger}
 */
function makeCollector(array $responses, ?InMemoryTweetRepository $repo = null): array
{
    $config = new XApiConfig(bearerToken: 'test-token');
    $http = new FakeHttpClient();

    foreach ($responses as $response) {
        $http->queue($response);
    }

    $provider = new XPostProvider(
        http: $http,
        config: $config,
        requestBuilder: new XRequestBuilder($config),
        mapper: new XPostMapper(),
        classifier: new XErrorClassifier(),
        retryPolicy: new RetryPolicy(maxAttempts: 1, baseSeconds: 0.001),
        budget: new FakeBudgetGuard(),
        logger: new RecordingLogger(),
        sleeper: static fn (float $s) => null,
    );

    $repo ??= new InMemoryTweetRepository();
    $ledger = new RecordingSearchRunLedger();
    $logger = new RecordingLogger();

    return [new TweetCollector($provider, $repo, $ledger, $logger), $repo, $ledger, $logger];
}

// ------------------------------------------------------------- pagination

it('collects across paginated responses', function () {
    [$collector, $repo] = makeCollector([
        new HttpResponse(200, collectorFixture('x-search-page-1')),
        new HttpResponse(200, collectorFixture('x-search-page-2')),
    ]);

    $report = $collector->collect(collectorPlan());

    expect($report->postsReturned)->toBe(3)
        ->and($report->postsStored)->toBe(3)
        ->and($report->requestCount)->toBe(2)
        ->and($repo->storedPostIds)->toHaveCount(3);
});

it('persists authors alongside posts', function () {
    [$collector, $repo] = makeCollector([
        new HttpResponse(200, collectorFixture('x-search-page-1')),
        new HttpResponse(200, collectorFixture('x-search-page-2')),
    ]);

    $collector->collect(collectorPlan());

    expect($repo->storedAuthorIds)->toHaveCount(3);
});

// ---------------------------------------------------------- empty results

it('treats an empty result as a normal outcome, not a failure', function () {
    [$collector, , $ledger] = makeCollector([
        new HttpResponse(200, '{"meta":{"result_count":0}}'),
    ]);

    $report = $collector->collect(collectorPlan());

    // A quiet hour is a query working correctly, not a broken one.
    expect($report->status)->toBe('completed')
        ->and($report->postsReturned)->toBe(0)
        ->and($report->postsStored)->toBe(0)
        ->and($ledger->ops('complete'))->toHaveCount(1);
});

it('still records the run for an empty result, because the request was paid for', function () {
    [$collector, , $ledger] = makeCollector([
        new HttpResponse(200, '{"meta":{"result_count":0}}'),
    ]);

    $collector->collect(collectorPlan());

    expect($ledger->ops('begin'))->toHaveCount(1);
});

// -------------------------------------------------------------- duplicates

it('reports duplicates rather than failing on them', function () {
    $repo = new InMemoryTweetRepository();

    [$first] = makeCollector([new HttpResponse(200, collectorFixture('x-search-page-2'))], $repo);
    $first->collect(collectorPlan(maxPages: 1));

    // The same page again -- overlapping queries and retries both cause this.
    [$second] = makeCollector([new HttpResponse(200, collectorFixture('x-search-page-2'))], $repo);
    $report = $second->collect(collectorPlan(maxPages: 1));

    expect($report->postsReturned)->toBe(1)
        ->and($report->postsStored)->toBe(0)
        ->and($report->duplicateCount())->toBe(1)
        ->and($repo->storedPostIds)->toHaveCount(1);
});

it('records posts_new separately from posts_returned in the ledger', function () {
    $repo = new InMemoryTweetRepository();
    $repo->storedPostIds['1800000000000000003'] = true;

    [$collector, , $ledger] = makeCollector([
        new HttpResponse(200, collectorFixture('x-search-page-2')),
    ], $repo);

    $collector->collect(collectorPlan(maxPages: 1));
    $complete = $ledger->ops('complete')[0];

    // The gap between these two is the duplicate rate, and it is what decides
    // whether a query is worth its cost.
    expect($complete['posts_returned'])->toBe(1)
        ->and($complete['posts_new'])->toBe(0);
});

// ------------------------------------------------------------- API errors

it('records a failed run when the provider rejects the request', function () {
    [$collector, $repo, $ledger] = makeCollector([
        new HttpResponse(400, collectorFixture('x-error-400')),
    ]);

    $report = $collector->collect(collectorPlan());

    expect($report->status)->toBe('failed')
        ->and($report->errorClass)->toBe('permanent')
        ->and($repo->storedPostIds)->toHaveCount(0)
        ->and($ledger->ops('fail'))->toHaveCount(1);
});

it('does not let an API error escape the collector', function () {
    [$collector] = makeCollector([new HttpResponse(401, collectorFixture('x-error-401'))]);

    // One query failing must not abandon the rest of the cycle, so the
    // collector converts a provider exception into a recorded outcome.
    $report = $collector->collect(collectorPlan());

    expect($report->isFailure())->toBeTrue()
        ->and($report->errorClass)->toBe('auth');
});

it('opens the ledger row before fetching, so a crash mid-run leaves evidence', function () {
    [$collector, , $ledger] = makeCollector([new HttpResponse(500, '{}')]);

    $collector->collect(collectorPlan());

    expect($ledger->calls[0]['op'])->toBe('begin')
        ->and($ledger->calls[1]['op'])->toBe('fail');
});

// -------------------------------------------------------- partial failures

it('keeps the good posts from a page that also reported errors', function () {
    [$collector, $repo] = makeCollector([
        new HttpResponse(200, collectorFixture('x-search-partial-errors')),
    ]);

    $report = $collector->collect(collectorPlan());

    // The page has been paid for; discarding it would waste the spend.
    expect($report->status)->toBe('completed')
        ->and($report->postsStored)->toBe(1)
        ->and($repo->storedPostIds)->toHaveCount(1);
});

it('records a data failure when persistence fails after a paid fetch', function () {
    $repo = new InMemoryTweetRepository();
    $repo->failOnStore = true;

    [$collector, , $ledger, $logger] = makeCollector([
        new HttpResponse(200, collectorFixture('x-search-page-2')),
    ], $repo);

    $report = $collector->collect(collectorPlan());

    // Money spent, data lost. The worst case, so it must be loud and the
    // billable count must be preserved.
    expect($report->status)->toBe('failed')
        ->and($report->errorClass)->toBe('data')
        ->and($report->billableResources)->toBeGreaterThan(0)
        ->and($ledger->ops('fail')[0]['billable'])->toBeGreaterThan(0)
        ->and($logger->withMessage('collection.persistence_failed'))->toHaveCount(1);
});

// ------------------------------------------------------------------ budget

it('closes a budget-halted run as aborted_budget, not completed', function () {
    $config = new XApiConfig(bearerToken: 't');
    $http = new FakeHttpClient();

    $provider = new XPostProvider(
        $http, $config, new XRequestBuilder($config), new XPostMapper(),
        new XErrorClassifier(), new RetryPolicy(maxAttempts: 1, baseSeconds: 0.001),
        FakeBudgetGuard::exhausted(), new RecordingLogger(),
        static fn (float $s) => null,
    );

    $ledger = new RecordingSearchRunLedger();
    $collector = new TweetCollector($provider, new InMemoryTweetRepository(), $ledger, new RecordingLogger());

    $report = $collector->collect(collectorPlan());

    // Recording this as 'completed' would hide that coverage was cut short by
    // spend rather than by having found everything.
    expect($report->status)->toBe('aborted_budget')
        ->and($report->stopReason)->toBe(StopReason::BudgetRefused)
        ->and($ledger->ops('complete')[0]['status'])->toBe('aborted_budget');
});

// ------------------------------------------------------------------ ledger

it('passes since_id to the ledger for incremental runs', function () {
    [$collector, , $ledger] = makeCollector([
        new HttpResponse(200, collectorFixture('x-search-page-2')),
    ]);

    $collector->collect(collectorPlan(sinceId: '1800000000000000000', maxPages: 1));

    expect($ledger->ops('begin')[0]['since_id'])->toBe('1800000000000000000');
});

it('records cost derived from billable resources, not from post count', function () {
    [$collector, , $ledger] = makeCollector([
        new HttpResponse(200, collectorFixture('x-search-page-1')),
    ]);

    $collector->collect(collectorPlan(maxPages: 1));
    $complete = $ledger->ops('complete')[0];

    // 2 posts + 2 author objects = 4 billable resources.
    expect($complete['billable'])->toBe(4)
        ->and($complete['cost'])->toBe(4 * 0.005);
});
