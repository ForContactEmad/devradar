<?php

declare(strict_types=1);

namespace DevRadar\Domain\Collection;

use InvalidArgumentException;

/**
 * One versioned query from the query set.
 *
 * Queries are business DATA, not configuration and certainly not literals in
 * a service. They change constantly during tuning, their version is stamped
 * onto every run, and they are edited from the admin panel without a deploy.
 */
final readonly class SearchQueryDefinition
{
    public function __construct(
        public ?int $id,
        public string $name,
        public string $family,
        public string $expression,
        public int $version = 1,
        public int $maxResults = 100,
        public bool $isActive = true,
        public ?string $lastSeenId = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Query definition needs a name.');
        }

        if (trim($expression) === '') {
            throw new InvalidArgumentException("Query '{$name}' has an empty expression.");
        }
    }

    public function label(): string
    {
        return "{$this->name}@v{$this->version}";
    }
}
