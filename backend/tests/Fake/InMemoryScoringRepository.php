<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Port\ScoringRepositoryInterface;
use DevRadar\Domain\Scoring\ScoreBreakdown;
use DevRadar\Domain\Scoring\ScoreInput;
use RuntimeException;

final class InMemoryScoringRepository implements ScoringRepositoryInterface
{
    /** @var array<int, ScoreInput> */
    public array $due = [];

    /** @var array<int, ScoreBreakdown> */
    public array $saved = [];

    /** @var list<int> */
    public array $snapshots = [];

    public int $agedOut = 0;

    /** Project ids whose save should blow up, to test isolation. */
    public array $failOn = [];

    public function claimForScoring(int $limit, int $staleAfterMinutes): array
    {
        return array_slice($this->due, 0, $limit, preserve_keys: true);
    }

    public function saveScore(int $projectId, ScoreBreakdown $breakdown): void
    {
        if (in_array($projectId, $this->failOn, true)) {
            throw new RuntimeException("database unavailable for project {$projectId}");
        }

        $this->saved[$projectId] = $breakdown;
    }

    public function recordSnapshot(int $projectId, ScoreInput $input, float $score, int $everyMinutes): void
    {
        $this->snapshots[] = $projectId;
    }

    public function ageOutBeyondWindow(int $windowHours): int
    {
        return $this->agedOut;
    }
}
