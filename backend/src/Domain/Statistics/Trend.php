<?php

declare(strict_types=1);

namespace DevRadar\Domain\Statistics;

/**
 * One metric, compared against the equivalent previous period.
 *
 * A BARE NUMBER IS NOT A STATISTIC. "47 projects" says nothing; "47, up from
 * 32" says something. Every headline figure therefore carries its own
 * baseline, and the comparison period is always the same length as the
 * current one -- comparing this week against this month would manufacture a
 * decline out of arithmetic.
 *
 * TWO GUARDS AGAINST FALSE SIGNAL:
 *
 *   Sample size. Below a minimum, no direction is reported at all. Percentage
 *   change on tiny counts is dominated by whichever project happened to land
 *   on a Tuesday.
 *
 *   Dead band. A move of a few percent is drift, not a trend. Inside the band
 *   the direction is Steady, so the dashboard stops flickering between rising
 *   and falling on every rescore.
 */
final readonly class Trend
{
    public function __construct(
        public string $metric,
        public float $current,
        public float $previous,
        public TrendDirection $direction,
        public ?float $changePercent,
        public int $sampleSize,
        public string $explanation,
    ) {}

    /**
     * @param int   $minimumSample below this, no direction is claimed
     * @param float $deadBand      fractional change treated as steady
     */
    public static function compare(
        string $metric,
        float $current,
        float $previous,
        int $sampleSize,
        int $minimumSample = 8,
        float $deadBand = 0.05,
    ): self {
        $change = self::changePercent($current, $previous);

        if ($sampleSize < $minimumSample) {
            return new self(
                $metric,
                $current,
                $previous,
                TrendDirection::Insufficient,
                $change,
                $sampleSize,
                sprintf(
                    'Only %d observations; too few to call a direction (%d needed).',
                    $sampleSize,
                    $minimumSample,
                ),
            );
        }

        if ($change === null) {
            // Nothing before and nothing now. Not a decline, not a rise.
            return new self($metric, $current, $previous, TrendDirection::Steady, null, $sampleSize,
                'No activity in either period.');
        }

        $direction = match (true) {
            abs($change) < $deadBand * 100 => TrendDirection::Steady,
            $change > 0 => TrendDirection::Rising,
            default => TrendDirection::Falling,
        };

        return new self(
            $metric,
            $current,
            $previous,
            $direction,
            $change,
            $sampleSize,
            sprintf(
                '%s vs %s in the previous period (%+.1f%%).',
                self::format($current),
                self::format($previous),
                $change,
            ),
        );
    }

    /**
     * Percentage change, or null when there is no baseline to change from.
     *
     * Growth from zero is not "infinite percent"; it is a first observation,
     * and reporting it as a percentage is how dashboards end up displaying
     * "+∞%". Null says what actually happened: there is nothing to compare to.
     */
    public static function changePercent(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? null : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    public function isReliable(): bool
    {
        return $this->direction !== TrendDirection::Insufficient;
    }

    private static function format(float $value): string
    {
        return $value == floor($value) ? (string) (int) $value : number_format($value, 1);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'current' => round($this->current, 2),
            'previous' => round($this->previous, 2),
            'direction' => $this->direction->value,
            'change_percent' => $this->changePercent,
            'sample_size' => $this->sampleSize,
            'reliable' => $this->isReliable(),
            'explanation' => $this->explanation,
        ];
    }
}
