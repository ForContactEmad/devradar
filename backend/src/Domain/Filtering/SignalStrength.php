<?php

declare(strict_types=1);

namespace DevRadar\Domain\Filtering;

/**
 * How strongly a post looks like a software launch, before any AI is involved.
 *
 * Four levels rather than a bare number, because the number is only useful
 * once it has been turned into a decision, and the level is what the
 * threshold is expressed in.
 */
enum SignalStrength: string
{
    case Strong = 'strong';
    case Medium = 'medium';
    case Weak = 'weak';
    case None = 'none';

    /** Higher is stronger. Used for threshold comparison. */
    public function rank(): int
    {
        return match ($this) {
            self::Strong => 3,
            self::Medium => 2,
            self::Weak => 1,
            self::None => 0,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }
}
