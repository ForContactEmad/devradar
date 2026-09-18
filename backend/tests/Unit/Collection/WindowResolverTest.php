<?php

declare(strict_types=1);

use DevRadar\Domain\Collection\WindowResolver;
use Tests\Fake\FixedClock;

it('resolves a rolling seven-day window from the clock', function () {
    $resolver = new WindowResolver(new FixedClock('2026-09-09 12:00:00'), windowDays: 7);
    $window = $resolver->resolve();

    // Strictly under seven days, by design: the margins keep the request
    // inside the provider's horizon. Asserting the property rather than a
    // rounded figure, because the exact value depends on both margins.
    expect($window->durationInDays() < 7.0)->toBeTrue()
        ->and($window->durationInDays() > 6.99)->toBeTrue();
});

it('moves with the clock rather than using a fixed date', function () {
    $first = (new WindowResolver(new FixedClock('2026-09-09 12:00:00')))->resolve();
    $second = (new WindowResolver(new FixedClock('2026-09-16 12:00:00')))->resolve();

    // A hard-coded date in a rolling-window product is a bug that only
    // appears a week later, when the feed silently stops moving.
    expect($first->start->format('Y-m-d'))->toBe('2026-09-02')
        ->and($second->start->format('Y-m-d'))->toBe('2026-09-09');
});

it('keeps the start just inside the seven-day horizon', function () {
    $window = (new WindowResolver(new FixedClock('2026-09-09 12:00:00'), startMarginSeconds: 300))->resolve();

    // Exactly seven days ago would be 2026-09-02 12:00:00. A request whose
    // start has drifted a second past the boundary is rejected outright, and
    // a rejected request still costs a round trip.
    expect($window->start->format('Y-m-d H:i:s'))->toBe('2026-09-02 12:05:00');
});

it('holds the end slightly back from now', function () {
    $window = (new WindowResolver(new FixedClock('2026-09-09 12:00:00'), endMarginSeconds: 30))->resolve();

    // The provider's index is not instantly consistent; asking for the last
    // few seconds returns nothing useful.
    expect($window->end->format('Y-m-d H:i:s'))->toBe('2026-09-09 11:59:30');
});

it('honours a configured window length', function () {
    $window = (new WindowResolver(new FixedClock('2026-09-09 12:00:00'), windowDays: 3))->resolve();

    expect($window->start->format('Y-m-d'))->toBe('2026-09-06');
});

it('reports whether a moment falls inside the window', function () {
    $window = (new WindowResolver(new FixedClock('2026-09-09 12:00:00')))->resolve();

    expect($window->contains(new DateTimeImmutable('2026-09-05 00:00:00', new DateTimeZone('UTC'))))->toBeTrue()
        ->and($window->contains(new DateTimeImmutable('2026-08-01 00:00:00', new DateTimeZone('UTC'))))->toBeFalse();
});
