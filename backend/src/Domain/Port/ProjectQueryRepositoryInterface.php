<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Query\Paginated;
use DevRadar\Domain\Query\ProjectDetail;
use DevRadar\Domain\Query\ProjectQuery;
use DevRadar\Domain\Query\Statistics;
use DevRadar\Domain\Query\TaxonomyCount;

/**
 * The read side.
 *
 * Strictly separate from the write-side repositories. The read path queries a
 * precomputed serving table and NOTHING ELSE -- it cannot reach a provider,
 * a model, or the pipeline, which is what makes a user request structurally
 * incapable of spending money.
 *
 * All SQL lives behind this interface. A controller that assembled a query
 * would put a business rule -- what "visible" means, what the window is -- in
 * a place where it could not be reused or tested.
 *
 * @phpstan-type SummaryPage Paginated<\DevRadar\Domain\Query\ProjectSummary>
 */
interface ProjectQueryRepositoryInterface
{
    /** @return Paginated<\DevRadar\Domain\Query\ProjectSummary> */
    public function search(ProjectQuery $query): Paginated;

    /** Null when no visible project has that slug. */
    public function findBySlug(string $slug): ?ProjectDetail;

    /** @return list<TaxonomyCount> */
    public function categories(): array;

    /**
     * @param  int|null $limit null for all
     * @return list<TaxonomyCount>
     */
    public function technologies(?int $limit = null): array;

    public function statistics(int $windowDays): Statistics;
}
