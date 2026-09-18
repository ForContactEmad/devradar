<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Port\ProcessingRepositoryInterface;
use DevRadar\Domain\Processing\NormalizationResult;
use DevRadar\Domain\Processing\ProcessableTweet;
use DevRadar\Domain\Processing\SeenIndex;

/**
 * In-memory store mirroring the real repository's contract, including its
 * state transitions.
 */
final class InMemoryProcessingRepository implements ProcessingRepositoryInterface
{
    /** @var array<int, ProcessableTweet> */
    public array $raw = [];

    /** @var array<int, ProcessableTweet> */
    public array $normalized = [];

    /** @var array<int, NormalizationResult> */
    public array $results = [];

    /** @var array<int, array{survivor: int, level: string}> */
    public array $duplicates = [];

    /** @var list<int> */
    public array $deduplicated = [];

    /** Survivors already stored from earlier cycles. */
    public SeenIndex $preExisting;

    public function __construct()
    {
        $this->preExisting = new SeenIndex();
    }

    /** @param list<ProcessableTweet> $tweets */
    public function seedRaw(array $tweets): void
    {
        foreach ($tweets as $tweet) {
            $this->raw[$tweet->id] = $tweet;
        }
    }

    /** @param list<ProcessableTweet> $tweets */
    public function seedNormalized(array $tweets): void
    {
        foreach ($tweets as $tweet) {
            $this->normalized[$tweet->id] = $tweet;
        }
    }

    public function claimForNormalization(int $limit): array
    {
        return array_slice(array_values($this->raw), 0, $limit);
    }

    public function saveNormalization(int $tweetId, NormalizationResult $result): void
    {
        $this->results[$tweetId] = $result;
        $tweet = $this->raw[$tweetId] ?? null;
        unset($this->raw[$tweetId]);

        if ($tweet !== null && ! $result->isRejected()) {
            $this->normalized[$tweetId] = $tweet->withDerived($result);
        }
    }

    public function claimForDeduplication(int $limit): array
    {
        return array_slice(array_values($this->normalized), 0, $limit);
    }

    public function loadSeenIndexFor(array $batch): SeenIndex
    {
        return $this->preExisting;
    }

    public function markDuplicate(int $tweetId, int $survivorId, string $matchLevel): void
    {
        $this->duplicates[$tweetId] = ['survivor' => $survivorId, 'level' => $matchLevel];
        unset($this->normalized[$tweetId]);
    }

    public function markDeduplicated(int $tweetId): void
    {
        $this->deduplicated[] = $tweetId;
        unset($this->normalized[$tweetId]);
    }
}
