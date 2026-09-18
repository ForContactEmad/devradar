<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Collection\SearchQueryDefinition;

/**
 * Where the active query set comes from.
 *
 * Behind this interface sits the database, because queries are business data
 * that changes without a deploy. The interface exists so the collector never
 * learns that, and so tests can supply a fixed set.
 */
interface SearchQuerySourceInterface
{
    /** @return list<SearchQueryDefinition> */
    public function activeQueries(): array;
}
