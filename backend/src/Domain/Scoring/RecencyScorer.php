<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

/**
 * Scores how fresh a project is. Pure, no I/O.
 *
 * EXPONENTIAL DECAY WITH A HALF-LIFE, not linear. Linear decay over seven
 * days makes a six-day-old project worth 1/7 of a new one, which is far too
 * generous at the tail: the whole product promise is "what shipped this
 * week", and something from last Tuesday should not still be competing hard
 * with something from this morning.
 *
 * The half-life is the only number here, and it says exactly what it means:
 * after this many hours, a project is worth half what it was.
 *
 * A GRACE PERIOD PROTECTS NEW POSTS. A post two hours old has almost no
 * engagement yet, so it loses on that component through no fault of its own.
 * Full recency during the grace window is what lets it compete while its
 * metrics mature, and the rescore sweep then re-ranks it on real numbers.
 */
final readonly class RecencyScorer
{
    public const NAME = 'recency';

    public function __construct(
        private float $halfLifeHours,
        private float $windowHours,
        private float $graceHours = 0.0,
    ) {}

    public function score(ScoreInput $input): ScoreComponent
    {
        $age = $input->ageInHours();

        if ($age > $this->windowHours) {
            // Outside the rolling window the project should be aging out
            // entirely, not merely ranking low.
            return new ScoreComponent(
                name: self::NAME,
                value: 0.0,
                weight: 0.0,
                explanation: sprintf('%.1f hours old, past the %.0f-hour window.', $age, $this->windowHours),
                inputs: ['age_hours' => round($age, 2)],
            );
        }

        if ($age <= $this->graceHours) {
            return new ScoreComponent(
                name: self::NAME,
                value: 1.0,
                weight: 0.0,
                explanation: sprintf('%.1f hours old, inside the %.0f-hour grace window.', $age, $this->graceHours),
                inputs: ['age_hours' => round($age, 2), 'grace' => true],
            );
        }

        $decayed = 2 ** (-(($age - $this->graceHours) / $this->halfLifeHours));

        return new ScoreComponent(
            name: self::NAME,
            value: max(0.0, min(1.0, $decayed)),
            weight: 0.0,
            explanation: sprintf(
                '%.1f hours old; half-life %.0f hours gives %.3f.',
                $age,
                $this->halfLifeHours,
                $decayed,
            ),
            inputs: ['age_hours' => round($age, 2), 'half_life_hours' => $this->halfLifeHours],
        );
    }
}
