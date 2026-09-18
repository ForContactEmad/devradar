<?php

declare(strict_types=1);

namespace DevRadar\Domain\Collection;

use DevRadar\Domain\Ingestion\SearchCriteria;

/**
 * One unit of work for the collector: which query, over what window, with
 * what criteria.
 *
 * Pairing the definition with the criteria keeps the collector from having to
 * know how criteria are built -- that is the strategy's job -- while still
 * letting the ledger record which versioned query produced which spend.
 */
final readonly class SearchPlan
{
    public function __construct(
        public SearchQueryDefinition $definition,
        public SearchCriteria $criteria,
        public CollectionWindow $window,
    ) {}
}
