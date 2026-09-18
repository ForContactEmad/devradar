<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\X;

use DateTimeImmutable;
use DateTimeZone;
use DevRadar\Domain\Ingestion\RawAuthor;
use DevRadar\Domain\Ingestion\RawPost;

/**
 * Translates X's wire format into DevRadar's own types.
 *
 * This class is the containment boundary for X's naming. Two renames in the
 * current API are absorbed here, both verified against the v2 OpenAPI
 * specification (version 2.168):
 *
 *   - public_metrics.repost_count  (was retweet_count in older versions)
 *   - post.referenced_posts        (was referenced_tweets)
 *
 * Older field names are still read as fallbacks, so a partially migrated
 * response does not silently map engagement to zero -- which would look like
 * an unpopular post rather than a bug.
 *
 * Mapping is deliberately forgiving. A single malformed post must not fail a
 * whole page: unmappable entries are skipped and reported, because the page
 * has already been paid for.
 */
final class XPostMapper
{
    /**
     * @param array<string, mixed> $payload decoded response body
     *
     * @return array{posts: list<RawPost>, skipped: list<string>}
     */
    public function mapPosts(array $payload): array
    {
        $posts = [];
        $skipped = [];

        foreach ($payload['data'] ?? [] as $item) {
            if (! is_array($item)) {
                $skipped[] = 'non-object entry in data array';

                continue;
            }

            $post = $this->mapPost($item);

            if ($post === null) {
                $skipped[] = (string) ($item['id'] ?? 'unknown id');

                continue;
            }

            $posts[] = $post;
        }

        return ['posts' => $posts, 'skipped' => $skipped];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, RawAuthor> keyed by author id
     */
    public function mapAuthors(array $payload): array
    {
        $authors = [];

        foreach ($payload['includes']['users'] ?? [] as $user) {
            if (! is_array($user) || ! isset($user['id'], $user['username'])) {
                continue;
            }

            $id = (string) $user['id'];

            $authors[$id] = new RawAuthor(
                id: $id,
                username: (string) $user['username'],
                displayName: isset($user['name']) ? (string) $user['name'] : null,
                verified: (bool) ($user['verified'] ?? false),
                followersCount: isset($user['public_metrics']['followers_count'])
                    ? (int) $user['public_metrics']['followers_count']
                    : null,
                rawPayload: $user,
            );
        }

        return $authors;
    }

    /**
     * Counts every billable resource in the response.
     *
     * Posts and users are billed separately and at different rates, so both
     * are counted. Using count($posts) alone would understate spend by the
     * number of author objects returned -- which are the more expensive of
     * the two per resource.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{posts: int, users: int, total: int}
     */
    public function countBillableResources(array $payload): array
    {
        $posts = is_array($payload['data'] ?? null) ? count($payload['data']) : 0;
        $users = is_array($payload['includes']['users'] ?? null) ? count($payload['includes']['users']) : 0;

        return ['posts' => $posts, 'users' => $users, 'total' => $posts + $users];
    }

    /**
     * X can return HTTP 200 with a partial `errors` array describing objects
     * it could not hydrate. Those are data problems, not request failures,
     * and must not abort a page that has already been charged for.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    public function partialErrors(array $payload): array
    {
        $errors = $payload['errors'] ?? [];

        return is_array($errors) ? array_values(array_filter($errors, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function mapPost(array $item): ?RawPost
    {
        if (! isset($item['id'], $item['text'])) {
            return null;
        }

        $createdAt = $this->parseTimestamp($item['created_at'] ?? null);

        if ($createdAt === null) {
            return null;
        }

        $metrics = is_array($item['public_metrics'] ?? null) ? $item['public_metrics'] : [];

        return new RawPost(
            id: (string) $item['id'],
            authorId: (string) ($item['author_id'] ?? ''),
            text: (string) $item['text'],
            lang: isset($item['lang']) ? (string) $item['lang'] : null,
            createdAt: $createdAt,
            likeCount: (int) ($metrics['like_count'] ?? 0),
            // repost_count is current; retweet_count is the legacy name.
            repostCount: (int) ($metrics['repost_count'] ?? $metrics['retweet_count'] ?? 0),
            replyCount: (int) ($metrics['reply_count'] ?? 0),
            quoteCount: (int) ($metrics['quote_count'] ?? 0),
            bookmarkCount: isset($metrics['bookmark_count']) ? (int) $metrics['bookmark_count'] : null,
            impressionCount: isset($metrics['impression_count']) ? (int) $metrics['impression_count'] : null,
            urls: $this->mapUrls($item),
            referencedPosts: $this->mapReferences($item),
            possiblySensitive: (bool) ($item['possibly_sensitive'] ?? false),
            rawPayload: $item,
        );
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return list<array{url: ?string, expanded: ?string, unwound: ?string}>
     */
    private function mapUrls(array $item): array
    {
        $urls = [];

        foreach ($item['entities']['urls'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $urls[] = [
                'url' => isset($entry['url']) ? (string) $entry['url'] : null,
                'expanded' => isset($entry['expanded_url']) ? (string) $entry['expanded_url'] : null,
                // X follows redirects itself and reports the final
                // destination here. Preferring it saves DevRadar a request
                // per link.
                'unwound' => isset($entry['unwound_url']) ? (string) $entry['unwound_url'] : null,
            ];
        }

        return $urls;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return list<array{type: string, id: string}>
     */
    private function mapReferences(array $item): array
    {
        // referenced_posts is current; referenced_tweets is the legacy name.
        $source = $item['referenced_posts'] ?? $item['referenced_tweets'] ?? [];
        $references = [];

        foreach ($source as $reference) {
            if (! is_array($reference) || ! isset($reference['type'], $reference['id'])) {
                continue;
            }

            $references[] = [
                'type' => (string) $reference['type'],
                'id' => (string) $reference['id'],
            ];
        }

        return $references;
    }

    private function parseTimestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
