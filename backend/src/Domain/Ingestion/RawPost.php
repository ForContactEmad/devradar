<?php

declare(strict_types=1);

namespace DevRadar\Domain\Ingestion;

use DateTimeImmutable;

/**
 * A post as returned by a provider, mapped into DevRadar's own shape.
 *
 * Field names here are DevRadar's, not X's. X currently calls the repost
 * counter `repost_count`; it was `retweet_count` in older versions of the API
 * and may be renamed again. Absorbing that churn in the mapper is why this
 * type exists.
 */
final readonly class RawPost
{
    /**
     * @param list<array{url: ?string, expanded: ?string, unwound: ?string}> $urls
     * @param list<array{type: string, id: string}>                          $referencedPosts
     * @param array<string, mixed>                                           $rawPayload
     */
    public function __construct(
        public string $id,
        public string $authorId,
        public string $text,
        public ?string $lang,
        public DateTimeImmutable $createdAt,
        public int $likeCount,
        public int $repostCount,
        public int $replyCount,
        public int $quoteCount,
        public ?int $bookmarkCount,
        public ?int $impressionCount,
        public array $urls,
        public array $referencedPosts,
        public bool $possiblySensitive,
        public array $rawPayload,
    ) {}

    /** True when this post is a repost of another. */
    public function isRepost(): bool
    {
        foreach ($this->referencedPosts as $ref) {
            if ($ref['type'] === 'retweeted') {
                return true;
            }
        }

        return false;
    }

    public function isReply(): bool
    {
        foreach ($this->referencedPosts as $ref) {
            if ($ref['type'] === 'replied_to') {
                return true;
            }
        }

        return false;
    }

    /**
     * Best available link target, preferring the provider's own resolved URL.
     *
     * X unwinds shortened links itself and reports the final destination in
     * `unwound_url`. Using it saves DevRadar a redirect-following HTTP request
     * per post, which matters at volume.
     */
    /**
     * The best link in the post, if it is one we are willing to store.
     *
     * SCHEME-CHECKED. This value is stored verbatim and eventually rendered
     * into an href, and it previously accepted whatever the provider sent.
     * Every downstream consumer would have had to remember to re-check it;
     * checking once, here, means none of them has to.
     */
    public function bestUrl(): ?string
    {
        foreach ($this->urls as $url) {
            $candidate = $url['unwound'] ?? $url['expanded'] ?? $url['url'] ?? null;

            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $scheme = strtolower((string) (parse_url($candidate, PHP_URL_SCHEME) ?? ''));

            // http(s) only. javascript:, data: and vbscript: are the ones
            // that become script when rendered; everything else is simply
            // not a link we can usefully show.
            if (in_array($scheme, ['http', 'https'], true)) {
                return $candidate;
            }
        }

        return null;
    }
}
