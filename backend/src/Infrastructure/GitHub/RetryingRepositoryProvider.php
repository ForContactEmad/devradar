<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\GitHub;

use DevRadar\Domain\Enrichment\LookupOutcome;
use DevRadar\Domain\Enrichment\RepositoryLookup;
use DevRadar\Domain\Enrichment\RepositoryRef;
use DevRadar\Domain\Port\RepositoryProviderInterface;
use DevRadar\Domain\Support\BackoffPolicy;
use Psr\Log\LoggerInterface;

/**
 * Retries transient repository lookups. A decorator, not a client feature.
 *
 * ONLY `Failed` IS RETRIED. Not-found and private are permanent -- retrying
 * spends quota on something that will never resolve. Rate limits are not
 * retried in-process either: the window can be an hour away, and blocking a
 * worker that long to re-ask one repository starves every other project in
 * the queue. The runner stops the batch instead and the next scheduled run
 * picks up where it left off.
 */
final readonly class RetryingRepositoryProvider implements RepositoryProviderInterface
{
    public function __construct(
        private RepositoryProviderInterface $inner,
        private BackoffPolicy $backoff,
        private LoggerInterface $logger,
        private int $maxAttempts = 3,
        /** Injected so tests never actually sleep. */
        private ?\Closure $sleeper = null,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function remainingQuota(): ?int
    {
        return $this->inner->remainingQuota();
    }

    public function lookup(RepositoryRef $ref, ?string $knownEtag = null): RepositoryLookup
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $result = $this->inner->lookup($ref, $knownEtag);

            if ($result->outcome !== LookupOutcome::Failed || $attempt >= $this->maxAttempts) {
                return $result;
            }

            $delay = $this->backoff->delayFor($attempt);

            $this->logger->warning('github.retrying', [
                'repository' => $ref->fullName(),
                'attempt' => $attempt,
                'reason' => $result->message,
                'delay_seconds' => $delay,
            ]);

            $this->sleep($delay);
        }
    }

    private function sleep(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }
}
