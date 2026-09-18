<?php

declare(strict_types=1);

namespace DevRadar\Domain\Query;

use DateTimeImmutable;

/**
 * The full view of one project.
 *
 * Carries the score breakdown, because ranking is what people disagree with
 * and "why is this above that" should be answerable from the API rather than
 * only from the database.
 */
final readonly class ProjectDetail
{
    /**
     * @param list<array{slug: string, name: string, kind: ?string}> $technologies
     * @param array<string, mixed>|null                             $scoreBreakdown
     * @param list<array{captured_at: string, score: ?float, engagement: int}> $history
     */
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
        public ?array $scoreBreakdown,
        public ?float $extractionConfidence,
        public DateTimeImmutable $discoveredAt,
        public DateTimeImmutable $publishedAt,
        public ?RepositoryView $repository = null,
        public array $history = [],
    ) {}
}
