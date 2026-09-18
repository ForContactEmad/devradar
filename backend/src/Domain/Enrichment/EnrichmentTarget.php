<?php

declare(strict_types=1);

namespace DevRadar\Domain\Enrichment;

use DateTimeImmutable;

/**
 * A repository queued for enrichment, with what we already know about it.
 *
 * The stored ETag is the whole point: sending it turns the next fetch into a
 * conditional request, which is free for a token holder when nothing has
 * changed. Most repositories do not change between refreshes, so most
 * refreshes should cost nothing.
 */
final readonly class EnrichmentTarget
{
    public function __construct(
        public int $repositoryId,
        public RepositoryRef $ref,
        public ?string $etag = null,
        public ?DateTimeImmutable $lastFetchedAt = null,
        public int $failureCount = 0,
    ) {}
}
