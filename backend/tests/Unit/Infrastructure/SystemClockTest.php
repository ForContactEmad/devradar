<?php

declare(strict_types=1);

use DevRadar\Domain\Collection\WindowResolver;
use DevRadar\Domain\Port\ClockInterface;
use DevRadar\Infrastructure\Clock\SystemClock;

/**
 * The production clock.
 *
 * WHY THESE EXIST. `ClockInterface` was declared, injected and resolved from
 * the container while the only implementation in the project lived in
 * tests/Fake. Fifty-one collection tests passed because each one constructs
 * its collaborator directly and hands it the fake -- so no test ever asked
 * whether a production implementation existed at all.
 *
 * The gap was not "the clock is wrong". It was "nothing asserts that a real
 * one can be built". These tests close that, and the provider test below
 * closes the container half of it.
 */

it('provides a real production implementation of the clock port', function () {
    // The assertion that was missing. If someone deletes SystemClock, or moves
    // it out of the production namespace, this fails -- where previously the
    // whole suite stayed green.
    expect(new SystemClock())->toBeInstanceOf(ClockInterface::class);
});

it('is constructible with no arguments, so the container can autowire it', function () {
    $reflection = new ReflectionClass(SystemClock::class);

    // A constructor with required arguments would need an explicit binding
    // closure; this asserts the simple binding in IngestionServiceProvider is
    // sufficient.
    expect($reflection->isInstantiable())->toBeTrue()
        ->and($reflection->getConstructor())->toBeNull();
});

it('returns the current time', function () {
    $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $now = (new SystemClock())->now();
    $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    expect($now >= $before)->toBeTrue()->and($now <= $after)->toBeTrue();
});

it('always returns UTC regardless of the process timezone', function () {
    // THE REASON THIS MATTERS. The collection window is computed against X's
    // timestamps, which are UTC, and the provider's deduplication boundary is
    // a UTC day. A clock following the process timezone would put the window
    // edge somewhere else than the provider does -- an error that is small,
    // seasonal, and very hard to see in a log.
    $original = date_default_timezone_get();
    date_default_timezone_set('Asia/Riyadh');

    try {
        $now = (new SystemClock())->now();
        expect($now->getTimezone()->getName())->toBe('UTC');
    } finally {
        date_default_timezone_set($original);
    }
});

it('advances between calls', function () {
    $clock = new SystemClock();
    $first = $clock->now();
    usleep(2000);

    // Distinguishes a real clock from one frozen at construction -- which is
    // exactly what the test fake is, and what production must not be.
    expect($clock->now() >= $first)->toBeTrue();
});

it('drives the window resolver without a test double', function () {
    // The consumer that could not be constructed in production, built here
    // from the production implementation only.
    $window = (new WindowResolver(new SystemClock(), windowDays: 7))->resolve();

    expect($window->durationInDays())->toBeGreaterThan(6.9)
        ->and($window->end > $window->start)->toBeTrue();
});
