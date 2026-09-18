<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

/**
 * Scores how much attention a post actually earned. Pure, no I/O.
 *
 * THREE PROBLEMS WITH RAW ENGAGEMENT, and each drives one part of this class:
 *
 * 1. INTERACTION TYPES ARE NOT EQUAL. A like costs a tap. A reply costs a
 *    sentence. A bookmark means "I intend to use this", which is the closest
 *    thing on the platform to the signal DevRadar actually wants. Summing
 *    them raw treats a thousand idle likes as equal to a hundred people
 *    saving the link.
 *
 * 2. THE DISTRIBUTION IS HEAVY-TAILED. One viral post with 50,000 likes would
 *    dominate a linear scale and flatten every other project to near-zero.
 *    Log compression keeps a viral post ahead without letting it erase the
 *    rest of the feed.
 *
 * 3. ABSOLUTE COUNTS MEASURE FOLLOWER COUNT. Two hundred likes from a
 *    500-follower account is a far stronger signal about the PROJECT than two
 *    hundred from an account with half a million followers, where it is a
 *    weak signal about the account. So the score blends an absolute term with
 *    an engagement-rate term.
 *
 * ANOMALY DAMPING. Engagement wildly out of proportion to reach is more often
 * bought, botted, or borrowed from a quote-tweet of something else than it is
 * a genuine signal. It is damped rather than rewarded, and the damping is
 * reported so it can be reviewed instead of silently changing a ranking.
 */
final readonly class EngagementScorer
{
    public const NAME = 'engagement';

    /**
     * @param array<string, float> $interactionWeights like/repost/reply/quote/bookmark
     */
    public function __construct(
        private array $interactionWeights,
        /**
         * Weighted engagement treated as a full score.
         *
         * Not a maximum: log compression means anything above it still scores
         * near 1 without breaking the scale.
         */
        private float $absoluteCap,
        /** Engagement rate treated as a full score, as a fraction of reach. */
        private float $rateCap,
        /** How much of the score is the rate term versus the absolute term. */
        private float $rateShare,
        /**
         * Minimum reach used in the rate calculation.
         *
         * Without a floor, a 3-follower account with 30 likes scores a rate
         * of 10 and pins the term at maximum. The floor asserts that below a
         * certain audience size, the rate is not measuring anything.
         */
        private int $reachFloor,
        /** Rate above which engagement is treated as anomalous. */
        private float $anomalyRateThreshold,
        /** Multiplier applied to the rate term when anomalous. */
        private float $anomalyDampener,
    ) {}

    public function score(ScoreInput $input): ScoreComponent
    {
        if (! $input->hasAnyEngagementMetric()) {
            // Distinct from zero engagement: the metrics were never fetched.
            return ScoreComponent::forfeited(
                self::NAME,
                0.0,
                'No engagement metrics recorded for this post yet.',
            );
        }

        $weighted = $this->weightedEngagement($input);

        // log1p so that zero engagement maps to exactly zero rather than to a
        // negative or undefined value. A post with no likes is a real, valid
        // data point and must score 0, not break.
        $absolute = $this->compress($weighted, $this->absoluteCap);

        $rate = $this->engagementRate($input, $weighted);
        $notes = [];

        if ($rate === null) {
            // No follower count: the rate term is unmeasurable, so the score
            // rests entirely on the absolute term rather than guessing.
            return new ScoreComponent(
                name: self::NAME,
                value: $absolute,
                weight: 0.0,
                explanation: sprintf(
                    'Weighted engagement %.1f (absolute only; author reach unknown).',
                    $weighted,
                ),
                inputs: $this->inputSummary($input, $weighted, null),
            );
        }

        $anomalous = $rate > $this->anomalyRateThreshold;

        if ($anomalous) {
            $notes[] = sprintf(
                'engagement rate %.2f exceeds %.2f; rate term damped',
                $rate,
                $this->anomalyRateThreshold,
            );
        }

        $rateValue = $this->compress($rate, $this->rateCap);

        if ($anomalous) {
            $rateValue *= $this->anomalyDampener;
        }

        $value = ($absolute * (1 - $this->rateShare)) + ($rateValue * $this->rateShare);

        return new ScoreComponent(
            name: self::NAME,
            value: $this->clamp($value),
            weight: 0.0,
            explanation: sprintf(
                'Weighted engagement %.1f (absolute %.2f) at a rate of %.4f per follower (rate term %.2f)%s.',
                $weighted,
                $absolute,
                $rate,
                $rateValue,
                $notes === [] ? '' : ' — ' . implode('; ', $notes),
            ),
            inputs: $this->inputSummary($input, $weighted, $rate),
        );
    }

    private function weightedEngagement(ScoreInput $input): float
    {
        // A null metric contributes nothing rather than being treated as
        // zero engagement, because the two mean different things and only
        // one of them is a claim about the post.
        return (($input->likeCount ?? 0) * ($this->interactionWeights['like'] ?? 0))
            + (($input->repostCount ?? 0) * ($this->interactionWeights['repost'] ?? 0))
            + (($input->replyCount ?? 0) * ($this->interactionWeights['reply'] ?? 0))
            + (($input->quoteCount ?? 0) * ($this->interactionWeights['quote'] ?? 0))
            + (($input->bookmarkCount ?? 0) * ($this->interactionWeights['bookmark'] ?? 0));
    }

    private function engagementRate(ScoreInput $input, float $weighted): ?float
    {
        if ($input->authorFollowers === null) {
            return null;
        }

        $reach = max($input->authorFollowers, $this->reachFloor);

        return $reach > 0 ? $weighted / $reach : null;
    }

    /** Log compression: generous to the ordinary, bounded for the viral. */
    private function compress(float $value, float $cap): float
    {
        if ($value <= 0.0 || $cap <= 0.0) {
            return 0.0;
        }

        return $this->clamp(log1p($value) / log1p($cap));
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    /** @return array<string, mixed> */
    private function inputSummary(ScoreInput $input, float $weighted, ?float $rate): array
    {
        return [
            'likes' => $input->likeCount,
            'reposts' => $input->repostCount,
            'replies' => $input->replyCount,
            'quotes' => $input->quoteCount,
            'bookmarks' => $input->bookmarkCount,
            'followers' => $input->authorFollowers,
            'weighted' => round($weighted, 2),
            'rate' => $rate === null ? null : round($rate, 5),
        ];
    }
}
