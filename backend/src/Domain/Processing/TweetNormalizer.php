<?php

declare(strict_types=1);

namespace DevRadar\Domain\Processing;

/**
 * Normalizes one post. Pure, no I/O, no database.
 *
 * Composes two collaborators rather than doing the work itself: text rules
 * change for one set of reasons (a new invisible character, a new repost
 * prefix), URL rules for another (a new tracking parameter, a new repository
 * host). Keeping them apart means neither change touches the other.
 *
 * NOTHING IS DESTROYED. The original text is not passed through and modified;
 * it is read and left alone. Every output is additive and recomputable.
 *
 * REJECTION IS RECORDED, NOT DELETED. A post with no usable text or no link
 * is marked with a reason rather than dropped, because rejection data is the
 * raw material for the free pre-filter rules that run before any paid
 * inference. Throwing it away throws away the measurement.
 */
final readonly class TweetNormalizer
{
    public function __construct(
        private TextNormalizer $text = new TextNormalizer(),
        private UrlCanonicalizer $urls = new UrlCanonicalizer(),
        /**
         * A launch announcement with no link cannot be acted on and cannot be
         * deduplicated by URL. Requiring one is a policy choice, not a fact,
         * so it is configurable.
         */
        private bool $requireLink = true,
    ) {}

    public function normalize(ProcessableTweet $tweet): NormalizationResult
    {
        $readable = $this->text->readable($tweet->text);
        $isEmpty = $readable === '';

        $canonicalUrl = $this->urls->canonicalize($tweet->primaryUrl);
        $urlHash = $canonicalUrl === null ? null : hash('sha256', $canonicalUrl);
        $fingerprint = $this->text->fingerprint($tweet->text);

        return new NormalizationResult(
            normalizedText: $readable,
            canonicalUrl: $canonicalUrl,
            urlHash: $urlHash,
            textFingerprint: $fingerprint,
            hashtags: $this->text->hashtags($tweet->text),
            mentions: $this->text->mentions($tweet->text),
            isEmpty: $isEmpty,
            rejectReason: $this->rejectReason($tweet, $isEmpty, $canonicalUrl),
        );
    }

    /**
     * Reject reasons use the vocabulary from docs/query-set-v1.md, so
     * automated rejections and hand labels are counted together rather than
     * in two incompatible tallies.
     */
    private function rejectReason(ProcessableTweet $tweet, bool $isEmpty, ?string $canonicalUrl): ?string
    {
        if ($isEmpty) {
            return 'no-text';
        }

        if (! $this->requireLink) {
            return null;
        }

        if ($tweet->primaryUrl === null || trim($tweet->primaryUrl) === '') {
            return 'no-link';
        }

        // A link that is still a shortener was never unwound by the provider.
        // Its destination is unknown, so it cannot be deduplicated or judged,
        // and treating it as canonical would let two different pages collide.
        if ($canonicalUrl === null && $this->urls->isShortener($tweet->primaryUrl)) {
            return 'unresolved-link';
        }

        if ($canonicalUrl === null) {
            return 'no-link';
        }

        return null;
    }
}
