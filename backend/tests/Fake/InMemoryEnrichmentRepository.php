<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Enrichment\EnrichmentTarget;
use DevRadar\Domain\Enrichment\RepositoryFacts;
use DevRadar\Domain\Port\EnrichmentRepositoryInterface;
use RuntimeException;

final class InMemoryEnrichmentRepository implements EnrichmentRepositoryInterface
{
    /** @var list<EnrichmentTarget> */
    public array $due = [];

    /** @var array<int, RepositoryFacts> */
    public array $stored = [];

    /** @var list<int> */
    public array $touched = [];

    /** @var array<int, string> */
    public array $gone = [];

    /** @var array<int, string> */
    public array $failures = [];

    public int $linkedCount = 0;

    public bool $failOnStore = false;

    public function claimForEnrichment(int $limit, int $refreshAfterMinutes, int $maxFailures): array
    {
        return array_slice($this->due, 0, $limit);
    }

    public function linkPendingProjects(int $limit): int
    {
        return $this->linkedCount;
    }

    public function storeFacts(int $repositoryId, RepositoryFacts $facts): void
    {
        if ($this->failOnStore) {
            throw new RuntimeException('simulated database failure');
        }

        $this->stored[$repositoryId] = $facts;
    }

    public function touchUnchanged(int $repositoryId): void
    {
        $this->touched[] = $repositoryId;
    }

    public function markGone(int $repositoryId, string $reason): void
    {
        $this->gone[$repositoryId] = $reason;
    }

    public function recordFailure(int $repositoryId, string $reason): void
    {
        $this->failures[$repositoryId] = $reason;
    }
}
