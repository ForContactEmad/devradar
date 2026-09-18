<?php

declare(strict_types=1);

use DevRadar\Domain\Scoring\ConfidenceScorer;
use DevRadar\Domain\Scoring\EngagementScorer;
use DevRadar\Domain\Scoring\GitHubScorer;
use DevRadar\Domain\Scoring\GrowthScorer;
use DevRadar\Domain\Scoring\MetricSnapshot;
use DevRadar\Domain\Scoring\RecencyScorer;
use DevRadar\Domain\Scoring\ScoreInput;
use DevRadar\Domain\Scoring\ScoringEngine;
use DevRadar\Domain\Scoring\ScoringWeights;

const NOW = '2026-09-09 12:00:00';

function scoringConfig(): array
{
    // The real configuration, so these tests exercise the numbers that ship
    // rather than a convenient set invented for the test.
    return require __DIR__ . '/../../../config/scoring.php';
}

function engine(?array $weightOverrides = null): ScoringEngine
{
    $c = scoringConfig();

    return new ScoringEngine(
        engagement: new EngagementScorer(
            $c['engagement']['interactions'],
            (float) $c['engagement']['absolute_cap'],
            (float) $c['engagement']['rate_cap'],
            (float) $c['engagement']['rate_share'],
            (int) $c['engagement']['reach_floor'],
            (float) $c['engagement']['anomaly_rate_threshold'],
            (float) $c['engagement']['anomaly_dampener'],
        ),
        recency: new RecencyScorer(
            (float) $c['recency']['half_life_hours'],
            (float) $c['recency']['window_hours'],
            (float) $c['recency']['grace_hours'],
        ),
        confidence: new ConfidenceScorer(
            (float) $c['confidence']['classification_share'],
            (float) $c['confidence']['extraction_share'],
        ),
        github: new GitHubScorer(
            (float) $c['github']['star_cap'],
            (float) $c['github']['fork_cap'],
            (float) $c['github']['star_share'],
            (float) $c['github']['fork_share'],
            (float) $c['github']['freshness_share'],
            (float) $c['github']['stale_after_days'],
        ),
        growth: new GrowthScorer(
            (float) $c['growth']['rate_per_hour_cap'],
            (float) $c['growth']['minimum_interval_hours'],
        ),
        weights: new ScoringWeights($weightOverrides ?? $c['weights']),
        scale: (float) $c['scale'],
        sparseDataDampener: (float) $c['sparse_data']['dampener'],
        sparseDataThreshold: (int) $c['sparse_data']['threshold'],
    );
}

function at(string $moment): DateTimeImmutable
{
    return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
}

function input(array $overrides = []): ScoreInput
{
    $defaults = [
        'postedAt' => at('2026-09-09 00:00:00'),
        'now' => at(NOW),
        'likeCount' => 100,
        'repostCount' => 10,
        'replyCount' => 5,
        'quoteCount' => 2,
        'bookmarkCount' => 20,
        'authorFollowers' => 5000,
        'classificationConfidence' => 0.9,
        'extractionConfidence' => 0.85,
    ];

    $args = array_merge($defaults, $overrides);

    return new ScoreInput(...$args);
}

// ============================================================== MONOTONICITY

it('scores higher as engagement increases', function () {
    $low = engine()->score(input(['likeCount' => 10]));
    $mid = engine()->score(input(['likeCount' => 200]));
    $high = engine()->score(input(['likeCount' => 2000]));

    expect($mid->score > $low->score)->toBeTrue()
        ->and($high->score > $mid->score)->toBeTrue();
});

it('scores higher for every kind of interaction', function () {
    $base = engine()->score(input([
        'likeCount' => 50, 'repostCount' => 0, 'replyCount' => 0,
        'quoteCount' => 0, 'bookmarkCount' => 0,
    ]))->score;

    foreach (['repostCount', 'replyCount', 'quoteCount', 'bookmarkCount'] as $field) {
        $improved = engine()->score(input([
            'likeCount' => 50, 'repostCount' => 0, 'replyCount' => 0,
            'quoteCount' => 0, 'bookmarkCount' => 0, $field => 25,
        ]))->score;

        expect($improved > $base)->toBeTrue();
    }
});

it('scores higher as recency increases', function () {
    $old = engine()->score(input(['postedAt' => at('2026-09-03 12:00:00')]));
    $recent = engine()->score(input(['postedAt' => at('2026-09-08 12:00:00')]));
    $fresh = engine()->score(input(['postedAt' => at('2026-09-09 10:00:00')]));

    expect($recent->score > $old->score)->toBeTrue()
        ->and($fresh->score > $recent->score)->toBeTrue();
});

it('scores higher as confidence increases', function () {
    $unsure = engine()->score(input(['classificationConfidence' => 0.6, 'extractionConfidence' => 0.6]));
    $sure = engine()->score(input(['classificationConfidence' => 0.99, 'extractionConfidence' => 0.99]));

    expect($sure->score > $unsure->score)->toBeTrue();
});

it('weights classification confidence above extraction confidence', function () {
    // A wrong launch call puts something on the front page that does not
    // belong there; a wrong tech tag is smaller and more visible.
    $strongClassification = engine()->score(input(['classificationConfidence' => 1.0, 'extractionConfidence' => 0.5]));
    $strongExtraction = engine()->score(input(['classificationConfidence' => 0.5, 'extractionConfidence' => 1.0]));

    expect($strongClassification->score > $strongExtraction->score)->toBeTrue();
});

it('scores higher with more GitHub traction', function () {
    $small = engine()->score(input(['repositoryStars' => 10, 'repositoryForks' => 1]));
    $large = engine()->score(input(['repositoryStars' => 4000, 'repositoryForks' => 400]));

    expect($large->score > $small->score)->toBeTrue();
});

it('scores higher for faster growth', function () {
    $slow = engine()->score(input(['history' => [
        new MetricSnapshot(at('2026-09-09 00:00:00'), likeCount: 100),
        new MetricSnapshot(at('2026-09-09 12:00:00'), likeCount: 110),
    ]]));

    $fast = engine()->score(input(['history' => [
        new MetricSnapshot(at('2026-09-09 00:00:00'), likeCount: 100),
        new MetricSnapshot(at('2026-09-09 12:00:00'), likeCount: 900),
    ]]));

    expect($fast->score > $slow->score)->toBeTrue();
});

// ============================================================== EDGE CASES

it('handles zero likes without breaking', function () {
    $breakdown = engine()->score(input([
        'likeCount' => 0, 'repostCount' => 0, 'replyCount' => 0,
        'quoteCount' => 0, 'bookmarkCount' => 0,
    ]));

    // Zero engagement is a real data point, not an error. The component is
    // available and scores zero; the project still ranks on recency and
    // confidence.
    expect($breakdown->score >= 0)->toBeTrue()
        ->and($breakdown->componentValue('engagement'))->toBe(0.0)
        ->and($breakdown->unavailableComponents())->not->toContain('engagement');
});

it('handles zero replies alongside other engagement', function () {
    $breakdown = engine()->score(input(['replyCount' => 0]));

    expect($breakdown->score > 0)->toBeTrue();
});

it('treats missing metrics as unavailable, not as zero', function () {
    $breakdown = engine()->score(input([
        'likeCount' => null, 'repostCount' => null, 'replyCount' => null,
        'quoteCount' => null, 'bookmarkCount' => null,
    ]));

    // "We have not fetched the metrics" and "nobody engaged" are different
    // claims, and only one of them is about the project.
    expect($breakdown->unavailableComponents())->toContain('engagement')
        ->and($breakdown->score > 0)->toBeTrue();
});

it('does not break when GitHub data is missing', function () {
    $breakdown = engine()->score(input());

    expect($breakdown->unavailableComponents())->toContain('github')
        ->and($breakdown->score > 0)->toBeTrue()
        ->and($breakdown->effectiveWeights['github'])->toBe(0.0);
});

it('does not penalise a project for having no repository', function () {
    // Most launches link to a product page, not a repo. A hosted SaaS with no
    // public code is a perfectly good project, and scoring GitHub as zero
    // would systematically rank it below open-source ones for a reason that
    // has nothing to do with quality.
    $noRepo = engine()->score(input());
    $sum = 0.0;

    foreach ($noRepo->components as $component) {
        if ($component->available) {
            $sum += $noRepo->effectiveWeights[$component->name];
        }
    }

    // Available weights are redistributed to sum to 1.
    expect(round($sum, 6))->toBe(1.0);
});

it('handles a brand-new project with no history', function () {
    $breakdown = engine()->score(input([
        'postedAt' => at('2026-09-09 11:30:00'),
        'history' => [],
    ]));

    // No trajectory exists yet, and inventing one from a single point would
    // let the first measurement decide the answer.
    expect($breakdown->unavailableComponents())->toContain('growth')
        ->and($breakdown->componentValue('recency'))->toBe(1.0);
});

it('gives a new post full recency during the grace window', function () {
    // A two-hour-old post has almost no engagement and would lose on that
    // component through no fault of its own.
    $breakdown = engine()->score(input(['postedAt' => at('2026-09-09 10:00:00')]));

    expect($breakdown->componentValue('recency'))->toBe(1.0);
});

it('scores an older project low but not negative', function () {
    $breakdown = engine()->score(input(['postedAt' => at('2026-09-03 00:00:00')]));

    expect($breakdown->componentValue('recency') < 0.1)->toBeTrue()
        ->and($breakdown->score >= 0)->toBeTrue();
});

it('drops recency to zero past the window', function () {
    $breakdown = engine()->score(input(['postedAt' => at('2026-08-01 00:00:00')]));

    expect($breakdown->componentValue('recency'))->toBe(0.0);
});

it('handles a post timestamped in the future', function () {
    // Clock skew, not a prophecy.
    $breakdown = engine()->score(input(['postedAt' => at('2026-09-10 00:00:00')]));

    expect($breakdown->componentValue('recency'))->toBe(1.0)
        ->and($breakdown->score > 0)->toBeTrue();
});

it('handles a completely empty input without throwing', function () {
    $breakdown = (new ScoringEngine(
        engagement: new EngagementScorer(['like' => 1.0], 100, 0.1, 0.5, 250, 0.5, 0.5),
        recency: new RecencyScorer(36, 168, 4),
        confidence: new ConfidenceScorer(0.7, 0.3),
        github: new GitHubScorer(5000, 500, 0.45, 0.2, 0.35, 180),
        growth: new GrowthScorer(40, 1.0),
        weights: new ScoringWeights(['engagement' => 1, 'recency' => 0, 'confidence' => 0, 'github' => 0, 'growth' => 0]),
    ))->score(new ScoreInput(postedAt: at('2026-08-01 00:00:00'), now: at(NOW)));

    expect($breakdown->score)->toBe(0.0)
        ->and($breakdown->notes)->not->toBeEmpty();
});

// ------------------------------------------------------- viral and abnormal

it('lets a viral post rank top without erasing the rest of the feed', function () {
    $good = engine()->score(input(['likeCount' => 400, 'authorFollowers' => 20000]));
    $viral = engine()->score(input(['likeCount' => 80000, 'authorFollowers' => 900000]));

    // Log compression: the viral post wins, but not by a factor that would
    // flatten everything else to zero.
    expect($viral->score > $good->score)->toBeTrue()
        ->and($viral->score < $good->score * 3)->toBeTrue();
});

it('damps engagement wildly out of proportion to reach', function () {
    // 5,000 weighted engagement against 300 followers is more often bought or
    // borrowed than it is a genuine signal.
    $abnormal = engine()->score(input(['likeCount' => 5000, 'authorFollowers' => 300]));

    $engagement = $abnormal->components[0];

    expect($engagement->explanation)->toContain('damped');

    // And the damping actually lowers the score relative to the same
    // engagement from a plausibly sized account.
    $plausible = engine()->score(input(['likeCount' => 5000, 'authorFollowers' => 40000]));

    expect($abnormal->componentValue('engagement') < $plausible->componentValue('engagement'))->toBeTrue();
});

it('does not let a tiny account pin the rate term', function () {
    // Without a reach floor, 30 likes from 3 followers scores a rate of 10.
    $tiny = engine()->score(input(['likeCount' => 30, 'authorFollowers' => 3]));
    $solid = engine()->score(input(['likeCount' => 800, 'authorFollowers' => 8000]));

    expect($solid->score > $tiny->score)->toBeTrue();
});

it('falls back to the absolute term when follower count is unknown', function () {
    $breakdown = engine()->score(input(['authorFollowers' => null]));

    expect($breakdown->unavailableComponents())->not->toContain('engagement')
        ->and($breakdown->componentValue('engagement') > 0)->toBeTrue();
});

it('ignores a downward metric correction rather than ranking it as decline', function () {
    // Metrics move down when a bot sweep removes likes. That is not evidence
    // of decline worth ranking on.
    $breakdown = engine()->score(input(['history' => [
        new MetricSnapshot(at('2026-09-09 00:00:00'), likeCount: 500),
        new MetricSnapshot(at('2026-09-09 12:00:00'), likeCount: 400),
    ]]));

    expect($breakdown->componentValue('growth'))->toBe(0.0);
});

it('ignores snapshots taken too close together', function () {
    $breakdown = engine()->score(input(['history' => [
        new MetricSnapshot(at('2026-09-09 11:59:00'), likeCount: 100),
        new MetricSnapshot(at('2026-09-09 12:00:00'), likeCount: 400),
    ]]));

    expect($breakdown->unavailableComponents())->toContain('growth');
});

// ------------------------------------------------------------ explainability

it('explains every component in words', function () {
    $breakdown = engine()->score(input(['repositoryStars' => 100, 'repositoryPushedAt' => at('2026-09-01 00:00:00')]));

    foreach ($breakdown->components as $component) {
        expect($component->explanation)->not->toBeEmpty();
    }
});

it('records the formula inputs alongside the result', function () {
    $stored = engine()->score(input())->toArray();

    // Ranking is the thing users most often disagree with, and "why is this
    // above that" has to be answerable after the weights have moved on.
    expect($stored)->toHaveKey('components')
        ->and($stored['components']['engagement'])->toHaveKey('effective_weight')
        ->and($stored['components']['engagement'])->toHaveKey('contribution')
        ->and($stored['components']['engagement']['inputs']['likes'])->toBe(100);
});

it('keeps the score inside the configured scale', function () {
    $maximal = engine()->score(input([
        'likeCount' => 500000, 'repostCount' => 50000, 'replyCount' => 20000,
        'quoteCount' => 10000, 'bookmarkCount' => 90000, 'authorFollowers' => 2000000,
        'classificationConfidence' => 1.0, 'extractionConfidence' => 1.0,
        'postedAt' => at('2026-09-09 11:59:00'),
        'repositoryStars' => 90000, 'repositoryForks' => 9000,
        'repositoryPushedAt' => at('2026-09-09 00:00:00'),
        'history' => [
            new MetricSnapshot(at('2026-09-09 00:00:00'), likeCount: 0),
            new MetricSnapshot(at('2026-09-09 12:00:00'), likeCount: 500000),
        ],
    ]));

    expect($maximal->score <= 100.0)->toBeTrue()
        ->and($maximal->score > 80.0)->toBeTrue();
});

// --------------------------------------------------------------- weights

it('rescales weights that do not sum to one', function () {
    // Raising one weight should mean "this matters more", not "every score
    // inflated".
    $weights = new ScoringWeights(['engagement' => 7, 'recency' => 3]);

    expect(round($weights->for('engagement'), 4))->toBe(0.7)
        ->and(round($weights->for('recency'), 4))->toBe(0.3);
});

it('rejects a weight set that is entirely zero', function () {
    expect(fn () => new ScoringWeights(['engagement' => 0, 'recency' => 0]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a negative weight', function () {
    expect(fn () => new ScoringWeights(['engagement' => -1]))->toThrow(InvalidArgumentException::class);
});

// ------------------------------------- forfeited versus redistributed weight

it('does not let a project with no metrics outrank one with proven engagement', function () {
    // Caught by looking at real output: redistributing engagement's weight
    // made ignorance inherit the weight of evidence, and an unmeasured
    // project ranked second in the feed.
    $unmeasured = engine()->score(input([
        'postedAt' => at('2026-09-09 11:30:00'),
        'likeCount' => null, 'repostCount' => null, 'replyCount' => null,
        'quoteCount' => null, 'bookmarkCount' => null,
    ]));

    $proven = engine()->score(input([
        'postedAt' => at('2026-09-08 12:00:00'),
        'likeCount' => 240, 'repostCount' => 30, 'replyCount' => 18,
        'quoteCount' => 6, 'bookmarkCount' => 55, 'authorFollowers' => 1800,
    ]));

    expect($proven->score > $unmeasured->score)->toBeTrue();
});

it('still ranks an unmeasured project above one with proven zero engagement', function () {
    // Unknown is not the same as bad.
    $unmeasured = engine()->score(input([
        'postedAt' => at('2026-09-09 06:00:00'),
        'likeCount' => null, 'repostCount' => null, 'replyCount' => null,
        'quoteCount' => null, 'bookmarkCount' => null,
    ]));

    $ignored = engine()->score(input([
        'postedAt' => at('2026-09-09 06:00:00'),
        'likeCount' => 0, 'repostCount' => 0, 'replyCount' => 0,
        'quoteCount' => 0, 'bookmarkCount' => 0,
    ]));

    expect($unmeasured->score > $ignored->score)->toBeTrue();
});

it('forfeits engagement weight rather than moving it', function () {
    $breakdown = engine()->score(input([
        'likeCount' => null, 'repostCount' => null, 'replyCount' => null,
        'quoteCount' => null, 'bookmarkCount' => null,
    ]));

    $total = array_sum($breakdown->effectiveWeights);

    // Weights no longer sum to 1: the engagement share is lost until the
    // rescore sweep fills it in.
    expect($total < 1.0)->toBeTrue()
        ->and($total > 0.5)->toBeTrue();
});

it('redistributes github and growth weight in full', function () {
    $breakdown = engine()->score(input());

    // github and growth are structurally absent for most launches, so their
    // weight moves and the project competes on what it does have.
    expect(round(array_sum($breakdown->effectiveWeights), 6))->toBe(1.0);
});
