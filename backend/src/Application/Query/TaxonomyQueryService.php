<?php

declare(strict_types=1);

namespace DevRadar\Application\Query;

use DevRadar\Domain\Port\ProjectQueryRepositoryInterface;
use DevRadar\Domain\Query\TaxonomyCount;

/**
 * Categories and technologies, with the counts that make them usable as
 * filters.
 *
 * Counts are scoped to VISIBLE projects in the window, not to everything ever
 * stored. A category list offering twelve choices, eight of which return
 * nothing, is worse than no list.
 */
final readonly class TaxonomyQueryService
{
    public function __construct(private ProjectQueryRepositoryInterface $repository) {}

    /** @return list<TaxonomyCount> */
    public function categories(bool $includeEmpty = false): array
    {
        $categories = $this->repository->categories();

        if ($includeEmpty) {
            return $categories;
        }

        return array_values(array_filter($categories, fn (TaxonomyCount $c) => $c->projectCount > 0));
    }

    /** @return list<TaxonomyCount> */
    public function technologies(?int $limit = null): array
    {
        return $this->repository->technologies($limit);
    }
}
