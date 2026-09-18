<?php

declare(strict_types=1);

namespace DevRadar\Domain\Query;

use DateTimeImmutable;

/**
 * A project as it appears in a list.
 *
 * Deliberately narrower than the detail view: a feed of twenty projects
 * should not carry twenty score breakdowns and twenty full descriptions. The
 * read model is shaped to the view rather than the table.
 */
final readonly class ProjectSummary
{
    /** @param list<string> $technologies */
    public function __construct(
        public string $slug,
        public string $name,
        public ?string $description,
        public string $category,
        public ?string $projectType,
        public array $technologies,
        public ?string $repositoryUrl,
        public ?string $websiteUrl,
        public ?string $demoUrl,
        public string $sourcePostUrl,
        public ?string $authorHandle,
        public float $score,
        public DateTimeImmutable $discoveredAt,
        public ?int $repositoryStars = null,
        /**
         * Total interactions on the source post.
         *
         * Denormalised onto the project, so a feed of twenty carries it
         * without twenty extra lookups.
         */
        public int $engagementCount = 0,
    ) {}
}
