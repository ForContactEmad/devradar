<?php

declare(strict_types=1);

namespace DevRadar\Domain\Collection;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The time span a collection run covers.
 *
 * Always derived from the clock, never written down. A hard-coded date in a
 * rolling-window product is a bug that only shows up a week later, when the
 * window silently stops moving and the feed quietly goes stale.
 */
final readonly class CollectionWindow
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {
        if ($start >= $end) {
            throw new InvalidArgumentException('Window start must be before its end.');
        }
    }

    public function durationInDays(): float
    {
        return ($this->end->getTimestamp() - $this->start->getTimestamp()) / 86400;
    }

    public function contains(DateTimeImmutable $moment): bool
    {
        return $moment >= $this->start && $moment <= $this->end;
    }
}
