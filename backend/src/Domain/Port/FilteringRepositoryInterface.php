<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Filtering\FilterDecision;
use DevRadar\Domain\Filtering\FilterableTweet;

/**
 * Persistence boundary for the pre-filter stage.
 *
 * Separate from the processing repository because the two change for
 * different reasons: normalization rules move independently of signal sets,
 * and neither stage should have to know the other's queries.
 */
interface FilteringRepositoryInterface
{
    /**
     * Deduplicated posts awaiting filtering, oldest first.
     *
     * @return list<FilterableTweet>
     */
    public function claimForFiltering(int $limit): array;

    /**
     * Record the outcome and the score that produced it.
     *
     * The breakdown is stored alongside the total: a score nobody can explain
     * is a score nobody can tune, and tuning the signal set is the entire
     * point of this layer.
     */
    public function saveDecision(int $tweetId, FilterDecision $decision): void;
}
