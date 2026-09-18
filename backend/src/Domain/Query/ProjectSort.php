<?php

declare(strict_types=1);

namespace DevRadar\Domain\Query;

/**
 * How a project collection is ordered.
 *
 * "Trending" and "latest" are ORDERINGS OF ONE COLLECTION, not separate
 * resources, so they are sort values rather than their own endpoints. A
 * /projects/trending route would be a second URL for the same set of things,
 * and consumers would then have to learn which filters work on which one.
 *
 * The distinction that matters:
 *
 *   Score    - the composite ranking. The default, and what the feed means.
 *   Trending - growth-weighted: what is accelerating right now, which is a
 *              different question from what is currently biggest.
 *   Latest   - publication order, ignoring quality entirely.
 *   Relevance- only meaningful with a search term, and rejected without one
 *              rather than silently falling back.
 */
enum ProjectSort: string
{
    case Score = 'score';
    case Trending = 'trending';
    case Latest = 'latest';
    case Oldest = 'oldest';
    case Engagement = 'engagement';
    case Relevance = 'relevance';

    public function requiresSearchTerm(): bool
    {
        return $this === self::Relevance;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
