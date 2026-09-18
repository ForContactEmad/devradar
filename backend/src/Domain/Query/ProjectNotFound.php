<?php

declare(strict_types=1);

namespace DevRadar\Domain\Query;

use RuntimeException;

/**
 * No visible project has this slug.
 *
 * Deliberately does not distinguish "never existed" from "aged out of the
 * window" or "hidden by an admin". All three are 404 to a consumer, and
 * telling them which would leak moderation decisions.
 */
final class ProjectNotFound extends RuntimeException
{
    public static function withSlug(string $slug): self
    {
        return new self(sprintf('No project found with slug "%s".', $slug));
    }
}
