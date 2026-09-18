<?php

declare(strict_types=1);

namespace DevRadar\Application\Processing;

use DevRadar\Domain\Port\ProcessingRepositoryInterface;
use DevRadar\Domain\Processing\TweetNormalizer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Normalizes a batch of newly collected posts.
 *
 * Orchestration only: claim, normalize, save. The rules live in the
 * normalizer, which is pure and therefore testable without any of this.
 *
 * ONE BAD POST NEVER FAILS A BATCH. The batch has already been paid for, so
 * quarantining a single unmappable row and continuing is strictly better than
 * discarding the rest. The failure is counted and logged so a systematic
 * problem is still visible.
 */
final readonly class NormalizationRunner
{
    public function __construct(
        private ProcessingRepositoryInterface $repository,
        private TweetNormalizer $normalizer,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, int> */
    public function run(int $limit = 200): array
    {
        $batch = $this->repository->claimForNormalization($limit);

        if ($batch === []) {
            return ['claimed' => 0, 'normalized' => 0, 'rejected' => 0, 'failed' => 0];
        }

        $normalized = 0;
        $rejected = 0;
        $failed = 0;
        $reasons = [];

        foreach ($batch as $tweet) {
            try {
                $result = $this->normalizer->normalize($tweet);
                $this->repository->saveNormalization($tweet->id, $result);

                if ($result->isRejected()) {
                    $rejected++;
                    $reasons[$result->rejectReason] = ($reasons[$result->rejectReason] ?? 0) + 1;

                    continue;
                }

                $normalized++;
            } catch (Throwable $e) {
                $failed++;

                $this->logger->warning('processing.normalize.item_failed', [
                    'tweet_id' => $tweet->id,
                    'x_tweet_id' => $tweet->xTweetId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('processing.normalize.complete', [
            'claimed' => count($batch),
            'normalized' => $normalized,
            'rejected' => $rejected,
            'failed' => $failed,
            // The reason histogram is what free pre-filter rules get written
            // from, so it is logged rather than merely counted.
            'reject_reasons' => $reasons,
        ]);

        return [
            'claimed' => count($batch),
            'normalized' => $normalized,
            'rejected' => $rejected,
            'failed' => $failed,
        ];
    }
}
