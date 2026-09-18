<?php

declare(strict_types=1);

namespace DevRadar\Domain\Ingestion;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What to search for, expressed in DevRadar's terms.
 *
 * The mutual-exclusivity rules are enforced here rather than discovered as a
 * 400 from the provider, because a rejected request still costs a round trip
 * and an error branch. X documents three such pairs:
 *
 *   - at most one of start_time / since_id
 *   - at most one of end_time / until_id
 *   - at most one of pagination_token / next_token
 *
 * maxPosts and maxPages are DevRadar's own ceilings, not the provider's. They
 * are the in-code half of the two-brake spend design; the other half is the
 * spending limit set in the provider console.
 */
final readonly class SearchCriteria
{
    public function __construct(
        public string $query,
        public ?string $sinceId = null,
        public ?string $untilId = null,
        public ?DateTimeImmutable $startTime = null,
        public ?DateTimeImmutable $endTime = null,
        public int $pageSize = 100,
        public int $maxPosts = 300,
        public int $maxPages = 10,
        public ?string $sortOrder = null,
    ) {
        if (trim($query) === '') {
            throw new InvalidArgumentException('Search query cannot be empty.');
        }

        if ($sinceId !== null && $startTime !== null) {
            throw new InvalidArgumentException(
                'Provide either sinceId or startTime, not both: the provider rejects requests carrying both.'
            );
        }

        if ($untilId !== null && $endTime !== null) {
            throw new InvalidArgumentException(
                'Provide either untilId or endTime, not both: the provider rejects requests carrying both.'
            );
        }

        foreach (['sinceId' => $sinceId, 'untilId' => $untilId] as $name => $id) {
            if ($id !== null && preg_match('/^[0-9]{1,19}$/', $id) !== 1) {
                throw new InvalidArgumentException("{$name} must be a numeric post id of up to 19 digits.");
            }
        }

        if ($pageSize < 10 || $pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 10 and 100.');
        }

        if ($maxPosts < 1) {
            throw new InvalidArgumentException('maxPosts must be at least 1.');
        }

        if ($maxPages < 1) {
            throw new InvalidArgumentException('maxPages must be at least 1.');
        }

        if ($sortOrder !== null && ! in_array($sortOrder, ['recency', 'relevancy'], true)) {
            throw new InvalidArgumentException('sortOrder must be recency or relevancy.');
        }
    }

    public function withPageSize(int $pageSize): self
    {
        return new self(
            $this->query, $this->sinceId, $this->untilId, $this->startTime, $this->endTime,
            $pageSize, $this->maxPosts, $this->maxPages, $this->sortOrder,
        );
    }
}
