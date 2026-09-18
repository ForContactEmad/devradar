<?php

declare(strict_types=1);

namespace DevRadar\Domain\Filtering;

/**
 * A post as the filter sees it.
 *
 * Carries the NORMALIZED text, because normalization has already removed the
 * invisible characters and entity encoding that would otherwise break phrase
 * matching -- "just&nbsp;launched" must fire the same signal as "just
 * launched". Re-normalising here would repeat work the previous stage
 * already did and paid for.
 */
final readonly class FilterableTweet
{
    public function __construct(
        public int $id,
        public string $normalizedText,
        public bool $hasLink = false,
        public bool $hasRepositoryLink = false,
        public bool $isRepost = false,
        public ?string $lang = null,
    ) {}

    public function text(): string
    {
        return $this->normalizedText;
    }
}
