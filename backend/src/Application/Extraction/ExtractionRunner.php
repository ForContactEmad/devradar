<?php

declare(strict_types=1);

namespace DevRadar\Application\Extraction;

use DevRadar\Domain\Port\BudgetGuardInterface;
use DevRadar\Domain\Port\ProjectRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs extraction over a batch of classified posts.
 *
 * Orchestration only: claim, extract, publish. The three concerns stay in
 * three components -- extraction asks the model, validation decides what may
 * be asserted, the repository stores it -- and this class owns nothing but
 * the order.
 */
final readonly class ExtractionRunner
{
    public function __construct(
        private ProjectRepositoryInterface $repository,
        private ProjectExtractor $extractor,
        private BudgetGuardInterface $budget,
        private LoggerInterface $logger,
        private int $budgetUnitsPerCall = 1,
    ) {}

    /** @return array<string, mixed> */
    public function run(int $limit = 25): array
    {
        $batch = $this->repository->claimForExtraction($limit);

        if ($batch === []) {
            return $this->summary(0, 0, 0, 0, 0, false);
        }

        $published = 0;
        $rejected = 0;
        $failed = 0;
        $corrected = 0;
        $reasons = [];
        $budgetHalted = false;

        foreach ($batch as $candidate) {
            if (! $this->budget->allows($this->budgetUnitsPerCall)) {
                $this->logger->warning('extraction.budget_halted', [
                    'processed' => $published + $rejected + $failed,
                ]);

                $budgetHalted = true;
                break;
            }

            $result = $this->extractor->extract($candidate);
            $this->budget->record($this->budgetUnitsPerCall);

            if ($result->hasCorrections()) {
                $corrected++;
            }

            try {
                if (! $result->isValid) {
                    $this->repository->recordExtractionFailure($candidate->tweetId, $result->rejectReason);
                    $reasons[$result->rejectReason] = ($reasons[$result->rejectReason] ?? 0) + 1;
                    $rejected++;

                    continue;
                }

                $this->repository->publish($result->project);
                $published++;
            } catch (Throwable $e) {
                // Two paid model calls have already been spent on this post
                // by now, so a storage failure is expensive and must be loud.
                $this->logger->error('extraction.persistence_failed', [
                    'tweet_id' => $candidate->tweetId,
                    'error' => $e->getMessage(),
                ]);

                $failed++;
            }
        }

        $summary = $this->summary(count($batch), $published, $rejected, $failed, $corrected, $budgetHalted);
        $summary['reject_reasons'] = $reasons;

        $this->logger->info('extraction.complete', $summary);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function summary(int $claimed, int $published, int $rejected, int $failed, int $corrected, bool $halted): array
    {
        return [
            'claimed' => $claimed,
            'published' => $published,
            'rejected' => $rejected,
            'failed' => $failed,
            // A rising correction rate means the prompt is drifting from what
            // the validator will accept, and it shows up here first.
            'corrected' => $corrected,
            'budget_halted' => $halted,
        ];
    }
}
