<?php

declare(strict_types=1);

namespace DevRadar\Domain\Statistics;

final readonly class SeriesPoint
{
    public function __construct(public string $date, public float $value) {}

    /** @return array{date: string, value: float} */
    public function toArray(): array
    {
        return ['date' => $this->date, 'value' => round($this->value, 2)];
    }
}
