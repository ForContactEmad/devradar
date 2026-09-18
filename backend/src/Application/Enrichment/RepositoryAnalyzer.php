<?php

declare(strict_types=1);

namespace DevRadar\Application\Enrichment;

use DevRadar\Domain\Enrichment\EnrichmentTarget;
use DevRadar\Domain\Enrichment\LookupOutcome;
use DevRadar\Domain\Enrichment\RepositoryLookup;
use DevRadar\Domain\Port\EnrichmentRepositoryInterface;
use DevRadar\Domain\Port\RepositoryProviderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Analyses one repository: look it up, decide what the answer means, store it.
 *
 * Sits between the provider and the persistence port so that neither knows
 * about the other. The provider does not decide what a 404 means for a
 * project; the repository does not decide when to fetch. This class owns
 * exactly that translation and nothing else.
 *
 * ENRICHMENT NEVER BLOCKS THE PROJECT. Every outcome is recorded and the
 * pipeline moves on: a project with an unreachable repository is still a
 * project, and the scoring engine already treats missing repository data as
 * an absent signal rather than a bad one.
 */
final readonly class RepositoryAnalyzer
{
    public function __construct(
        private RepositoryProviderInterface $provider,
        private EnrichmentRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    public function analyze(EnrichmentTarget $target): RepositoryLookup
    {
        // The stored ETag makes this conditional. Most refreshes should come
        // back 304 and, with a token, cost nothing.
        $result = $this->provider->lookup($target->ref, $target->etag);

        try {
            match ($result->outcome) {
                LookupOutcome::Found => $this->repository->storeFacts($target->repositoryId, $result->facts),
                LookupOutcome::Unchanged => $this->repository->touchUnchanged($target->repositoryId),
                LookupOutcome::NotFound, LookupOutcome::Private_ => $this->repository->markGone(
                    $target->repositoryId,
                    $result->message ?? $result->outcome->value,
                ),
                // A rate limit says nothing about this repository, so it must
                // not count towards its failure budget or a single throttled
                // hour would permanently blacklist everything in the batch.
                LookupOutcome::RateLimited => null,
                LookupOutcome::Failed => $this->repository->recordFailure(
                    $target->repositoryId,
                    $result->message ?? 'unknown failure',
                ),
            };
        } catch (Throwable $e) {
            $this->logger->error('enrichment.persistence_failed', [
                'repository' => $target->ref->fullName(),
                'outcome' => $result->outcome->value,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('enrichment.analyzed', [
            'repository' => $target->ref->fullName(),
            'outcome' => $result->outcome->value,
            'conditional' => $target->etag !== null,
            'counted_against_quota' => $result->countedAgainstQuota,
            'remaining_quota' => $result->remainingQuota,
        ]);

        return $result;
    }
}
