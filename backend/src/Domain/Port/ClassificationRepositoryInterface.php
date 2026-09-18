<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Classification\ClassificationRequest;
use DevRadar\Domain\Classification\ClassificationResult;

/**
 * Persistence boundary for AI classification.
 *
 * Analyses are INSERTED, never updated. Re-classifying a post under a new
 * prompt adds a row and clears is_current on the old one, so a past ranking
 * stays explainable after the classifier has moved on.
 */
interface ClassificationRepositoryInterface
{
    /**
     * Posts that passed the pre-filter and await classification, oldest
     * first.
     *
     * @return list<ClassificationRequest>
     */
    public function claimForClassification(int $limit): array;

    public function saveAnalysis(ClassificationResult $result): void;

    /**
     * Return posts abandoned mid-classification to the claimable state.
     *
     * A worker killed after claiming leaves rows nothing will pick up again.
     * They were already paid for at collection; losing them silently is the
     * expensive kind of bug.
     */
    public function releaseStaleClaims(int $olderThanMinutes): int;

    /**
     * Total AI spend in the current billing cycle.
     *
     * Read by the budget guard so model spend and post-retrieval spend share
     * one ceiling. Two separate budgets would each look healthy while the
     * combined total ran over.
     */
    public function cycleSpendUsd(): float;
}
