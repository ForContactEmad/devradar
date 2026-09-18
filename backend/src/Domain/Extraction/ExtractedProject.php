<?php

declare(strict_types=1);

namespace DevRadar\Domain\Extraction;

use DateTimeImmutable;

/**
 * A structured project, ready to publish.
 *
 * Every URL field is nullable and every one has been validated against links
 * that actually appear in the source post. A null is honest; an invented link
 * looks correct, reaches the feed and goes nowhere.
 */
final readonly class ExtractedProject
{
    /** @param list<DetectedTechnology> $technologies */
    public function __construct(
        public int $tweetId,
        public string $name,
        public ?string $description,
        public string $category,
        public ?string $projectType,
        public array $technologies,
        public ?string $repositoryUrl,
        public ?string $websiteUrl,
        public ?string $demoUrl,
        public ?string $authorHandle,
        public string $sourcePostUrl,
        public DateTimeImmutable $publishedAt,
        public float $confidence,
    ) {}

    /** @return list<string> */
    public function technologySlugs(): array
    {
        return array_map(fn (DetectedTechnology $t) => $t->slug, $this->technologies);
    }

    public function primaryUrl(): string
    {
        // Repository first: it is the most useful destination for the target
        // reader and the most stable identity for deduplication.
        return $this->repositoryUrl ?? $this->websiteUrl ?? $this->demoUrl ?? $this->sourcePostUrl;
    }
}
