<?php

declare(strict_types=1);

namespace DevRadar\Domain\Filtering;

/**
 * Whether a post proceeds to AI classification, and why.
 *
 * A rejection always carries a reason drawn from the vocabulary in
 * docs/query-set-v1.md, so automated rejections and hand labels are counted
 * in one tally rather than two incompatible ones.
 */
final readonly class FilterDecision
{
    private function __construct(
        public bool $passes,
        public PreliminaryScore $score,
        public ?string $rejectReason = null,
        public ?string $explanation = null,
    ) {}

    public static function pass(PreliminaryScore $score): self
    {
        return new self(true, $score);
    }

    public static function reject(PreliminaryScore $score, string $reason, string $explanation): self
    {
        return new self(false, $score, $reason, $explanation);
    }
}
