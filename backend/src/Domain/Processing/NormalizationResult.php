<?php

declare(strict_types=1);

namespace DevRadar\Domain\Processing;

/**
 * What normalization derived from one post.
 *
 * Everything here is DERIVED. The original text is untouched in `tweets.text`
 * and in `raw_payload`; this is the layer that can be recomputed and thrown
 * away when a rule changes, which is exactly why it must not be the only
 * copy of anything.
 */
final readonly class NormalizationResult
{
    /**
     * @param list<string> $hashtags
     * @param list<string> $mentions
     */
    public function __construct(
        public string $normalizedText,
        public ?string $canonicalUrl,
        public ?string $urlHash,
        public ?string $textFingerprint,
        public array $hashtags,
        public array $mentions,
        public bool $isEmpty,
        public ?string $rejectReason = null,
    ) {}

    public function isRejected(): bool
    {
        return $this->rejectReason !== null;
    }

    /** True when neither a URL nor a fingerprint is available to match on. */
    public function hasNoMatchableSignal(): bool
    {
        return $this->urlHash === null && $this->textFingerprint === null;
    }
}
