<?php

declare(strict_types=1);

namespace DevRadar\Application\Scoring;

use DevRadar\Domain\Port\ScoringRepositoryInterface;
use DevRadar\Domain\Scoring\ScoringEngine;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes scores for projects whose ranking has gone stale.
 *
 * RUNS ON ITS OWN SCHEDULE, not only after publication. Engagement matures
 * across the seven-day window: a post scored two hours after launch has
 * almost no metrics, and if that score stuck it would be permanently
 * outranked by older projects that simply had more time to accumulate. The
 * sweep is what makes the ranking a moving picture rather than a snapshot of
 * each project's first two hours.
 *
 * FREE TO RE-RUN. Scoring costs no API calls and no model calls -- it is
 * arithmetic over data already stored. That is why it can run every few
 * minutes without a budget guard, unlike every other stage in this pipeline.
 */
final readonly class ScoringRunner
{
    public function __construct(
        private ScoringRepositoryInterface $repository,
        private ScoringEngine $engine,
        private LoggerInterface $logger,
        private int $staleAfterMinutes = 180,
        private int $snapshotEveryMinutes = 180,
        private int $windowHours = 168,
    ) {}

    /** @return array<string, mixed> */
    public function run(int $limit = 200): array
    {
        $agedOut = $this->repository->ageOutBeyondWindow($this->windowHours);
        $batch = $this->repository->claimForScoring($limit, $this->staleAfterMinutes);

        if ($batch === []) {
            return ['claimed' => 0, 'scored' => 0, 'failed' => 0, 'aged_out' => $agedOut, 'average_score' => 0.0];
        }

        $scored = 0;
        $failed = 0;
        $total = 0.0;
        $unavailable = [];

        foreach ($batch as $projectId => $input) {
            try {
                $breakdown = $this->engine->score($input);

                $this->repository->saveScore($projectId, $breakdown);
                $this->repository->recordSnapshot($projectId, $input, $breakdown->score, $this->snapshotEveryMinutes);

                $scored++;
                $total += $breakdown->score;

                foreach ($breakdown->unavailableComponents() as $name) {
                    $unavailable[$name] = ($unavailable[$name] ?? 0) + 1;
                }
            } catch (Throwable $e) {
                // One project's bad data must not stop the sweep: the rest of
                // the feed would keep a stale ranking for no reason.
                $failed++;

                $this->logger->warning('scoring.project_failed', [
                    'project_id' => $projectId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $summary = [
            'claimed' => count($batch),
            'scored' => $scored,
            'failed' => $failed,
            'aged_out' => $agedOut,
            'average_score' => $scored > 0 ? round($total / $scored, 3) : 0.0,
            // Which signals are missing across the feed, which is how you
            // find out that enrichment has quietly stopped running.
            'unavailable_components' => $unavailable,
        ];

        $this->logger->info('scoring.complete', $summary);

        return $summary;
    }
}
