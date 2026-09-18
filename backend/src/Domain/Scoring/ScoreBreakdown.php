<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

/**
 * A score and the complete reasoning behind it.
 *
 * Stored on the project, not recomputed for display. Ranking is the thing
 * users most often disagree with, and "why is this above that" has to be
 * answerable months later, after the weights have moved on.
 */
final readonly class ScoreBreakdown
{
    /**
     * @param list<ScoreComponent>   $components
     * @param array<string, float>   $effectiveWeights after redistribution
     * @param list<string>           $notes           dampeners and anomalies applied
     */
    public function __construct(
        public float $score,
        public array $components,
        public array $effectiveWeights,
        public array $notes = [],
        public float $dampener = 1.0,
    ) {}

    /** @return list<string> */
    public function unavailableComponents(): array
    {
        return array_values(array_map(
            fn (ScoreComponent $c) => $c->name,
            array_filter($this->components, fn (ScoreComponent $c) => ! $c->available),
        ));
    }

    public function componentValue(string $name): ?float
    {
        foreach ($this->components as $component) {
            if ($component->name === $name) {
                return $component->available ? $component->value : null;
            }
        }

        return null;
    }

    /** @return array<string, mixed> persisted as jsonb on the project */
    public function toArray(): array
    {
        $components = [];

        foreach ($this->components as $component) {
            $weight = $this->effectiveWeights[$component->name] ?? 0.0;

            $components[$component->name] = [
                'value' => round($component->value, 4),
                'available' => $component->available,
                'configured_weight' => round($component->weight, 4),
                'effective_weight' => round($weight, 4),
                'contribution' => round($component->contribution($weight), 4),
                'why' => $component->explanation,
                'inputs' => $component->inputs,
            ];
        }

        return [
            'score' => round($this->score, 5),
            'dampener' => round($this->dampener, 4),
            'notes' => $this->notes,
            'components' => $components,
        ];
    }
}
