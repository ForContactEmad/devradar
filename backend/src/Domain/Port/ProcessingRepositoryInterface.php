<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Processing\NormalizationResult;
use DevRadar\Domain\Processing\ProcessableTweet;
use DevRadar\Domain\Processing\SeenIndex;

/**
 * Persistence boundary for the processing stages.
 *
 * Each stage claims rows in its input state and writes them to the next.
 * Because ingestion is the only stage that costs money, a crash here is free
 * to retry -- the unprocessed remainder is simply still in its input state.
 */
interface ProcessingRepositoryInterface
{
    /**
     * Posts awaiting normalization, oldest first.
     *
     * Oldest first so that the original announcement becomes the survivor of
     * a duplicate group rather than whichever amplification arrived last.
     *
     * @return list<ProcessableTweet>
     */
    public function claimForNormalization(int $limit): array;

    public function saveNormalization(int $tweetId, NormalizationResult $result): void;

    /**
     * Posts awaiting deduplication, oldest first.
     *
     * @return list<ProcessableTweet>
     */
    public function claimForDeduplication(int $limit): array;

    /**
     * Pre-load an index with survivors already stored that share any of the
     * batch's keys.
     *
     * Scoped to the batch's keys rather than loading the whole window: the
     * index only has to answer questions this batch actually asks.
     *
     * @param list<ProcessableTweet> $batch
     */
    public function loadSeenIndexFor(array $batch): SeenIndex;

    public function markDuplicate(int $tweetId, int $survivorId, string $matchLevel): void;

    public function markDeduplicated(int $tweetId): void;
}
