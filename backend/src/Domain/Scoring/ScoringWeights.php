<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

use InvalidArgumentException;

/**
 * The weights each component carries, injected from configuration.
 *
 * NORMALISED ON CONSTRUCTION. Weights that do not sum to 1 are rescaled
 * rather than rejected, so tuning one weight in configuration does not
 * silently change the meaning of every other. Somebody raising engagement
 * from 0.35 to 0.45 should get "engagement matters more", not "every score
 * inflated by 10%".
 */
final readonly class ScoringWeights
{
    /** @var array<string, float> */
    public array $weights;

    /** @param array<string, float> $weights */
    public function __construct(array $weights)
    {
        $clean = [];

        foreach ($weights as $name => $weight) {
            $weight = (float) $weight;

            if ($weight < 0) {
                throw new InvalidArgumentException("Weight for '{$name}' is negative.");
            }

            $clean[(string) $name] = $weight;
        }

        $total = array_sum($clean);

        if ($total <= 0) {
            throw new InvalidArgumentException('At least one scoring weight must be greater than zero.');
        }

        $this->weights = array_map(fn (float $w) => $w / $total, $clean);
    }

    public function for(string $component): float
    {
        return $this->weights[$component] ?? 0.0;
    }

    /**
     * Compute effective weights, redistributing or forfeiting as each
     * unavailable component requires.
     *
     * THIS IS THE HEART OF GRACEFUL DEGRADATION, and it has two halves.
     *
     * Redistribution handles STRUCTURAL absence. Scoring a missing repository
     * as zero would say "this project has a bad repository" -- a different and
     * false claim from "this project has no repository". Its weight moves to
     * the components that do apply, so a strong project with no GitHub link
     * competes fairly with one that has stars.
     *
     * Forfeiture handles TEMPORARY absence. Engagement metrics not yet
     * fetched are not evidence of anything. Redistributing them would let a
     * project we know nothing about inherit the weight of evidence and
     * outrank one with proven traction -- ignorance rewarded. That weight is
     * lost, so such a project scores out of less than the full scale until
     * the rescore sweep fills the gap.
     *
     * @param  list<ScoreComponent> $components
     * @return array<string, float>
     */
    public function effectiveWeights(array $components): array
    {
        $availableTotal = 0.0;
        $pool = 0.0;

        foreach ($components as $component) {
            $weight = $this->for($component->name);

            if ($component->available) {
                $availableTotal += $weight;
            } elseif ($component->redistributable) {
                $pool += $weight;
            }
            // Otherwise forfeited: neither counted nor moved.
        }

        $effective = [];
        $multiplier = $availableTotal > 0 ? 1 + ($pool / $availableTotal) : 0.0;

        foreach ($components as $component) {
            $effective[$component->name] = $component->available
                ? $this->for($component->name) * $multiplier
                : 0.0;
        }

        return $effective;
    }
}
