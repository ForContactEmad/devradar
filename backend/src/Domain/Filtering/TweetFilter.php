<?php

declare(strict_types=1);

namespace DevRadar\Domain\Filtering;

/**
 * Decides which posts are worth paying a model to read. Pure, no I/O.
 *
 * TUNED FOR RECALL, NOT PRECISION -- and this is the decision that matters
 * most in this layer.
 *
 * The AI stage exists to be precise. This stage exists to be cheap. Their
 * error costs are not symmetric:
 *
 *   A false positive here costs one classification. Fractions of a cent, and
 *   the AI stage rejects it anyway.
 *
 *   A false negative here costs the project entirely. The post was already
 *   paid for at collection, the model never sees it, and it never reaches the
 *   feed. There is no later stage that can recover it.
 *
 * So the default threshold is Weak: almost anything with a hint of a launch
 * proceeds. Rejection is reserved for posts with NO positive signal at all,
 * or with strong evidence of being something else.
 *
 * NEGATIVE SIGNALS DO NOT AUTOMATICALLY VETO. "We're hiring engineers to work
 * on our newly open-sourced compiler" is a real launch wearing a hiring
 * phrase. A negative signal only rejects when the positive evidence is weak
 * enough that the post is probably what the negative says it is.
 */
final readonly class TweetFilter
{
    public function __construct(
        private ProjectSignalDetector $detector,
        /** Minimum strength to proceed to classification. */
        private SignalStrength $minimumStrength = SignalStrength::Weak,
        /**
         * Strength at or above which negative signals are ignored.
         *
         * Set to Strong so that only overwhelming positive evidence
         * overrides an explicit hiring or tutorial phrase.
         */
        private SignalStrength $negativeOverrideStrength = SignalStrength::Strong,
        /**
         * Require actual launch evidence, not just structural points.
         *
         * Without this, "Good morning everyone" plus any link scores 1 and
         * clears the Weak threshold on the link alone. A link is not evidence
         * of a launch; it is evidence of a link. Structural points AMPLIFY
         * keyword evidence, they do not substitute for it.
         *
         * A repository link is the one exception: a link to a code host is
         * itself evidence that software is involved, whatever the wording.
         */
        private bool $requireEvidence = true,
    ) {}

    public function decide(FilterableTweet $tweet): FilterDecision
    {
        $score = $this->detector->detect($tweet);

        if ($score->hasNegativeSignal() && ! $score->strength->atLeast($this->negativeOverrideStrength)) {
            return FilterDecision::reject(
                $score,
                $this->reasonFor($score),
                sprintf(
                    'Negative signal(s) [%s] and only %s positive evidence.',
                    implode(', ', $score->negativePhrases()),
                    $score->strength->value,
                ),
            );
        }

        // Checked AFTER the negative rules, so a hiring post is recorded as
        // 'hiring' rather than the far less useful 'no-signal'. The reject
        // reason histogram is what new free rules get written from, and a
        // specific reason is worth more than a generic one.
        if ($this->requireEvidence && $score->positives === [] && ! $tweet->hasRepositoryLink) {
            return FilterDecision::reject(
                $score,
                'no-signal',
                'No launch phrase matched and no repository link; structural points alone are not evidence.',
            );
        }

        if (! $score->strength->atLeast($this->minimumStrength)) {
            return FilterDecision::reject(
                $score,
                'no-signal',
                sprintf('Score %d is below the %s threshold.', $score->total, $this->minimumStrength->value),
            );
        }

        return FilterDecision::pass($score);
    }

    /**
     * The negative group's name doubles as the reject reason, so adding a new
     * negative group to configuration adds a new reason to the histogram
     * without touching this class.
     */
    private function reasonFor(PreliminaryScore $score): string
    {
        $negatives = $score->negatives;

        if ($negatives === []) {
            return 'no-signal';
        }

        // Strongest negative wins, so the most confident explanation is the
        // one recorded.
        usort($negatives, fn (SignalMatch $a, SignalMatch $b) => $a->weight <=> $b->weight);

        return $negatives[0]->group;
    }
}
