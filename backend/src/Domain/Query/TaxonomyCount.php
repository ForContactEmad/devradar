<?php

declare(strict_types=1);

namespace DevRadar\Domain\Query;

/**
 * A facet value and how many visible projects carry it.
 *
 * Counts are what make a filter usable: a category list without them offers
 * the user twelve choices, eight of which return nothing.
 */
final readonly class TaxonomyCount
{
    public function __construct(
        public string $slug,
        public string $name,
        public int $projectCount,
        public ?string $kind = null,
    ) {}
}
