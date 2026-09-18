<?php

declare(strict_types=1);

namespace DevRadar\Domain\Classification;

/**
 * What happened to a classification attempt.
 *
 * LowConfidence is deliberately distinct from Rejected. The model said yes
 * but was unsure, and that is a different fact from the model saying no --
 * it is the population you sample when tuning the threshold, and collapsing
 * it into "rejected" throws away the only evidence about where the threshold
 * should sit.
 */
enum ClassificationOutcome: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case LowConfidence = 'low_confidence';
    case Unparseable = 'unparseable';
    case Failed = 'failed';

    public function isTerminalFailure(): bool
    {
        return $this === self::Failed || $this === self::Unparseable;
    }
}
