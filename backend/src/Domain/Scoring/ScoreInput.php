<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

use DateTimeImmutable;

/**
 * Everything the scoring engine is allowed to see.
 *
 * Deliberately a flat value object rather than a database row: the engine
 * must be callable with invented numbers in a test and with real ones in
 * production, and it must be impossible for a scorer to reach past its inputs
 * and query something.
 *
 * Every metric is nullable. Missing is a real state -- a post fetched before
 * its metrics matured, an author whose follower count was never expanded, a
 * project with no repository -- and the engine distinguishes missing from
 * zero throughout.
 */
final readonly class ScoreInput
{
    /** @param list<MetricSnapshot> $history oldest first */
    public function __construct(
        public DateTimeImmutable $postedAt,
        public DateTimeImmutable $now,
        public ?int $likeCount = null,
        public ?int $repostCount = null,
        public ?int $replyCount = null,
        public ?int $quoteCount = null,
        public ?int $bookmarkCount = null,
        public ?int $authorFollowers = null,
        public ?float $classificationConfidence = null,
        public ?float $extractionConfidence = null,
        public ?int $repositoryStars = null,
        public ?int $repositoryForks = null,
        public ?DateTimeImmutable $repositoryPushedAt = null,
        public array $history = [],
    ) {}

    public function ageInHours(): float
    {
        $seconds = $this->now->getTimestamp() - $this->postedAt->getTimestamp();

        // A post timestamped in the future is clock skew, not a prophecy.
        return max(0.0, $seconds / 3600);
    }

    public function hasAnyEngagementMetric(): bool
    {
        return $this->likeCount !== null
            || $this->repostCount !== null
            || $this->replyCount !== null
            || $this->quoteCount !== null
            || $this->bookmarkCount !== null;
    }

    public function hasRepository(): bool
    {
        return $this->repositoryStars !== null || $this->repositoryPushedAt !== null;
    }
}
