<?php

declare(strict_types=1);

use DevRadar\Domain\Processing\ProcessableTweet;
use DevRadar\Domain\Processing\TweetNormalizer;

function subject(string $text, ?string $url = 'https://github.com/acme/tool'): ProcessableTweet
{
    return new ProcessableTweet(
        id: 1,
        xTweetId: '1800000000000000001',
        text: $text,
        primaryUrl: $url,
        postedAt: new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('UTC')),
        lang: 'en',
        authorId: 7,
    );
}

it('derives every field from one post', function () {
    $result = (new TweetNormalizer())->normalize(
        subject('Just  launched #OpenSource pgplan with @alice https://t.co/abc'),
    );

    expect($result->normalizedText)->toBe('Just launched #OpenSource pgplan with @alice https://t.co/abc')
        ->and($result->canonicalUrl)->toBe('https://github.com/acme/tool')
        ->and($result->urlHash)->not->toBeNull()
        ->and($result->textFingerprint)->not->toBeNull()
        ->and($result->hashtags)->toBe(['opensource'])
        ->and($result->mentions)->toBe(['alice'])
        ->and($result->isRejected())->toBeFalse();
});

it('never modifies the original text', function () {
    $original = "Just  launched\n\nour tool";
    $tweet = subject($original);

    (new TweetNormalizer())->normalize($tweet);

    // The original is the only copy that can be re-derived from, so it must
    // survive every rule change.
    expect($tweet->text)->toBe($original);
});

it('rejects a post with no usable text', function () {
    $result = (new TweetNormalizer())->normalize(subject("   \u{200B}  "));

    expect($result->isEmpty)->toBeTrue()
        ->and($result->rejectReason)->toBe('no-text');
});

it('rejects a post with no link', function () {
    $result = (new TweetNormalizer())->normalize(subject('a genuinely interesting thought about software', null));

    expect($result->rejectReason)->toBe('no-link');
});

it('rejects a post whose link was never unwound', function () {
    $result = (new TweetNormalizer())->normalize(
        subject('check out this new tool we built today', 'https://t.co/abc123'),
    );

    // An unresolved shortener has no identity: it can neither be judged nor
    // deduplicated, and treating it as canonical would let two different
    // pages collide.
    expect($result->rejectReason)->toBe('unresolved-link');
});

it('can be configured to accept posts without links', function () {
    $normalizer = new TweetNormalizer(requireLink: false);
    $result = $normalizer->normalize(subject('a genuinely interesting thought about software', null));

    expect($result->isRejected())->toBeFalse();
});

it('records a rejection rather than discarding the post', function () {
    $result = (new TweetNormalizer())->normalize(subject('short', null));

    // Rejection data is the raw material for the free pre-filter rules;
    // discarding it discards the measurement.
    expect($result->normalizedText)->toBe('short')
        ->and($result->rejectReason)->toBe('no-link');
});

it('reports when nothing is left to match on', function () {
    $result = (new TweetNormalizer(requireLink: false))->normalize(subject('@bob https://t.co/x', null));

    expect($result->hasNoMatchableSignal())->toBeTrue();
});

it('gives two posts about one repository the same url hash', function () {
    $normalizer = new TweetNormalizer();

    $a = $normalizer->normalize(subject('Look at this', 'https://github.com/acme/tool?utm_source=twitter'));
    $b = $normalizer->normalize(subject('Different words entirely', 'https://www.github.com/ACME/Tool/blob/main/README.md'));

    expect($a->urlHash)->toBe($b->urlHash);
});

it('handles a post with missing metadata', function () {
    $tweet = new ProcessableTweet(
        id: 2,
        xTweetId: '1800000000000000002',
        text: 'shipped a small go scheduler this weekend',
        primaryUrl: null,
        postedAt: new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('UTC')),
        lang: null,
        authorId: null,
    );

    $result = (new TweetNormalizer(requireLink: false))->normalize($tweet);

    // No language, no author, no URL: still normalizable and still
    // fingerprintable.
    expect($result->normalizedText)->not->toBeEmpty()
        ->and($result->textFingerprint)->not->toBeNull()
        ->and($result->canonicalUrl)->toBeNull();
});

it('normalizes an Arabic post end to end', function () {
    $result = (new TweetNormalizer())->normalize(
        subject('أطلقت أداة جديدة مفتوحة المصدر لتحليل استعلامات قواعد البيانات'),
    );

    expect($result->isEmpty)->toBeFalse()
        ->and($result->isRejected())->toBeFalse()
        ->and($result->textFingerprint)->not->toBeNull();
});
