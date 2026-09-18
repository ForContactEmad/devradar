<?php

declare(strict_types=1);

use DevRadar\Application\Processing\DeduplicationRunner;
use DevRadar\Application\Processing\NormalizationRunner;
use DevRadar\Domain\Processing\ProcessableTweet;
use DevRadar\Domain\Processing\TweetDeduplicator;
use DevRadar\Domain\Processing\TweetNormalizer;
use Tests\Fake\InMemoryProcessingRepository;
use Tests\Fake\RecordingLogger;

function rawTweet(int $id, string $xId, string $text, ?string $url): ProcessableTweet
{
    return new ProcessableTweet(
        id: $id,
        xTweetId: $xId,
        text: $text,
        primaryUrl: $url,
        postedAt: new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('UTC')),
        lang: 'en',
        authorId: 1,
    );
}

// ---------------------------------------------------------- normalization

it('normalizes a batch and records rejections separately', function () {
    $repo = new InMemoryProcessingRepository();
    $repo->seedRaw([
        rawTweet(1, '1', 'Just launched our postgres plan viewer', 'https://github.com/acme/pgplan'),
        rawTweet(2, '2', 'a thought with no link at all here', null),
        rawTweet(3, '3', "   \u{200B}   ", 'https://github.com/acme/x'),
    ]);

    $stats = (new NormalizationRunner($repo, new TweetNormalizer(), new RecordingLogger()))->run();

    expect($stats['claimed'])->toBe(3)
        ->and($stats['normalized'])->toBe(1)
        ->and($stats['rejected'])->toBe(2)
        ->and($stats['failed'])->toBe(0);
});

it('logs the reject reason histogram that pre-filter rules get written from', function () {
    $repo = new InMemoryProcessingRepository();
    $repo->seedRaw([
        rawTweet(1, '1', 'no link on this one at all', null),
        rawTweet(2, '2', 'nor on this one either honestly', null),
    ]);

    $logger = new RecordingLogger();
    (new NormalizationRunner($repo, new TweetNormalizer(), $logger))->run();

    $record = $logger->withMessage('processing.normalize.complete')[0];

    expect($record['context']['reject_reasons']['no-link'])->toBe(2);
});

it('does nothing on an empty queue', function () {
    $repo = new InMemoryProcessingRepository();
    $stats = (new NormalizationRunner($repo, new TweetNormalizer(), new RecordingLogger()))->run();

    expect($stats['claimed'])->toBe(0);
});

// ---------------------------------------------------------- deduplication

it('collapses the same post surfaced by several queries', function () {
    $repo = new InMemoryProcessingRepository();

    // The unique constraint prevents this at storage time, but the pipeline
    // must not depend on that alone -- a re-run or a backfill can present the
    // same id again.
    $repo->seedNormalized([
        new ProcessableTweet(1, '1800000000000000001', 'a', null, new DateTimeImmutable('2026-09-08 09:00:00')),
        new ProcessableTweet(2, '1800000000000000001', 'a', null, new DateTimeImmutable('2026-09-08 10:00:00')),
    ]);

    $stats = (new DeduplicationRunner($repo, new TweetDeduplicator(), new RecordingLogger()))->run();

    expect($stats['unique'])->toBe(1)
        ->and($stats['duplicates'])->toBe(1)
        ->and($repo->duplicates[2]['survivor'])->toBe(1)
        ->and($repo->duplicates[2]['level'])->toBe('tweet_id');
});

it('collapses different posts about one project', function () {
    $hash = hash('sha256', 'https://github.com/acme/pgplan');
    $repo = new InMemoryProcessingRepository();
    $repo->seedNormalized([
        new ProcessableTweet(1, '1', 'a', null, new DateTimeImmutable('2026-09-08 09:00:00'), urlHash: $hash),
        new ProcessableTweet(2, '2', 'b', null, new DateTimeImmutable('2026-09-08 10:00:00'), urlHash: $hash),
    ]);

    (new DeduplicationRunner($repo, new TweetDeduplicator(), new RecordingLogger()))->run();

    expect($repo->duplicates[2]['level'])->toBe('canonical_url')
        ->and($repo->deduplicated)->toBe([1]);
});

it('reports the two kinds of duplicate separately', function () {
    $hash = str_repeat('a', 64);
    $repo = new InMemoryProcessingRepository();
    $repo->seedNormalized([
        new ProcessableTweet(1, '100', 'a', null, new DateTimeImmutable('2026-09-08 09:00:00'), urlHash: $hash),
        new ProcessableTweet(2, '100', 'a', null, new DateTimeImmutable('2026-09-08 09:30:00')),
        new ProcessableTweet(3, '300', 'c', null, new DateTimeImmutable('2026-09-08 10:00:00'), urlHash: $hash),
    ]);

    $logger = new RecordingLogger();
    (new DeduplicationRunner($repo, new TweetDeduplicator(), $logger))->run();

    $context = $logger->withMessage('processing.deduplicate.complete')[0]['context'];

    // "Queries overlap and money was wasted" and "one project has several
    // sources" are different findings and must not be summed into one number.
    expect($context['same_post'])->toBe(1)
        ->and($context['same_url'])->toBe(1)
        ->and($context['unique'])->toBe(1);
});

it('matches a batch against survivors from earlier cycles', function () {
    $hash = str_repeat('b', 64);
    $repo = new InMemoryProcessingRepository();
    $repo->preExisting->rememberUrlHash($hash, 99);
    $repo->seedNormalized([
        new ProcessableTweet(5, '5', 'x', null, new DateTimeImmutable('2026-09-08 10:00:00'), urlHash: $hash),
    ]);

    (new DeduplicationRunner($repo, new TweetDeduplicator(), new RecordingLogger()))->run();

    expect($repo->duplicates[5]['survivor'])->toBe(99);
});

// ------------------------------------------------------------- end to end

it('runs normalization then deduplication over one collected batch', function () {
    $repo = new InMemoryProcessingRepository();
    $repo->seedRaw([
        rawTweet(1, '1', 'Just launched pgplan, a postgres plan viewer', 'https://github.com/acme/pgplan?utm_source=twitter'),
        rawTweet(2, '2', 'Everyone should see this postgres tool',      'https://www.github.com/ACME/PgPlan/blob/main/README.md'),
        rawTweet(3, '3', 'Shipped a vue component kit for forms today', 'https://github.com/vue/kit'),
    ]);

    (new NormalizationRunner($repo, new TweetNormalizer(), new RecordingLogger()))->run();
    $stats = (new DeduplicationRunner($repo, new TweetDeduplicator(), new RecordingLogger()))->run();

    // Posts 1 and 2 are one project despite entirely different wording,
    // because canonicalisation reduced both links to the same repository.
    expect($stats['unique'])->toBe(2)
        ->and($stats['duplicates'])->toBe(1)
        ->and($repo->duplicates[2]['level'])->toBe('canonical_url')
        ->and($repo->duplicates[2]['survivor'])->toBe(1);
});
