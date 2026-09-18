<?php

declare(strict_types=1);

namespace DevRadar\Domain\Statistics;

use DateTimeImmutable;

/**
 * A metric over consecutive days.
 *
 * GAPS ARE FILLED WITH ZEROS, not omitted. A day on which nothing was
 * published is a real observation -- it is the quiet Sunday that makes the
 * busy Tuesday legible. Omitting it would draw a chart whose x-axis is
 * unevenly spaced while looking perfectly even, which is the most
 * straightforwardly misleading thing a time series can do.
 */
final readonly class TimeSeries
{
    /** @param list<SeriesPoint> $points */
    private function __construct(public string $metric, public array $points) {}

    /**
     * Build a dense series across every day in the range.
     *
     * @param array<string, float> $valuesByDate keyed Y-m-d; missing days become 0
     */
    public static function dense(
        string $metric,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $valuesByDate,
    ): self {
        $points = [];
        $cursor = $from->setTime(0, 0);
        $end = $to->setTime(0, 0);

        // Bounded: a runaway range would otherwise allocate without limit.
        $guard = 0;

        while ($cursor <= $end && $guard++ < 400) {
            $key = $cursor->format('Y-m-d');
            $points[] = new SeriesPoint($key, (float) ($valuesByDate[$key] ?? 0));
            $cursor = $cursor->modify('+1 day');
        }

        return new self($metric, $points);
    }

    public function total(): float
    {
        return array_sum(array_map(fn (SeriesPoint $p) => $p->value, $this->points));
    }

    public function peak(): ?SeriesPoint
    {
        if ($this->points === []) {
            return null;
        }

        return array_reduce(
            $this->points,
            fn (?SeriesPoint $best, SeriesPoint $p) => $best === null || $p->value > $best->value ? $p : $best,
        );
    }

    public function average(): float
    {
        return $this->points === [] ? 0.0 : round($this->total() / count($this->points), 2);
    }

    /**
     * The mean of the most recent N days.
     *
     * Used to compare a recent stretch against the one before it without
     * letting a single spike decide the answer.
     */
    public function tailAverage(int $days): float
    {
        $tail = array_slice($this->points, -$days);

        return $tail === [] ? 0.0 : array_sum(array_map(fn (SeriesPoint $p) => $p->value, $tail)) / count($tail);
    }

    public function headAverage(int $days): float
    {
        $head = array_slice($this->points, 0, $days);

        return $head === [] ? 0.0 : array_sum(array_map(fn (SeriesPoint $p) => $p->value, $head)) / count($head);
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric,
            'points' => array_map(fn (SeriesPoint $p) => $p->toArray(), $this->points),
            'total' => round($this->total(), 2),
            'average' => $this->average(),
            'peak' => $this->peak()?->toArray(),
        ];
    }
}
