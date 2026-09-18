<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

use DateTimeImmutable;

/**
 * One historical measurement, used to compute growth.
 *
 * Mirrors a row of project_metrics, which is append-only for exactly this
 * reason: growth cannot be computed from a table that overwrites.
 */
final readonly class MetricSnapshot
{
    public function __construct(
        public DateTimeImmutable $capturedAt,
        public int $likeCount = 0,
        public int $repostCount = 0,
        public int $replyCount = 0,
        public int $quoteCount = 0,
        public ?int $repositoryStars = null,
    ) {}

    public function totalEngagement(): int
    {
        return $this->likeCount + $this->repostCount + $this->replyCount + $this->quoteCount;
    }
}
