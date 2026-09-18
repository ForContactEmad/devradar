<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DateTimeImmutable;

/**
 * A classified post awaiting extraction.
 *
 * knownUrls is the whitelist the validator checks model output against, and
 * it is the reason extraction cannot invent a link.
 */
final readonly class ExtractionCandidate
{
    /** @param list<string> $knownUrls */
    public function __construct(
        public int $tweetId,
        public string $text,
        public array $knownUrls,
        public ?string $authorHandle,
        public string $sourcePostUrl,
        public DateTimeImmutable $postedAt,
        public ?float $classificationConfidence = null,
        public ?string $lang = null,
    ) {}
}
