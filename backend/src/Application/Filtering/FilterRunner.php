<?php

declare(strict_types=1);

namespace DevRadar\Application\Filtering;

use DevRadar\Domain\Filtering\TweetFilter;
use DevRadar\Domain\Port\FilteringRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies the pre-filter to a batch of deduplicated posts.
 *
 * Orchestration only. The rules live in the filter, which is pure.
 *
 * THE NUMBER TO WATCH is the pass rate. It is logged on every batch because
 * it is the direct multiplier on AI spend: at 40% the classifier reads two in
 * five posts, at 90% the pre-filter is barely earning its place, and at 5% it
 * is almost certainly discarding real launches -- which cost money to collect
 * and cannot be recovered.
 */
final readonly class FilterRunner
{
    public function __construct(
        private FilteringRepositoryInterface $repository,
        private TweetFilter $filter,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, mixed> */
    public function run(int $limit = 200): array
    {
        $batch = $this->repository->claimForFiltering($limit);

        if ($batch === []) {
            return ['claimed' => 0, 'passed' => 0, 'rejected' => 0, 'failed' => 0, 'pass_rate' => 0.0];
        }

        $passed = 0;
        $failed = 0;
        $reasons = [];
        $strengths = ['strong' => 0, 'medium' => 0, 'weak' => 0, 'none' => 0];

        foreach ($batch as $tweet) {
            try {
                $decision = $this->filter->decide($tweet);
                $this->repository->saveDecision($tweet->id, $decision);

                $strengths[$decision->score->strength->value]++;

                if ($decision->passes) {
                    $passed++;

                    continue;
                }

                $reasons[$decision->rejectReason] = ($reasons[$decision->rejectReason] ?? 0) + 1;
            } catch (Throwable $e) {
                $failed++;

                $this->logger->warning('filtering.item_failed', [
                    'tweet_id' => $tweet->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $decided = count($batch) - $failed;
        $rejected = $decided - $passed;

        $this->logger->info('filtering.complete', [
            'claimed' => count($batch),
            'passed' => $passed,
            'rejected' => $rejected,
            'failed' => $failed,
            // The direct multiplier on AI spend.
            'pass_rate' => $decided > 0 ? round($passed / $decided, 3) : 0.0,
            // Reasons become new free rules; strengths show how close the
            // threshold is to the mass of the distribution.
            'reject_reasons' => $reasons,
            'strengths' => $strengths,
        ]);

        return [
            'claimed' => count($batch),
            'passed' => $passed,
            'rejected' => $rejected,
            'failed' => $failed,
            'pass_rate' => $decided > 0 ? round($passed / $decided, 3) : 0.0,
        ];
    }
}
