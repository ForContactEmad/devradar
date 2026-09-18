<?php

declare(strict_types=1);

namespace DevRadar\Domain\Processing;

/**
 * Whether a post is a duplicate, and on what evidence.
 *
 * The MATCH LEVEL is recorded, not just the verdict. Without it there is no
 * way to answer "how much of our duplicate rate is the same post arriving
 * twice, versus five accounts announcing one project" -- and those two are
 * completely different findings. The first says queries overlap; the second
 * says the product is working.
 */
final readonly class DuplicateDecision
{
    public const LEVEL_TWEET_ID = 'tweet_id';
    public const LEVEL_URL = 'canonical_url';
    public const LEVEL_TEXT = 'text_fingerprint';

    private function __construct(
        public bool $isDuplicate,
        public ?int $survivorId = null,
        public ?string $matchLevel = null,
    ) {}

    public static function unique(): self
    {
        return new self(false);
    }

    public static function duplicateOf(int $survivorId, string $matchLevel): self
    {
        return new self(true, $survivorId, $matchLevel);
    }

    /**
     * A same-id match means the post was already processed, not that two
     * posts describe one project. The pipeline treats them differently:
     * one is a wasted purchase, the other is a project with several sources.
     */
    public function isSamePost(): bool
    {
        return $this->matchLevel === self::LEVEL_TWEET_ID;
    }
}
