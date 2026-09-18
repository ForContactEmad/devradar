<?php

declare(strict_types=1);

namespace DevRadar\Domain\Statistics;

final readonly class RankedProject
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $category,
        public float $score,
        public int $engagement,
        public ?int $stars,
        public string $discoveredAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'category' => $this->category,
            'score' => round($this->score, 2),
            'engagement' => $this->engagement,
            'stars' => $this->stars,
            'discovered_at' => $this->discoveredAt,
        ];
    }
}
