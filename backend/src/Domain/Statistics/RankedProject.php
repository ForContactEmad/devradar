<?php

declare(strict_types=1);

namespace DevRadar\Domain\Statistics;

/**
 * A project as it appears in a statistics ranking -- the top-N lists on the
 * stats page.
 *
 * Deliberately smaller than the full project resource: a ranking needs a name,
 * a slug to link to and the value it was ranked by, not the whole record.
 */
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
