<?php

declare(strict_types=1);

use DevRadar\Domain\Filtering\FilterableTweet;
use DevRadar\Domain\Filtering\KeywordMatcher;
use DevRadar\Domain\Filtering\ProjectSignalDetector;
use DevRadar\Domain\Filtering\SignalSetFactory;
use DevRadar\Domain\Filtering\SignalStrength;
use DevRadar\Domain\Filtering\TweetFilter;

function testSignals(): array
{
    return SignalSetFactory::fromConfig([
        'signals' => [
            'launch' => ['weight' => 3, 'phrases' => ['just launched', 'open sourced', 'introducing']],
            'build' => ['weight' => 2, 'phrases' => ['I built', 'side project']],
            'product' => ['weight' => 2, 'phrases' => ['new tool', 'developer tool']],
            'invite' => ['weight' => 1, 'phrases' => ['try it', 'feedback welcome']],
        ],
        'negative_signals' => [
            'hiring' => ['weight' => -5, 'phrases' => ['we are hiring', 'apply now']],
            'tutorial' => ['weight' => -3, 'phrases' => ['how to build', 'a thread']],
            'giveaway' => ['weight' => -5, 'phrases' => ['retweet to win']],
        ],
    ]);
}

function detector(): ProjectSignalDetector
{
    return new ProjectSignalDetector(new KeywordMatcher(), testSignals());
}

function filter(
    SignalStrength $minimum = SignalStrength::Weak,
    SignalStrength $override = SignalStrength::Strong,
): TweetFilter {
    return new TweetFilter(detector(), $minimum, $override);
}

function candidate(
    string $text,
    bool $link = false,
    bool $repo = false,
    bool $repost = false,
): FilterableTweet {
    return new FilterableTweet(1, $text, $link, $repo, $repost);
}

// -------------------------------------------------------------- positives

it('passes a clear launch announcement', function () {
    $decision = filter()->decide(candidate('We just launched pgplan, a query plan viewer', link: true, repo: true));

    expect($decision->passes)->toBeTrue()
        ->and($decision->score->strength)->toBe(SignalStrength::Strong);
});

it('passes a build-in-public post', function () {
    expect(filter()->decide(candidate('I built a small scheduler as a side project', link: true))->passes)->toBeTrue();
});

it('scores a repository link as strong structural evidence', function () {
    // A link to a code host is stronger evidence than any phrase, and no
    // keyword tuning can express it.
    $withRepo = detector()->detect(candidate('new tool', link: true, repo: true));
    $withoutRepo = detector()->detect(candidate('new tool', link: true));

    expect($withRepo->total)->toBe($withoutRepo->total + 2)
        ->and($withRepo->structuralPoints)->toBe(3);
});

it('does not reward a repository link twice', function () {
    // repository_link is the stronger version of any_link, not an addition.
    expect(detector()->detect(candidate('x', link: true, repo: true))->structuralPoints)->toBe(3);
});

// -------------------------------------------------------------- negatives

it('rejects a post with no signal at all', function () {
    $decision = filter()->decide(candidate('Thinking about database indexes this morning'));

    expect($decision->passes)->toBeFalse()
        ->and($decision->rejectReason)->toBe('no-signal');
});

it('rejects a hiring post', function () {
    $decision = filter()->decide(candidate('We are hiring backend engineers, apply now', link: true));

    expect($decision->passes)->toBeFalse()
        ->and($decision->rejectReason)->toBe('hiring');
});

it('rejects a tutorial thread', function () {
    $decision = filter()->decide(candidate('How to build a new tool in Rust, a thread', link: true));

    expect($decision->passes)->toBeFalse()
        ->and($decision->rejectReason)->toBe('tutorial');
});

it('rejects a giveaway', function () {
    expect(filter()->decide(candidate('Retweet to win a free licence', link: true))->passes)->toBeFalse();
});

it('penalises a repost', function () {
    // Someone else's announcement, at full price.
    $original = detector()->detect(candidate('just launched our new tool', link: true));
    $repost = detector()->detect(candidate('just launched our new tool', link: true, repost: true));

    expect($repost->total)->toBe($original->total - 4);
});

it('reports the strongest negative as the reason', function () {
    $decision = filter()->decide(candidate('a thread on how to build things, we are hiring too'));

    expect($decision->rejectReason)->toBe('hiring');
});

// -------------------------------------------- false positives and negatives

it('does not fire on words that merely contain a signal', function () {
    // "rebuilt" is not "I built"; "new appointment" is not "new app".
    $decision = filter()->decide(candidate('We rebuilt the parser and booked a new appointment'));

    expect($decision->passes)->toBeFalse()
        ->and($decision->score->total)->toBe(0);
});

it('lets overwhelming positive evidence override a negative signal', function () {
    // "We're hiring engineers to work on our newly open-sourced compiler" is
    // a real launch wearing a hiring phrase.
    $decision = filter()->decide(candidate(
        'We just launched our open sourced compiler. Introducing it today. We are hiring too.',
        link: true,
        repo: true,
    ));

    expect($decision->score->strength)->toBe(SignalStrength::Strong)
        ->and($decision->passes)->toBeTrue();
});

it('errs toward passing, because a false negative loses the project entirely', function () {
    // One weak phrase and a link is enough. A false positive costs one
    // classification; a false negative costs a post already paid for.
    $decision = filter()->decide(candidate('try it and let me know', link: true));

    expect($decision->passes)->toBeTrue()
        ->and($decision->score->strength)->toBe(SignalStrength::Weak);
});

it('can be tightened when measurements justify it', function () {
    $strict = filter(minimum: SignalStrength::Strong);
    // "try it" plus a link scores 2 -- Weak. Enough for the permissive
    // default, not enough for a strict threshold.
    $weakPost = candidate('try it and let me know', link: true);

    expect(filter()->decide($weakPost)->passes)->toBeTrue()
        ->and($strict->decide($weakPost)->passes)->toBeFalse();
});

// ------------------------------------------------------- multiple signals

it('accumulates distinct signals', function () {
    $score = detector()->detect(candidate('I built a new developer tool and just launched it', link: true));

    // Three, not four: "new tool" does not appear contiguously in "a new
    // developer tool", and the matcher is deliberately not fuzzy.
    expect(count($score->positives))->toBe(3)
        ->and($score->groups())->toContain('launch')
        ->and($score->groups())->toContain('build')
        ->and($score->groups())->toContain('product');
});

it('records an explainable breakdown', function () {
    $breakdown = detector()->detect(candidate('just launched a new tool', link: true, repo: true))->breakdown();

    // A score nobody can explain is a score nobody can tune.
    expect($breakdown['strength'])->toBe('strong')
        ->and($breakdown['structural'])->toBe(3)
        ->and($breakdown['positive'])->toHaveCount(2)
        ->and($breakdown['positive'][0]['group'])->toBe('launch');
});

// ------------------------------------------------------------- thresholds

it('maps totals onto the three strength levels', function () {
    expect(detector()->detect(candidate('try it'))->strength)->toBe(SignalStrength::Weak)
        ->and(detector()->detect(candidate('just launched'))->strength)->toBe(SignalStrength::Medium)
        ->and(detector()->detect(candidate('just launched a new tool'))->strength)->toBe(SignalStrength::Strong)
        ->and(detector()->detect(candidate('nothing here'))->strength)->toBe(SignalStrength::None);
});

it('compares strength levels by rank', function () {
    expect(SignalStrength::Strong->atLeast(SignalStrength::Weak))->toBeTrue()
        ->and(SignalStrength::Weak->atLeast(SignalStrength::Strong))->toBeFalse()
        ->and(SignalStrength::None->atLeast(SignalStrength::Weak))->toBeFalse();
});

// ------------------------------------------------------------- validation

it('refuses a signal that can never affect a score', function () {
    expect(fn () => new DevRadar\Domain\Filtering\SignalDefinition('x', 0, 'g'))
        ->toThrow(InvalidArgumentException::class, 'weight 0');
});
