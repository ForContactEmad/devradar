<?php

declare(strict_types=1);

use DevRadar\Domain\Processing\DuplicateDecision;
use DevRadar\Domain\Processing\ProcessableTweet;
use DevRadar\Domain\Processing\SeenIndex;
use DevRadar\Domain\Processing\TweetDeduplicator;

function tweet(
    int $id,
    string $xId,
    ?string $urlHash = null,
    ?string $fingerprint = null,
    string $posted = '2026-09-08 10:00:00',
): ProcessableTweet {
    return new ProcessableTweet(
        id: $id,
        xTweetId: $xId,
        text: 'text ' . $id,
        primaryUrl: null,
        postedAt: new DateTimeImmutable($posted, new DateTimeZone('UTC')),
        urlHash: $urlHash,
        textFingerprint: $fingerprint,
    );
}

// -------------------------------------------- level 1: the X Tweet ID key

it('treats the same X tweet id as a duplicate', function () {
    $index = new SeenIndex();
    $index->remember(tweet(1, '1800000000000000001'));

    $decision = (new TweetDeduplicator())->decide(tweet(2, '1800000000000000001'), $index);

    expect($decision->isDuplicate)->toBeTrue()
        ->and($decision->matchLevel)->toBe(DuplicateDecision::LEVEL_TWEET_ID)
        ->and($decision->survivorId)->toBe(1)
        ->and($decision->isSamePost())->toBeTrue();
});

it('processes a post surfaced by several queries exactly once', function () {
    // The expected case, not an anomaly: overlapping query families surface
    // the same post, and each surfacing was paid for.
    $batch = [
        tweet(1, '1800000000000000001'),
        tweet(2, '1800000000000000001'),
        tweet(3, '1800000000000000001'),
    ];

    $decisions = (new TweetDeduplicator())->decideBatch($batch, new SeenIndex());

    expect($decisions[1]->isDuplicate)->toBeFalse()
        ->and($decisions[2]->isDuplicate)->toBeTrue()
        ->and($decisions[3]->isDuplicate)->toBeTrue()
        ->and($decisions[3]->survivorId)->toBe(1);
});

it('never marks a post a duplicate of itself', function () {
    $index = new SeenIndex();
    $subject = tweet(1, '1800000000000000001', urlHash: str_repeat('a', 64));
    $index->remember($subject);

    // Without this guard, re-running over already-indexed rows would empty
    // the pipeline.
    expect((new TweetDeduplicator())->decide($subject, $index)->isDuplicate)->toBeFalse();
});

// ------------------------------------------------ level 2: canonical URL

it('collapses different posts that link to the same project', function () {
    $hash = hash('sha256', 'https://github.com/acme/pgplan');
    $batch = [
        tweet(1, '1800000000000000001', urlHash: $hash),
        tweet(2, '1800000000000000002', urlHash: $hash),
    ];

    $decisions = (new TweetDeduplicator())->decideBatch($batch, new SeenIndex());

    // Five accounts announcing one repository is one project, not five.
    expect($decisions[2]->isDuplicate)->toBeTrue()
        ->and($decisions[2]->matchLevel)->toBe(DuplicateDecision::LEVEL_URL)
        ->and($decisions[2]->isSamePost())->toBeFalse();
});

it('keeps posts linking to different projects separate', function () {
    $batch = [
        tweet(1, '1', urlHash: hash('sha256', 'https://github.com/a/one')),
        tweet(2, '2', urlHash: hash('sha256', 'https://github.com/b/two')),
    ];

    $decisions = (new TweetDeduplicator())->decideBatch($batch, new SeenIndex());

    expect($decisions[1]->isDuplicate)->toBeFalse()
        ->and($decisions[2]->isDuplicate)->toBeFalse();
});

// ----------------------------------------------- level 3: text fingerprint

it('collapses identical wording when no url matches', function () {
    $fp = hash('sha256', 'just launched our postgres plan viewer');
    $batch = [
        tweet(1, '1', urlHash: hash('sha256', 'https://a.example/x'), fingerprint: $fp),
        tweet(2, '2', urlHash: hash('sha256', 'https://b.example/y'), fingerprint: $fp),
    ];

    $decisions = (new TweetDeduplicator())->decideBatch($batch, new SeenIndex());

    expect($decisions[2]->isDuplicate)->toBeTrue()
        ->and($decisions[2]->matchLevel)->toBe(DuplicateDecision::LEVEL_TEXT);
});

it('does not merge different posts with merely similar text', function () {
    // Similar is not the same. A wrong merge hides a project entirely, which
    // is worse than showing one twice.
    $batch = [
        tweet(1, '1', fingerprint: hash('sha256', 'launched a new rust cli tool for postgres')),
        tweet(2, '2', fingerprint: hash('sha256', 'launched a new rust cli tool for mysql')),
    ];

    $decisions = (new TweetDeduplicator())->decideBatch($batch, new SeenIndex());

    expect($decisions[2]->isDuplicate)->toBeFalse();
});

// --------------------------------------------------------- missing signals

it('treats posts with no matchable signal as distinct', function () {
    // Two posts that are only a link and a mention have nothing to match on.
    // Collapsing them on absence would merge unrelated projects.
    $batch = [tweet(1, '1'), tweet(2, '2')];

    $decisions = (new TweetDeduplicator())->decideBatch($batch, new SeenIndex());

    expect($decisions[1]->isDuplicate)->toBeFalse()
        ->and($decisions[2]->isDuplicate)->toBeFalse();
});

it('matches on text when one post has no url', function () {
    $fp = hash('sha256', 'shipped a tracing sdk for python today');
    $batch = [
        tweet(1, '1', fingerprint: $fp),
        tweet(2, '2', urlHash: hash('sha256', 'https://x.example/y'), fingerprint: $fp),
    ];

    $decisions = (new TweetDeduplicator())->decideBatch($batch, new SeenIndex());

    expect($decisions[2]->matchLevel)->toBe(DuplicateDecision::LEVEL_TEXT);
});

// ------------------------------------------------------------- precedence

it('prefers the cheapest, most certain match level', function () {
    $index = new SeenIndex();
    $index->remember(tweet(1, '1800000000000000001', urlHash: str_repeat('a', 64), fingerprint: str_repeat('b', 64)));

    $decision = (new TweetDeduplicator())->decide(
        tweet(2, '1800000000000000001', urlHash: str_repeat('a', 64), fingerprint: str_repeat('b', 64)),
        $index,
    );

    // All three would match; identity is reported because it is the only one
    // that is certain.
    expect($decision->matchLevel)->toBe(DuplicateDecision::LEVEL_TWEET_ID);
});

it('does not index duplicates, so no chains form', function () {
    $hash = str_repeat('c', 64);
    $batch = [
        tweet(1, '1', urlHash: $hash),
        tweet(2, '2', urlHash: $hash),
        tweet(3, '3', urlHash: $hash),
    ];

    $decisions = (new TweetDeduplicator())->decideBatch($batch, new SeenIndex());

    // All duplicates point at the original survivor, never at each other.
    expect($decisions[2]->survivorId)->toBe(1)
        ->and($decisions[3]->survivorId)->toBe(1);
});

it('matches a batch against survivors already stored', function () {
    $hash = str_repeat('d', 64);
    $index = new SeenIndex();
    $index->rememberUrlHash($hash, 99);

    $decisions = (new TweetDeduplicator())->decideBatch([tweet(5, '5', urlHash: $hash)], $index);

    expect($decisions[5]->isDuplicate)->toBeTrue()
        ->and($decisions[5]->survivorId)->toBe(99);
});

it('handles an empty batch', function () {
    expect((new TweetDeduplicator())->decideBatch([], new SeenIndex()))->toHaveCount(0);
});
