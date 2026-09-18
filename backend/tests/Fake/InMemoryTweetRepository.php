<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Ingestion\PostBatch;
use DevRadar\Domain\Port\StoreResult;
use DevRadar\Domain\Port\TweetRepositoryInterface;
use RuntimeException;

/**
 * In-memory store that mirrors the real repository's contract: storing a post
 * already held is a no-op that is counted, never an error.
 */
final class InMemoryTweetRepository implements TweetRepositoryInterface
{
    /** @var array<string, true> */
    public array $storedPostIds = [];

    /** @var array<string, true> */
    public array $storedAuthorIds = [];

    /** @var array<int, string> query id => highest stored post id */
    public array $highWaterMarks = [];

    public bool $failOnStore = false;

    public function store(PostBatch $batch, ?int $searchRunId): StoreResult
    {
        if ($this->failOnStore) {
            throw new RuntimeException('simulated database failure');
        }

        $new = 0;
        $duplicates = 0;
        $newest = null;

        foreach ($batch->posts as $post) {
            if (isset($this->storedPostIds[$post->id])) {
                $duplicates++;

                continue;
            }

            $this->storedPostIds[$post->id] = true;
            $new++;

            if ($newest === null || $this->isNewer($post->id, $newest)) {
                $newest = $post->id;
            }
        }

        $authorsStored = 0;
        foreach ($batch->authors as $author) {
            $this->storedAuthorIds[$author->id] = true;
            $authorsStored++;
        }

        return new StoreResult($new, $duplicates, $authorsStored, $newest);
    }

    public function highestSeenId(int $searchQueryId): ?string
    {
        return $this->highWaterMarks[$searchQueryId] ?? null;
    }

    /** Post ids are numeric strings; comparison must not be lexicographic. */
    private function isNewer(string $candidate, string $current): bool
    {
        return strlen($candidate) > strlen($current)
            || (strlen($candidate) === strlen($current) && $candidate > $current);
    }
}
