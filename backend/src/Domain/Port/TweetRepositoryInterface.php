<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Ingestion\PostBatch;

/**
 * Persistence boundary for collected posts.
 *
 * Storage MUST be idempotent. The same post arriving twice -- from a retry,
 * from overlapping queries, or from a re-run of a failed stage -- must store
 * once and report the duplicate rather than raising. The unique constraint on
 * the post id is the guarantee; this interface is how the collector learns
 * what it actually gained.
 */
interface TweetRepositoryInterface
{
    /**
     * Store a batch, skipping posts already held.
     *
     * @return StoreResult counts of what was newly stored versus already known
     */
    public function store(PostBatch $batch, ?int $searchRunId): StoreResult;

    /**
     * The highest post id seen by a SUCCESSFUL run of a given query.
     *
     * This is what makes collection incremental: the next run starts here
     * instead of re-fetching -- and re-paying for -- posts already held.
     *
     * Read from the run ledger rather than by scanning stored posts. Scanning
     * is O(posts) per query per cycle and grows without bound; the ledger
     * holds one row per run and answers the same question. Only completed
     * runs count, so a run whose storage failed does not advance the mark and
     * its window is retried.
     */
    public function highestSeenId(int $searchQueryId): ?string;
}
