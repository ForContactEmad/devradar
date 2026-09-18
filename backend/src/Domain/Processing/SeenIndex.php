<?php

declare(strict_types=1);

namespace DevRadar\Domain\Processing;

/**
 * An in-memory index of what the deduplicator has already seen.
 *
 * Exists so the deduplicator can stay pure. The runner fills it from the
 * database for the batch's candidate keys and then adds to it as the batch is
 * processed -- which is what catches duplicates WITHIN one batch, not just
 * against history. Two queries in the same cycle surfacing one project is the
 * normal case, and a database-only lookup would miss it because neither row
 * is committed as a survivor yet.
 */
final class SeenIndex
{
    /** @var array<string, int> x_tweet_id => local id */
    private array $byTweetId = [];

    /** @var array<string, int> url hash => local id */
    private array $byUrlHash = [];

    /** @var array<string, int> text fingerprint => local id */
    private array $byFingerprint = [];

    public function rememberTweetId(string $xTweetId, int $localId): void
    {
        $this->byTweetId[$xTweetId] ??= $localId;
    }

    public function rememberUrlHash(string $urlHash, int $localId): void
    {
        // First writer wins: the earliest post about a project is the
        // survivor, and later ones attach to it.
        $this->byUrlHash[$urlHash] ??= $localId;
    }

    public function rememberFingerprint(string $fingerprint, int $localId): void
    {
        $this->byFingerprint[$fingerprint] ??= $localId;
    }

    public function remember(ProcessableTweet $tweet): void
    {
        $this->rememberTweetId($tweet->xTweetId, $tweet->id);

        if ($tweet->urlHash !== null) {
            $this->rememberUrlHash($tweet->urlHash, $tweet->id);
        }

        if ($tweet->textFingerprint !== null) {
            $this->rememberFingerprint($tweet->textFingerprint, $tweet->id);
        }
    }

    public function tweetId(string $xTweetId): ?int
    {
        return $this->byTweetId[$xTweetId] ?? null;
    }

    public function urlHash(string $urlHash): ?int
    {
        return $this->byUrlHash[$urlHash] ?? null;
    }

    public function fingerprint(string $fingerprint): ?int
    {
        return $this->byFingerprint[$fingerprint] ?? null;
    }

    public function size(): int
    {
        return count($this->byTweetId);
    }
}
