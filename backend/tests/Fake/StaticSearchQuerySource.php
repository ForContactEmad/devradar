<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Collection\SearchQueryDefinition;
use DevRadar\Domain\Port\SearchQuerySourceInterface;

final readonly class StaticSearchQuerySource implements SearchQuerySourceInterface
{
    /** @param list<SearchQueryDefinition> $definitions */
    public function __construct(private array $definitions) {}

    public function activeQueries(): array
    {
        return $this->definitions;
    }
}
