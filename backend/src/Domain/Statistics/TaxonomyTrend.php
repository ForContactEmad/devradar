<?php

declare(strict_types=1);

namespace DevRadar\Domain\Statistics;

/**
 * A category or technology with its share and its movement.
 *
 * Share matters as much as count: "Rust, 4 projects" is less informative than
 * "Rust, 4 projects, 18% of the week" when the week has 22 projects and last
 * week it was 5%.
 */
final readonly class TaxonomyTrend
{
    public function __construct(
        public string $slug,
        public string $name,
        public int $count,
        public int $previousCount,
        public float $share,
        public TrendDirection $direction,
        public ?string $kind = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'count' => $this->count,
            'previous_count' => $this->previousCount,
            'share' => round($this->share, 3),
            'direction' => $this->direction->value,
            'kind' => $this->kind,
        ];
    }
}
