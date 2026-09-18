<?php

declare(strict_types=1);

namespace DevRadar\Domain\Classification;

/**
 * One post presented for classification.
 *
 * Carries the URLs found in the post so the parser can VALIDATE any link the
 * model returns against links that actually exist. A model will occasionally
 * invent a plausible repository URL, and an invented link is worse than no
 * link: it looks correct, it reaches the feed, and it goes nowhere.
 */
final readonly class ClassificationRequest
{
    /** @param list<string> $knownUrls */
    public function __construct(
        public int $tweetId,
        public string $text,
        public array $knownUrls = [],
        public ?string $lang = null,
        public bool $hasRepositoryLink = false,
    ) {}
}
