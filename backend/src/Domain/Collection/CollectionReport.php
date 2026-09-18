<?php

declare(strict_types=1);

namespace DevRadar\Domain\Collection;

use DevRadar\Domain\Ingestion\StopReason;

/**
 * What one query cost and what it yielded.
 *
 * postsStored is deliberately separate from postsReturned. The gap between
 * them is the duplicate rate -- posts paid for and already held -- and it is
 * the number that decides whether a query is worth keeping. A query returning
 * 100 posts of which 95 are already stored is an expensive way to find five
 * things.
 */
final readonly class CollectionReport
{
    public function __construct(
        public string $queryLabel,
        public ?int $searchRunId,
        public string $status,
        public int $postsReturned,
        public int $postsStored,
        public int $authorsStored,
        public int $requestCount,
        public int $billableResources,
        public ?string $newestId,
        public ?StopReason $stopReason,
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
    ) {}

    public function isFailure(): bool
    {
        return $this->status === 'failed';
    }

    public function duplicateCount(): int
    {
        return max(0, $this->postsReturned - $this->postsStored);
    }
}
