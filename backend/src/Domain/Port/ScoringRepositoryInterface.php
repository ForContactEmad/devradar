<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Scoring\ScoreBreakdown;
use DevRadar\Domain\Scoring\ScoreInput;

/**
 * Persistence boundary for ranking.
 *
 * Scoring itself is pure and knows nothing about this interface; the runner
 * uses it to fetch inputs and write results. That separation is why the
 * formula can be exercised entirely with invented numbers.
 */
interface ScoringRepositoryInterface
{
    /**
     * Projects due for scoring, stalest first.
     *
     * Stalest first rather than newest first: a project scored three hours
     * ago has drifted furthest from reality, and newest-first would starve
     * older projects of rescoring entirely.
     *
     * @return array<int, ScoreInput> keyed by project id
     */
    public function claimForScoring(int $limit, int $staleAfterMinutes): array;

    public function saveScore(int $projectId, ScoreBreakdown $breakdown): void;

    /**
     * Append a metric snapshot, if one is not already recent.
     *
     * Snapshots are what make growth measurable at all, and the table is
     * append-only for that reason. Writing one per rescore would fill it with
     * near-identical rows, so the interval is enforced.
     */
    public function recordSnapshot(int $projectId, ScoreInput $input, float $score, int $everyMinutes): void;

    /** Mark projects past the rolling window so they leave the feed. */
    public function ageOutBeyondWindow(int $windowHours): int;
}
