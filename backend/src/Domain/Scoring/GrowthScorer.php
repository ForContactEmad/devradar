<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

/**
 * Scores how fast a project is gaining attention. Pure, no I/O.
 *
 * WHY GROWTH IS SEPARATE FROM ENGAGEMENT. Engagement measures where a project
 * has got to; growth measures where it is going. A project at 500 likes and
 * climbing steeply is a better bet than one that reached 800 two days ago and
 * stopped, and a total-only score cannot tell them apart.
 *
 * REQUIRES AT LEAST TWO SNAPSHOTS, and reports itself unavailable otherwise.
 * That is the honest state for a newly published project: it has no
 * trajectory yet, and inventing one from a single point would mean the first
 * measurement decided the answer. Its weight is redistributed instead, so a
 * brand-new project is scored on recency, engagement and confidence rather
 * than being punished for lacking history it could not possibly have.
 *
 * RATE PER HOUR, not total delta, so a project measured twice an hour apart
 * is comparable with one measured twice a day apart.
 */
final readonly class GrowthScorer
{
    public const NAME = 'growth';

    public function __construct(
        /** Engagement gained per hour that counts as a full score. */
        private float $ratePerHourCap,
        /** Ignore intervals shorter than this; they are mostly noise. */
        private float $minimumIntervalHours,
    ) {}

    public function score(ScoreInput $input): ScoreComponent
    {
        $history = $input->history;

        if (count($history) < 2) {
            return ScoreComponent::unavailable(
                self::NAME,
                0.0,
                'Fewer than two metric snapshots; no trajectory exists yet.',
            );
        }

        $first = $history[0];
        $last = $history[count($history) - 1];

        $hours = ($last->capturedAt->getTimestamp() - $first->capturedAt->getTimestamp()) / 3600;

        if ($hours < $this->minimumIntervalHours) {
            return ScoreComponent::unavailable(
                self::NAME,
                0.0,
                sprintf('Snapshots only %.2f hours apart; too close together to measure a rate.', $hours),
            );
        }

        $delta = $last->totalEngagement() - $first->totalEngagement();

        // A negative delta means metrics were corrected downward, usually
        // because a post was deleted or a bot sweep removed likes. It is not
        // evidence of decline worth ranking on, so it floors at zero rather
        // than dragging the project below one that simply has not grown.
        $rate = max(0.0, $delta / $hours);

        $value = $this->compress($rate, $this->ratePerHourCap);

        return new ScoreComponent(
            name: self::NAME,
            value: $value,
            weight: 0.0,
            explanation: sprintf(
                '+%d engagement over %.1f hours = %.2f/hour (%.2f).',
                $delta,
                $hours,
                $rate,
                $value,
            ),
            inputs: [
                'delta' => $delta,
                'hours' => round($hours, 2),
                'rate_per_hour' => round($rate, 3),
                'snapshots' => count($history),
            ],
        );
    }

    private function compress(float $value, float $cap): float
    {
        if ($value <= 0.0 || $cap <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, log1p($value) / log1p($cap)));
    }
}
