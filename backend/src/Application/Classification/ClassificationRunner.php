<?php

declare(strict_types=1);

namespace DevRadar\Application\Classification;

use DevRadar\Domain\Classification\ClassificationOutcome;
use DevRadar\Domain\Port\BudgetGuardInterface;
use DevRadar\Domain\Port\ClassificationRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs classification over a batch of filtered posts.
 *
 * ONE POST PER CALL, deliberately. Batching several posts into one prompt
 * saves input tokens on the system instructions, but introduces an alignment
 * risk: if the model returns four verdicts for five posts, every verdict
 * after the gap is attributed to the wrong post. That failure is silent, it
 * corrupts the labelled evaluation set, and it is worth far more than the
 * tokens it saves. Throughput comes from queue concurrency instead.
 *
 * THE BUDGET GUARD IS CONSULTED PER POST. Model spend and post-retrieval
 * spend share one ceiling, because two separate budgets each look healthy
 * while the combined total runs over.
 */
final readonly class ClassificationRunner
{
    public function __construct(
        private ClassificationRepositoryInterface $repository,
        private AiClassifier $classifier,
        private BudgetGuardInterface $budget,
        private LoggerInterface $logger,
        /** Rough resource-equivalent of one classification, for the guard. */
        private int $budgetUnitsPerCall = 1,
    ) {}

    /** @return array<string, mixed> */
    public function run(int $limit = 50): array
    {
        $batch = $this->repository->claimForClassification($limit);

        if ($batch === []) {
            return $this->summary(0, 0, 0, 0, 0, 0, 0.0, false);
        }

        $accepted = 0;
        $rejected = 0;
        $lowConfidence = 0;
        $unparseable = 0;
        $failed = 0;
        $cost = 0.0;
        $budgetHalted = false;

        foreach ($batch as $request) {
            if (! $this->budget->allows($this->budgetUnitsPerCall)) {
                $this->logger->warning('classification.budget_halted', [
                    'processed' => $accepted + $rejected + $lowConfidence + $unparseable + $failed,
                    'remaining_in_batch' => count($batch) - ($accepted + $rejected + $lowConfidence + $unparseable + $failed),
                ]);

                $budgetHalted = true;
                break;
            }

            $result = $this->classifier->classify($request);

            // Persist before counting. A crash after a paid call must not
            // lose the verdict it bought.
            try {
                $this->repository->saveAnalysis($result);
            } catch (Throwable $e) {
                $this->logger->error('classification.persistence_failed', [
                    'tweet_id' => $request->tweetId,
                    'cost_usd' => $result->costUsd,
                    'error' => $e->getMessage(),
                ]);

                $failed++;

                continue;
            }

            $this->budget->record($this->budgetUnitsPerCall);
            $cost += $result->costUsd;

            match ($result->outcome) {
                ClassificationOutcome::Accepted => $accepted++,
                ClassificationOutcome::Rejected => $rejected++,
                ClassificationOutcome::LowConfidence => $lowConfidence++,
                ClassificationOutcome::Unparseable => $unparseable++,
                ClassificationOutcome::Failed => $failed++,
            };
        }

        $summary = $this->summary(
            count($batch), $accepted, $rejected, $lowConfidence, $unparseable, $failed, $cost, $budgetHalted,
        );

        $this->logger->info('classification.complete', $summary);

        return $summary;
    }

    /** @return array<string, mixed> */
    private function summary(
        int $claimed,
        int $accepted,
        int $rejected,
        int $lowConfidence,
        int $unparseable,
        int $failed,
        float $cost,
        bool $budgetHalted,
    ): array {
        $decided = $accepted + $rejected + $lowConfidence;

        return [
            'claimed' => $claimed,
            'accepted' => $accepted,
            'rejected' => $rejected,
            // Tracked separately: this is the population you sample when
            // deciding where the confidence threshold belongs.
            'low_confidence' => $lowConfidence,
            // A rising unparseable rate is the earliest signal of model drift
            // or a prompt regression.
            'unparseable' => $unparseable,
            'failed' => $failed,
            'cost_usd' => round($cost, 6),
            'acceptance_rate' => $decided > 0 ? round($accepted / $decided, 3) : 0.0,
            'budget_halted' => $budgetHalted,
        ];
    }
}
