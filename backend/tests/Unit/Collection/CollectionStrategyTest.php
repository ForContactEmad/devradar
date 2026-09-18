<?php

declare(strict_types=1);

use DevRadar\Application\Collection\RecentWindowStrategy;
use DevRadar\Domain\Collection\SearchQueryDefinition;
use DevRadar\Domain\Collection\WindowResolver;
use Tests\Fake\FixedClock;
use Tests\Fake\InMemoryTweetRepository;
use Tests\Fake\StaticSearchQuerySource;

function strategyWith(array $definitions, ?InMemoryTweetRepository $repo = null): RecentWindowStrategy
{
    return new RecentWindowStrategy(
        queries: new StaticSearchQuerySource($definitions),
        windows: new WindowResolver(new FixedClock('2026-09-09 12:00:00')),
        repository: $repo ?? new InMemoryTweetRepository(),
    );
}

function definition(int $id, string $name): SearchQueryDefinition
{
    return new SearchQueryDefinition(id: $id, name: $name, family: 'A', expression: "({$name})");
}

it('plans one search per active query', function () {
    $plans = strategyWith([definition(1, 'repo-launch'), definition(2, 'release'), definition(3, 'devtools')])->plan();

    expect($plans)->toHaveCount(3)
        ->and($plans[0]->definition->name)->toBe('repo-launch')
        ->and($plans[2]->criteria->query)->toBe('(devtools)');
});

it('uses one window for the whole cycle', function () {
    $plans = strategyWith([definition(1, 'a'), definition(2, 'b')])->plan();

    // Per-query windows would let two queries in the same run cover different
    // spans, making their yields incomparable.
    expect($plans[0]->window->start)->toEqual($plans[1]->window->start)
        ->and($plans[0]->window->end)->toEqual($plans[1]->window->end);
});

it('uses the window start for a query with no history', function () {
    $plans = strategyWith([definition(1, 'fresh')])->plan();

    expect($plans[0]->criteria->sinceId)->toBeNull()
        ->and($plans[0]->criteria->startTime->format('Y-m-d'))->toBe('2026-09-02');
});

it('switches to since_id once a query has history', function () {
    $repo = new InMemoryTweetRepository();
    $repo->highWaterMarks[1] = '1800000000000000123';

    $plans = strategyWith([definition(1, 'seen-before')], $repo)->plan();

    // Re-fetching a post already held costs exactly as much as fetching a new
    // one. The only free page is the one never requested.
    expect($plans[0]->criteria->sinceId)->toBe('1800000000000000123');
});

it('never sends both since_id and start_time', function () {
    $repo = new InMemoryTweetRepository();
    $repo->highWaterMarks[1] = '1800000000000000123';

    $plans = strategyWith([definition(1, 'seen-before')], $repo)->plan();

    // The provider rejects requests carrying both, and a rejected request is
    // still a round trip.
    expect($plans[0]->criteria->startTime)->toBeNull()
        ->and($plans[0]->criteria->endTime)->toBeNull();
});

it('tracks history per query, not globally', function () {
    $repo = new InMemoryTweetRepository();
    $repo->highWaterMarks[1] = '1800000000000000123';

    $plans = strategyWith([definition(1, 'seen'), definition(2, 'unseen')], $repo)->plan();

    expect($plans[0]->criteria->sinceId)->toBe('1800000000000000123')
        ->and($plans[1]->criteria->sinceId)->toBeNull();
});

it('plans nothing when no query is active', function () {
    expect(strategyWith([])->plan())->toHaveCount(0);
});

it('caps page size at the query definition maximum', function () {
    $strategy = new RecentWindowStrategy(
        queries: new StaticSearchQuerySource([
            new SearchQueryDefinition(id: 1, name: 'small', family: 'A', expression: '(x)', maxResults: 25),
        ]),
        windows: new WindowResolver(new FixedClock()),
        repository: new InMemoryTweetRepository(),
        pageSize: 100,
    );

    expect($strategy->plan()[0]->criteria->pageSize)->toBe(25);
});
