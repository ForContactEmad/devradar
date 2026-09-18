<?php

declare(strict_types=1);

namespace DevRadar\Domain\Enrichment;

/**
 * What happened when a repository was looked up.
 *
 * Six outcomes rather than success/failure, because the pipeline responds
 * differently to each and collapsing them loses the distinction that matters:
 *
 *   Found       - fresh data, store it
 *   Unchanged   - a 304; the stored data is still correct, just touch the
 *                 timestamp. This is the cheap path and the one the refresh
 *                 strategy is built around.
 *   NotFound    - deleted, renamed or never existed. Permanent: retrying
 *                 spends quota on something that will never resolve.
 *   Private     - exists but is not visible to our token. Also permanent for
 *                 our purposes, and distinct from NotFound because a project
 *                 with a private repo is still a real project.
 *   RateLimited - retry after the window resets, not now.
 *   Failed      - transient; retry later.
 */
enum LookupOutcome: string
{
    case Found = 'found';
    case Unchanged = 'unchanged';
    case NotFound = 'not_found';
    case Private_ = 'private';
    case RateLimited = 'rate_limited';
    case Failed = 'failed';

    /** Should the pipeline stop asking about this repository? */
    public function isPermanent(): bool
    {
        return $this === self::NotFound || $this === self::Private_;
    }

    public function isSuccess(): bool
    {
        return $this === self::Found || $this === self::Unchanged;
    }
}
