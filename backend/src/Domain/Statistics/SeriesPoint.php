<?php

declare(strict_types=1);

namespace DevRadar\Domain\Statistics;

/**
 * One point on a daily chart: a date and its value.
 *
 * The date is a Y-m-d string rather than a DateTimeImmutable because this is
 * a presentation value on its way to JSON; the charts bucket by calendar day
 * in UTC, and nothing downstream does arithmetic on it.
 */
final readonly class SeriesPoint
{
    public function __construct(public string $date, public float $value) {}

    /** @return array{date: string, value: float} */
    public function toArray(): array
    {
        return ['date' => $this->date, 'value' => round($this->value, 2)];
    }
}
