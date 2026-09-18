<?php

declare(strict_types=1);

namespace DevRadar\Domain\Query;

/**
 * A page of results with the information needed to navigate.
 *
 * Total count is included because the feed is small -- a seven-day window
 * holds tens of projects, not millions -- so COUNT is cheap and callers
 * benefit from knowing how many there are. On a large table this would be the
 * wrong trade and keyset pagination would replace it.
 *
 * @template T
 */
final readonly class Paginated
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function lastPage(): int
    {
        return $this->total === 0 ? 1 : (int) ceil($this->total / $this->perPage);
    }

    public function hasMore(): bool
    {
        return $this->page < $this->lastPage();
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * A page past the end is not an error.
     *
     * Returning 404 for page=99 would break clients that walk until empty,
     * and the collection itself plainly exists.
     */
    public function isBeyondEnd(): bool
    {
        return $this->page > $this->lastPage() && $this->total > 0;
    }
}
