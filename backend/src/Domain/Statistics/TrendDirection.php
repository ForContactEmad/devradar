<?php

declare(strict_types=1);

namespace DevRadar\Domain\Statistics;

/**
 * Which way a metric is moving.
 *
 * `Insufficient` is the one that matters. DevRadar publishes on the order of
 * ten projects a week, so a jump from two to six is a 200% rise by
 * arithmetic and noise by any honest reading. A dashboard that reports it as
 * a trend is training its reader to ignore the dashboard.
 *
 * Refusing to call a direction on a small sample is the difference between
 * statistics and decoration.
 */
enum TrendDirection: string
{
    case Rising = 'rising';
    case Falling = 'falling';
    case Steady = 'steady';
    case Insufficient = 'insufficient';

    public function isMovement(): bool
    {
        return $this === self::Rising || $this === self::Falling;
    }
}
