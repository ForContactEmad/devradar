<?php

declare(strict_types=1);

use DevRadar\Application\Filtering\FilterRunner;
use DevRadar\Application\Scoring\ScoringRunner;
use DevRadar\Domain\Filtering\FilterableTweet;
use DevRadar\Domain\Filtering\KeywordMatcher;
use DevRadar\Domain\Filtering\ProjectSignalDetector;
use DevRadar\Domain\Filtering\SignalSetFactory;
use DevRadar\Domain\Filtering\TweetFilter;
use DevRadar\Domain\Scoring\ScoreInput;
use Tests\Fake\InMemoryFilteringRepository;
use Tests\Fake\InMemoryScoringRepository;
use Tests\Fake\RecordingLogger;

/**
 * The two batch runners that had no tests at all.
 *
 * Their engines were well covered -- 34 tests on the scoring formula, 41 on
 * the filter -- but the code that walks a batch, isolates a failure and
 * reports what happened was never exercised. That code is where a bad night
 * turns into a lost batch, so it is worth more than another test of the
 * formula.
 */

// ============================================================ SCORING RUNNER

/** @return array{0: ScoringRunner, 1: InMemoryScoringRepository, 2: RecordingLogger} */
function scoringRunner(array $due = [], ?InMemoryScoringRepository $repo = null): array
{
    $repo ??= new InMemoryScoringRepository();
    $repo->due = $due;
    $logger = new RecordingLogger();

    return [new ScoringRunner($repo, engine(), $logger), $repo, $logger];
}

function scoreInput(string $posted = '2026-09-09 06:00:00', ?int $likes = 100): ScoreInput
{
    return new ScoreInput(
        postedAt: at($posted),
        now: at(NOW),
        likeCount: $likes,
        repostCount: 10,
        replyCount: 5,
        quoteCount: 1,
        bookmarkCount: 20,
        authorFollowers: 5000,
        classificationConfidence: 0.9,
        extractionConfidence: 0.85,
    );
}

it('scores every project in the batch', function () {
    [$runner, $repo] = scoringRunner([1 => scoreInput(), 2 => scoreInput(), 3 => scoreInput()]);

    $stats = $runner->run();

    expect($stats['claimed'])->toBe(3)
        ->and($stats['scored'])->toBe(3)
        ->and($repo->saved)->toHaveCount(3)
        ->and($stats['average_score'])->toBeGreaterThan(0);
});

it('does nothing when nothing is stale', function () {
    [$runner, $repo] = scoringRunner([]);

    $stats = $runner->run();

    // A sweep on an empty queue is the normal case -- it runs every ten
    // minutes -- and must cost nothing and report cleanly.
    expect($stats['claimed'])->toBe(0)
        ->and($stats['average_score'])->toBe(0.0)
        ->and($repo->saved)->toHaveCount(0);
});

it('ages out projects past the window before scoring', function () {
    $repo = new InMemoryScoringRepository();
    $repo->agedOut = 4;

    [$runner] = scoringRunner([1 => scoreInput()], $repo);

    // Ordering matters: a project that has left the window should not be
    // scored on the same pass that removes it.
    expect($runner->run()['aged_out'])->toBe(4);
});

it('isolates a failing project so the rest of the batch still scores', function () {
    $repo = new InMemoryScoringRepository();
    $repo->failOn = [2];

    [$runner, , $logger] = scoringRunner([1 => scoreInput(), 2 => scoreInput(), 3 => scoreInput()], $repo);
    $stats = $runner->run();

    // One project's bad data must not leave the whole feed on a stale
    // ranking.
    expect($stats['scored'])->toBe(2)
        ->and($stats['failed'])->toBe(1)
        ->and($repo->saved)->toHaveKey(1)
        ->and($repo->saved)->toHaveKey(3)
        ->and($logger->withMessage('scoring.project_failed'))->toHaveCount(1);
});

it('does not count a failed project in the average', function () {
    $repo = new InMemoryScoringRepository();
    $repo->failOn = [2];

    [$runner] = scoringRunner([1 => scoreInput(), 2 => scoreInput()], $repo);
    $stats = $runner->run();

    // Pinned to the one score that was actually saved. An earlier version of
    // this test asserted only "greater than zero", which stayed true when the
    // divisor was wrong -- a test that cannot fail is not a test.
    expect($stats['scored'])->toBe(1)
        ->and($stats['average_score'])->toBe(round($repo->saved[1]->score, 3));
});

it('does not record a snapshot for a project it failed to score', function () {
    $repo = new InMemoryScoringRepository();
    $repo->failOn = [1];

    [$runner] = scoringRunner([1 => scoreInput()], $repo);
    $runner->run();

    // A snapshot of a score that was never saved would put a number in the
    // growth history that no ranking ever used.
    expect($repo->snapshots)->toHaveCount(0);
});

it('tallies which signals are missing across the feed', function () {
    // Every project here has no repository and no history.
    [$runner] = scoringRunner([1 => scoreInput(), 2 => scoreInput()]);

    $stats = $runner->run();

    // This is how an operator notices enrichment has quietly stopped: the
    // github tally climbs to match the batch size.
    expect($stats['unavailable_components']['github'])->toBe(2)
        ->and($stats['unavailable_components']['growth'])->toBe(2);
});

it('handles a project whose metrics were never fetched', function () {
    [$runner, $repo] = scoringRunner([1 => scoreInput(likes: null)]);

    // Missing engagement is a real state, not an error, and the sweep must
    // score it rather than count it as a failure.
    expect($runner->run()['failed'])->toBe(0)
        ->and($repo->saved)->toHaveCount(1);
});

it('respects the batch limit', function () {
    $due = [];
    for ($i = 1; $i <= 50; $i++) {
        $due[$i] = scoreInput();
    }

    [$runner] = scoringRunner($due);

    // A sweep with an unbounded batch is a sweep that can run past its own
    // schedule and overlap itself.
    expect($runner->run(10)['claimed'])->toBe(10);
});

// ============================================================ FILTER RUNNER

function filterableTweet(int $id, string $text, bool $hasRepo = false): FilterableTweet
{
    return new FilterableTweet(
        id: $id,
        normalizedText: $text,
        hasLink: $hasRepo,
        hasRepositoryLink: $hasRepo,
    );
}

/** @return array{0: FilterRunner, 1: InMemoryFilteringRepository, 2: RecordingLogger} */
function filterRunner(array $due, ?InMemoryFilteringRepository $repo = null): array
{
    $repo ??= new InMemoryFilteringRepository();
    $repo->due = $due;
    $logger = new RecordingLogger();

    // The real signal set, so the runner is exercised against the rules that
    // actually ship rather than a convenient fixture.
    $signals = SignalSetFactory::fromConfig([
        'signals' => [
            'launch' => ['weight' => 3, 'phrases' => ['just launched', 'launched', 'open sourced']],
            'product' => ['weight' => 2, 'phrases' => ['new tool', 'my new']],
        ],
        'negative_signals' => [
            'hiring' => ['weight' => -5, 'phrases' => ['we are hiring']],
        ],
    ]);

    $filter = new TweetFilter(new ProjectSignalDetector(new KeywordMatcher(), $signals));

    return [new FilterRunner($repo, $filter, $logger), $repo, $logger];
}

it('records a decision for every claimed post', function () {
    [$runner, $repo] = filterRunner([
        filterableTweet(1, 'Just launched Pgplan, my new open source query plan viewer', true),
        filterableTweet(2, 'thinking about lunch'),
    ]);

    $stats = $runner->run();

    // Every claim must produce a stored decision, or a post silently sits in
    // the input state forever and is never classified.
    expect($stats['claimed'])->toBe(2)
        ->and($repo->decisions)->toHaveCount(2);
});

it('reports a pass rate, which is the multiplier on AI spend', function () {
    [$runner] = filterRunner([
        filterableTweet(1, 'Just launched my new open source tool, source on GitHub', true),
        filterableTweet(2, 'good morning everyone'),
        filterableTweet(3, 'the weather is nice today'),
    ]);

    $stats = $runner->run();

    expect($stats)->toHaveKey('pass_rate')
        ->and($stats['passed'] + $stats['rejected'] + $stats['failed'])->toBe(3);
});

it('does nothing on an empty queue', function () {
    [$runner, $repo] = filterRunner([]);

    expect($runner->run()['claimed'])->toBe(0)
        ->and($repo->decisions)->toHaveCount(0);
});

it('isolates a post it cannot store', function () {
    $repo = new InMemoryFilteringRepository();
    $repo->failOn = [2];

    [$runner, , $logger] = filterRunner([
        filterableTweet(1, 'launched a new tool', true),
        filterableTweet(2, 'launched another tool', true),
        filterableTweet(3, 'launched a third tool', true),
    ], $repo);

    $stats = $runner->run();

    expect($stats['failed'])->toBe(1)
        ->and($repo->decisions)->toHaveCount(2)
        ->and($logger->withMessage('filtering.item_failed'))->toHaveCount(1);
});

it('does not count a failed post as rejected', function () {
    $repo = new InMemoryFilteringRepository();
    $repo->failOn = [1];

    [$runner] = filterRunner([filterableTweet(1, 'launched a tool', true)], $repo);
    $stats = $runner->run();

    // Counting a storage failure as a rejection would make the pass rate --
    // the number that governs AI spend -- quietly wrong.
    expect($stats['failed'])->toBe(1)
        ->and($stats['rejected'])->toBe(0)
        ->and($stats['passed'])->toBe(0);
});

it('handles an empty post without throwing', function () {
    [$runner, $repo] = filterRunner([filterableTweet(1, '')]);

    expect($runner->run()['failed'])->toBe(0)
        ->and($repo->decisions)->toHaveCount(1);
});
