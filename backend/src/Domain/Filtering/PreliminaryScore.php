<?php

declare(strict_types=1);

namespace DevRadar\Domain\Filtering;

/**
 * A cheap relevance estimate, computed without any model call.
 *
 * This is NOT a ranking score. Ranking decides where a published project sits
 * in the feed; this decides whether a post is worth paying a model to read.
 * Conflating the two would mean the feed's order was partly determined by
 * keyword bingo, which is exactly the noise the AI stage exists to filter.
 */
final readonly class PreliminaryScore
{
    /**
     * @param list<SignalMatch> $positives
     * @param list<SignalMatch> $negatives
     */
    public function __construct(
        public int $total,
        public SignalStrength $strength,
        public array $positives,
        public array $negatives,
        public int $structuralPoints = 0,
    ) {}

    public function hasNegativeSignal(): bool
    {
        return $this->negatives !== [];
    }

    /** @return list<string> */
    public function positivePhrases(): array
    {
        return array_map(fn (SignalMatch $m) => $m->phrase, $this->positives);
    }

    /** @return list<string> */
    public function negativePhrases(): array
    {
        return array_map(fn (SignalMatch $m) => $m->phrase, $this->negatives);
    }

    /** @return list<string> distinct groups that fired */
    public function groups(): array
    {
        return array_values(array_unique(array_map(fn (SignalMatch $m) => $m->group, $this->positives)));
    }

    /**
     * Stored on the post so a score can be explained months later, after the
     * signal set has moved on.
     *
     * @return array<string, mixed>
     */
    public function breakdown(): array
    {
        return [
            'total' => $this->total,
            'strength' => $this->strength->value,
            'structural' => $this->structuralPoints,
            'positive' => array_map(
                fn (SignalMatch $m) => ['phrase' => $m->phrase, 'weight' => $m->weight, 'group' => $m->group],
                $this->positives,
            ),
            'negative' => array_map(
                fn (SignalMatch $m) => ['phrase' => $m->phrase, 'weight' => $m->weight, 'group' => $m->group],
                $this->negatives,
            ),
        ];
    }
}
