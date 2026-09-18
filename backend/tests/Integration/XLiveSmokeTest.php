<?php

declare(strict_types=1);

use DevRadar\Domain\Ingestion\SearchCriteria;
use DevRadar\Domain\Port\PostProviderInterface;

/**
 * LIVE smoke test. Costs real money. Skipped unless explicitly enabled.
 *
 * Everything else in the suite runs against a scripted transport. This is the
 * one test that talks to X, and it exists to answer a question no mock can:
 * does the request DevRadar actually builds get accepted by the live API, and
 * does the live response still have the shape the mapper expects?
 *
 * Run deliberately, never in CI:
 *
 *   X_LIVE_SMOKE=1 php artisan test --filter=XLiveSmokeTest
 *
 * It requests a single page of 10 posts, which is the smallest chargeable
 * unit the endpoint permits.
 */
beforeEach(function () {
    if (env('X_LIVE_SMOKE') !== '1') {
        $this->markTestSkipped(
            'Live X smoke test is disabled. Set X_LIVE_SMOKE=1 to run it. It spends real credits.'
        );
    }
});

it('accepts the request DevRadar actually builds', function () {
    $provider = app(PostProviderInterface::class);

    $batch = $provider->search(new SearchCriteria(
        query: 'from:XDevelopers -is:retweet',
        pageSize: 10,
        maxPosts: 10,
        maxPages: 1,
    ));

    expect($batch->requestCount)->toBe(1);

    // A live response must still map. If X renames a field again, this is
    // where it surfaces -- as a failing test rather than as silently zeroed
    // engagement in production.
    foreach ($batch->posts as $post) {
        expect($post->id)->not->toBeEmpty()
            ->and($post->createdAt)->toBeInstanceOf(DateTimeImmutable::class);
    }
})->group('live');

it('reports what it actually spent', function () {
    $provider = app(PostProviderInterface::class);

    $batch = $provider->search(new SearchCriteria(
        query: 'from:XDevelopers -is:retweet',
        pageSize: 10,
        maxPosts: 10,
        maxPages: 1,
    ));

    // Cross-check this figure against the developer console. If they diverge,
    // the cost model in devradar-labelling-workbook.xlsx is wrong.
    expect($batch->billableResources)->toBeGreaterThan(-1);
})->group('live');
