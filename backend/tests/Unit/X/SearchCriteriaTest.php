<?php

declare(strict_types=1);

use DevRadar\Domain\Ingestion\SearchCriteria;

it('rejects sinceId together with startTime', function () {
    // X rejects requests carrying both. Catching it here saves a round trip
    // and an error branch.
    expect(fn () => new SearchCriteria('x', sinceId: '123', startTime: new DateTimeImmutable()))
        ->toThrow(InvalidArgumentException::class, 'not both');
});

it('rejects untilId together with endTime', function () {
    expect(fn () => new SearchCriteria('x', untilId: '123', endTime: new DateTimeImmutable()))
        ->toThrow(InvalidArgumentException::class, 'not both');
});

it('rejects a non-numeric post id', function () {
    expect(fn () => new SearchCriteria('x', sinceId: 'abc'))
        ->toThrow(InvalidArgumentException::class, 'numeric post id');
});

it('rejects a post id longer than 19 digits', function () {
    expect(fn () => new SearchCriteria('x', sinceId: str_repeat('9', 20)))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an empty query', function () {
    expect(fn () => new SearchCriteria('   '))->toThrow(InvalidArgumentException::class);
});

it('enforces the provider page size bounds', function () {
    expect(fn () => new SearchCriteria('x', pageSize: 9))->toThrow(InvalidArgumentException::class);
    expect(fn () => new SearchCriteria('x', pageSize: 101))->toThrow(InvalidArgumentException::class);
    expect((new SearchCriteria('x', pageSize: 100))->pageSize)->toBe(100);
});

it('rejects an unknown sort order', function () {
    expect(fn () => new SearchCriteria('x', sortOrder: 'random'))->toThrow(InvalidArgumentException::class);
    expect((new SearchCriteria('x', sortOrder: 'recency'))->sortOrder)->toBe('recency');
});

it('defaults to the cost ceilings, not to unlimited', function () {
    $criteria = new SearchCriteria('x');

    // An unbounded default here is how a runaway pagination loop becomes a
    // large bill. The defaults are deliberately conservative.
    expect($criteria->maxPosts)->toBe(300)
        ->and($criteria->maxPages)->toBe(10);
});
