<?php

declare(strict_types=1);

namespace DevRadar\Application\Enrichment;

use DevRadar\Domain\Enrichment\LookupOutcome;
use DevRadar\Domain\Port\EnrichmentRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs enrichment over a batch of repositories.
 *
 * STOPS ON A RATE LIMIT INSTEAD OF GRINDING THROUGH IT. Once GitHub says the
 * window is exhausted, every remaining request in the batch returns 403. The
 * batch ends, the reset time is logged, and the next scheduled run resumes.
 * Continuing would turn one throttled minute into a batch of failures that
 * look like broken repositories.
 *
 * A QUOTA RESERVE IS KEPT. The runner stops while requests remain rather than
 * at zero, so an interactive lookup or a different job is not left with an
 * empty budget because this sweep drained it.
 */
final readonly class EnrichmentRunner
{
    public function __construct(
        private EnrichmentRepositoryInterface $repository,
        private RepositoryAnalyzer $analyzer,
        private LoggerInterface $logger = new NullLogger(),
        private int $refreshAfterMinutes = 720,
        private int $maxFailures = 5,
        private int $quotaReserve = 50,
    ) {}

    /** @return array<string, mixed> */
    public function run(int $limit = 50): array
    {
        $linked = $this->repository->linkPendingProjects($limit);
        $batch = $this->repository->claimForEnrichment($limit, $this->refreshAfterMinutes, $this->maxFailures);

        $counts = [
            'found' => 0, 'unchanged' => 0, 'not_found' => 0,
            'private' => 0, 'rate_limited' => 0, 'failed' => 0,
        ];
        $requestsCounted = 0;
        $stoppedEarly = false;
        $resetsIn = null;

        foreach ($batch as $target) {
            $result = $this->analyzer->analyze($target);
            $counts[$result->outcome->value]++;

            if ($result->countedAgainstQuota) {
                $requestsCounted++;
            }

            if ($result->outcome === LookupOutcome::RateLimited) {
                $resetsIn = $result->secondsUntilReset();
                $stoppedEarly = true;
                break;
            }

            if ($result->remainingQuota !== null && $result->remainingQuota <= $this->quotaReserve) {
                $this->logger->warning('enrichment.quota_reserve_reached', [
                    'remaining' => $result->remainingQuota,
                    'reserve' => $this->quotaReserve,
                ]);

                $stoppedEarly = true;
                break;
            }
        }

        $summary = [
            'linked' => $linked,
            'claimed' => count($batch),
            ...$counts,
            // The number that matters for the refresh strategy: how many
            // lookups actually cost quota versus came back unchanged.
            'requests_counted' => $requestsCounted,
            'stopped_early' => $stoppedEarly,
            'quota_resets_in_seconds' => $resetsIn,
        ];

        $this->logger->info('enrichment.complete', $summary);

        return $summary;
    }
}
