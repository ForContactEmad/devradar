<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Enrichment\EnrichmentTarget;
use DevRadar\Domain\Enrichment\RepositoryFacts;

/**
 * Persistence boundary for repository enrichment.
 *
 * PERSISTED, NOT FETCHED ON READ. The dashboard reads stored rows; nothing on
 * the read path reaches GitHub. That is why enrichment is a background stage
 * with a refresh strategy rather than a lookup helper.
 */
interface EnrichmentRepositoryInterface
{
    /**
     * Repositories due for a fetch, most valuable first.
     *
     * @return list<EnrichmentTarget>
     */
    public function claimForEnrichment(int $limit, int $refreshAfterMinutes, int $maxFailures): array;

    /** Link a project's repository URL to a repositories row, creating it if needed. */
    public function linkPendingProjects(int $limit): int;

    public function storeFacts(int $repositoryId, RepositoryFacts $facts): void;

    /** A 304: the data is still correct, only the freshness timestamp moves. */
    public function touchUnchanged(int $repositoryId): void;

    /** Permanent failure: stop asking about this repository. */
    public function markGone(int $repositoryId, string $reason): void;

    /** Transient failure: back off, but keep it in the queue. */
    public function recordFailure(int $repositoryId, string $reason): void;
}
