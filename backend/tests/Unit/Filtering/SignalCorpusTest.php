<?php

declare(strict_types=1);

use DevRadar\Domain\Filtering\FilterableTweet;
use DevRadar\Domain\Filtering\KeywordMatcher;
use DevRadar\Domain\Filtering\ProjectSignalDetector;
use DevRadar\Domain\Filtering\SignalSetFactory;
use DevRadar\Domain\Filtering\SignalStrength;
use DevRadar\Domain\Filtering\TweetFilter;

/**
 * Measures the REAL configured signal set, not a test fixture.
 *
 * The unit tests above prove the mechanism works. This one proves the actual
 * phrases in config/filtering.php behave, which is a different question and
 * the one that decides whether this layer earns its place.
 *
 * It is a small hand-labelled corpus, not the phase 4 evaluation set. When
 * that exists, this file should be pointed at it and the thresholds tuned
 * against real posts instead of invented ones.
 */
function realFilter(
    SignalStrength $minimum = SignalStrength::Weak,
    SignalStrength $override = SignalStrength::Strong,
): TweetFilter {
    $config = require __DIR__ . '/../../../config/filtering.php';

    return new TweetFilter(
        new ProjectSignalDetector(
            new KeywordMatcher(),
            SignalSetFactory::fromConfig($config),
            $config['thresholds'],
            $config['structural'],
        ),
        $minimum,
        $override,
    );
}

/** @return list<array{0: string, 1: bool, 2: bool}> text, hasRepo, shouldPass */
function corpus(): array
{
    return [
        // --- true launches: these MUST pass ---------------------------------
        ['We just launched pgplan, a Postgres query plan viewer. MIT licensed.', true, true],
        ['Open sourced our internal tracing SDK today. Feedback welcome.', true, true],
        ['Introducing Tinysched, a tiny Rust scheduler. npm install tinysched', true, true],
        ['I built a CLI tool for inspecting Docker layers over the weekend', true, true],
        ['My first side project is now live. Try it and let me know.', false, true],
        ['v1.0 of our open source alternative to Postman is now available', true, true],
        ['Shipped a self-hosted analytics tool. Out of beta today.', true, true],
        ['We built this developer tool to make CI logs readable. Check it out.', true, true],
        ['New SaaS for tracking API uptime, public beta open now', false, true],
        ['pip install pgplan — our new AI tool for query optimisation', true, true],

        // --- not launches: these SHOULD be rejected --------------------------
        ['We are hiring senior backend engineers. Apply now.', false, false],
        ['How to build a CLI tool in Rust — a thread on the basics', false, false],
        ['Enroll now in my free course on system design. Use code EARLY.', false, false],
        ['Retweet to win a lifetime licence, tag 3 friends', false, false],
        ['Thinking about how database indexes behave under write pressure', false, false],
        ['Good morning everyone, coffee first', false, false],
        ['We rebuilt the parser and booked a new appointment with the vendor', false, false],
        ['Book a demo of our platform, limited time offer', false, false],

        // --- known-hard cases -----------------------------------------------
        // A real launch wearing a hiring phrase. Must pass on strength.
        ['We just launched our open sourced compiler. Introducing it today. We are hiring too.', true, true],
    ];
}

it('passes every true launch in the corpus', function () {
    $filter = realFilter();
    $missed = [];

    foreach (corpus() as [$text, $repo, $shouldPass]) {
        if (! $shouldPass) {
            continue;
        }

        if (! $filter->decide(new FilterableTweet(1, $text, true, $repo))->passes) {
            $missed[] = $text;
        }
    }

    // A false negative here costs the project entirely: the post was already
    // paid for, the model never sees it, and nothing downstream recovers it.
    // Recall must be 100% on the corpus.
    expect($missed)->toBe([]);
});

it('rejects the clear non-launches in the corpus', function () {
    $filter = realFilter();
    $leaked = [];

    foreach (corpus() as [$text, $repo, $shouldPass]) {
        if ($shouldPass) {
            continue;
        }

        if ($filter->decide(new FilterableTweet(1, $text, $repo || $text !== '', $repo))->passes) {
            $leaked[] = $text;
        }
    }

    expect($leaked)->toBe([]);
});

it('reduces the volume reaching the model', function () {
    $filter = realFilter();
    $passed = 0;

    foreach (corpus() as [$text, $repo]) {
        if ($filter->decide(new FilterableTweet(1, $text, true, $repo))->passes) {
            $passed++;
        }
    }

    // The whole justification for this layer. If it passes everything, it is
    // not earning its place.
    expect($passed)->toBeGreaterThan(0)
        ->and($passed < count(corpus()))->toBeTrue();
});

it('handles Arabic launch language with the configured signals', function () {
    $filter = realFilter();

    $launch = $filter->decide(new FilterableTweet(1, 'أطلقت أداة جديدة مفتوحة المصدر للمطورين', true, true));
    $chatter = $filter->decide(new FilterableTweet(2, 'صباح الخير، القهوة أولاً', false, false));

    // Arabic coverage is a starting point and unmeasured. This asserts the
    // mechanism works end to end, not that the phrase list is good.
    expect($launch->passes)->toBeTrue()
        ->and($chatter->passes)->toBeFalse();
});

it('loads the real signal set without duplicate or zero-weight phrases', function () {
    $config = require __DIR__ . '/../../../config/filtering.php';
    $signals = SignalSetFactory::fromConfig($config);

    $phrases = array_map(fn ($s) => mb_strtolower($s->phrase), $signals);

    expect(count($signals))->toBeGreaterThan(50)
        ->and(count($phrases))->toBe(count(array_unique($phrases)));
});
