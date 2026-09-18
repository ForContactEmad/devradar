<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

/**
 * Produces a project's final score. Pure, no I/O, no framework.
 *
 * THE FORMULA, in full:
 *
 *   final = scale × dampener × Σ (componentᵢ.value × effectiveWeightᵢ)
 *
 *   where effectiveWeightᵢ = configuredWeightᵢ / Σ configuredWeight of
 *                            AVAILABLE components
 *
 * Every term is inspectable. Each component normalises to 0..1 and explains
 * itself in words; the weights come from configuration; the redistribution
 * rule is one line. Nothing in here is a tuned constant with no story behind
 * it -- the only numbers are in config/scoring.php, each with a comment
 * saying what it means and why it has that value.
 *
 * WHY REDISTRIBUTION RATHER THAN ZEROS. Most launches have no repository and
 * no growth history on their first scoring pass. Scoring those components
 * zero would make "we don't know" indistinguishable from "we know it's bad",
 * and would systematically rank hosted products below open-source ones for
 * reasons that have nothing to do with quality. Redistribution scores each
 * project on what is actually known about it.
 *
 * NO PERSISTENCE, NO CLOCK, NO CONFIG LOOKUP. Everything arrives as arguments,
 * which is what lets the whole formula be tested with invented numbers and no
 * infrastructure at all.
 */
final readonly class ScoringEngine
{
    public function __construct(
        private EngagementScorer $engagement,
        private RecencyScorer $recency,
        private ConfidenceScorer $confidence,
        private GitHubScorer $github,
        private GrowthScorer $growth,
        private ScoringWeights $weights,
        /** Final scores are expressed on a 0..N scale for readability. */
        private float $scale = 100.0,
        /**
         * Applied when every component but one is unavailable.
         *
         * A project scored on recency alone is not comparable with one scored
         * on five signals, and letting it reach the same maximum would put
         * unmeasured projects at the top of the feed. The dampener says
         * plainly: we know very little about this one.
         */
        private float $sparseDataDampener = 0.85,
        private int $sparseDataThreshold = 2,
    ) {}

    public function score(ScoreInput $input): ScoreBreakdown
    {
        $components = [
            $this->engagement->score($input),
            $this->recency->score($input),
            $this->confidence->score($input),
            $this->github->score($input),
            $this->growth->score($input),
        ];

        $effective = $this->weights->effectiveWeights($components);

        $sum = 0.0;

        foreach ($components as $component) {
            $sum += $component->contribution($effective[$component->name] ?? 0.0);
        }

        $availableCount = count(array_filter($components, fn (ScoreComponent $c) => $c->available));

        $notes = [];
        $dampener = 1.0;

        if ($availableCount === 0) {
            // Nothing measurable at all. Zero is the honest answer, and the
            // note is what stops it looking like a bug.
            $notes[] = 'no scoring component had usable data';
        } elseif ($availableCount < $this->sparseDataThreshold) {
            $dampener = $this->sparseDataDampener;
            $notes[] = sprintf(
                'only %d of %d components available; score damped by %.2f',
                $availableCount,
                count($components),
                $this->sparseDataDampener,
            );
        }

        foreach ($components as $component) {
            if (! $component->available) {
                $notes[] = sprintf('%s unavailable: %s', $component->name, $component->explanation);
            }
        }

        $final = round($this->scale * $dampener * $sum, 5);

        return new ScoreBreakdown(
            // The schema constrains score >= 0; floating-point sums of
            // non-negative terms cannot go below it, but clamping makes that
            // guarantee local rather than something you have to reason about.
            score: max(0.0, $final),
            components: $components,
            effectiveWeights: $effective,
            notes: $notes,
            dampener: $dampener,
        );
    }
}
