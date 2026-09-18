<?php

declare(strict_types=1);

use DevRadar\Domain\Collection\QueryComposer;

it('quotes multi-word signals and leaves single words bare', function () {
    $expressions = (new QueryComposer())->compose(['just launched', 'introducing']);

    expect($expressions)->toHaveCount(1)
        ->and($expressions[0])->toContain('"just launched"')
        ->and($expressions[0])->toContain('OR introducing');
});

it('passes operators through untouched', function () {
    $expressions = (new QueryComposer())->compose(['url:"github.com"', 'open source']);

    expect($expressions[0])->toContain('url:"github.com"');
});

it('appends modifiers to every chunk', function () {
    $composer = new QueryComposer(120);
    $signals = array_map(fn ($i) => "signal phrase number {$i}", range(1, 12));

    $expressions = $composer->compose($signals, ['-is:retweet', 'has:links']);

    expect(count($expressions))->toBeGreaterThan(1);

    foreach ($expressions as $expression) {
        expect($expression)->toContain('-is:retweet')
            ->and($expression)->toContain('has:links');
    }
});

it('splits a long signal list instead of truncating it', function () {
    $composer = new QueryComposer(100);
    $signals = array_map(fn ($i) => "phrase number {$i}", range(1, 20));

    $expressions = $composer->compose($signals, ['-is:retweet']);

    // Truncating would silently change what is matched, and the shortened
    // query would still cost money to run.
    $joined = implode(' ', $expressions);

    foreach ($signals as $signal) {
        expect($joined)->toContain($signal);
    }
});

it('keeps every chunk within the limit', function () {
    $composer = new QueryComposer(100);
    $signals = array_map(fn ($i) => "phrase number {$i}", range(1, 20));

    foreach ($composer->compose($signals, ['-is:retweet', 'lang:en']) as $expression) {
        expect(mb_strlen($expression) <= 100)->toBeTrue();
    }
});

it('refuses a single signal that cannot fit even alone', function () {
    $composer = new QueryComposer(20);

    // Splitting cannot help here, so saying so beats emitting a query that
    // will be rejected on arrival.
    expect(fn () => $composer->compose([str_repeat('long phrase ', 10)]))
        ->toThrow(InvalidArgumentException::class, 'cannot fit');
});

it('rejects an empty signal list', function () {
    expect(fn () => (new QueryComposer())->compose([]))->toThrow(InvalidArgumentException::class);
});

it('numbers the definitions when a group splits', function () {
    $composer = new QueryComposer(90);
    $signals = array_map(fn ($i) => "phrase number {$i}", range(1, 12));

    $definitions = $composer->defineGroup('devtools', 'D', $signals, ['-is:retweet']);

    // Per-chunk naming lets the ledger attribute cost and yield to the exact
    // chunk. Without it, a group where one chunk carries all the signal looks
    // like a mediocre group.
    expect(count($definitions))->toBeGreaterThan(1)
        ->and($definitions[0]->name)->toBe('devtools-1')
        ->and($definitions[1]->name)->toBe('devtools-2')
        ->and($definitions[0]->family)->toBe('D');
});

it('leaves the name unsuffixed when a group fits in one query', function () {
    $definitions = (new QueryComposer())->defineGroup('release', 'B', ['v1.0', 'now available']);

    expect($definitions)->toHaveCount(1)
        ->and($definitions[0]->name)->toBe('release');
});
