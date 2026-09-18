<?php

declare(strict_types=1);

namespace DevRadar\Application\Processing;

use DevRadar\Domain\Port\ProcessingRepositoryInterface;
use DevRadar\Domain\Processing\DuplicateDecision;
use DevRadar\Domain\Processing\TweetDeduplicator;
use Psr\Log\LoggerInterface;

/**
 * Collapses duplicates across a batch and against what is already stored.
 *
 * The index is seeded from the database for the batch's keys, then extended
 * as the batch is processed. That two-part construction is what catches both
 * kinds of duplicate: a post matching something stored last week, and two
 * posts within this batch matching each other -- neither of which is visible
 * to a database-only lookup, because neither in-batch row is a survivor yet.
 */
final readonly class DeduplicationRunner
{
    public function __construct(
        private ProcessingRepositoryInterface $repository,
        private TweetDeduplicator $deduplicator,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, int> */
    public function run(int $limit = 200): array
    {
        $batch = $this->repository->claimForDeduplication($limit);

        if ($batch === []) {
            return ['claimed' => 0, 'unique' => 0, 'duplicates' => 0];
        }

        $index = $this->repository->loadSeenIndexFor($batch);
        $decisions = $this->deduplicator->decideBatch($batch, $index);

        $unique = 0;
        $byLevel = [
            DuplicateDecision::LEVEL_TWEET_ID => 0,
            DuplicateDecision::LEVEL_URL => 0,
            DuplicateDecision::LEVEL_TEXT => 0,
        ];

        foreach ($batch as $tweet) {
            $decision = $decisions[$tweet->id];

            if (! $decision->isDuplicate) {
                $this->repository->markDeduplicated($tweet->id);
                $unique++;

                continue;
            }

            $this->repository->markDuplicate($tweet->id, $decision->survivorId, $decision->matchLevel);
            $byLevel[$decision->matchLevel]++;
        }

        $duplicates = array_sum($byLevel);

        $this->logger->info('processing.deduplicate.complete', [
            'claimed' => count($batch),
            'unique' => $unique,
            'duplicates' => $duplicates,
            // Split by level because the two findings are different: same-id
            // means queries overlap and money was wasted; same-url means the
            // product is working and one project has several sources.
            'same_post' => $byLevel[DuplicateDecision::LEVEL_TWEET_ID],
            'same_url' => $byLevel[DuplicateDecision::LEVEL_URL],
            'same_text' => $byLevel[DuplicateDecision::LEVEL_TEXT],
        ]);

        return [
            'claimed' => count($batch),
            'unique' => $unique,
            'duplicates' => $duplicates,
        ];
    }
}
