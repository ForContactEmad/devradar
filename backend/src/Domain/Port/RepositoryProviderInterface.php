<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Enrichment\RepositoryLookup;
use DevRadar\Domain\Enrichment\RepositoryRef;

/**
 * Boundary for source-code repository facts.
 *
 * ENRICHMENT IS OFF THE CRITICAL PATH. A project publishes without repository
 * data and is enriched afterwards. Implementations must therefore never throw
 * for an unavailable provider: every failure is an outcome the caller records
 * and moves past. GitHub being down must not stop the feed.
 *
 * Nothing above this port knows GitHub exists. The dashboard reads stored
 * rows and never reaches this interface at all.
 */
interface RepositoryProviderInterface
{
    /**
     * Look up a repository, optionally conditionally.
     *
     * $knownEtag turns the request into a conditional one. When the provider
     * answers 304 the stored data is still correct and -- when authenticated
     * -- the request costs no quota.
     */
    public function lookup(RepositoryRef $ref, ?string $knownEtag = null): RepositoryLookup;

    /** Remaining quota, if the provider has reported it. */
    public function remainingQuota(): ?int;

    public function name(): string;
}
