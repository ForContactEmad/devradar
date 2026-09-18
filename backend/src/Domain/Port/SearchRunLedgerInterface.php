<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Collection\SearchQueryDefinition;

/**
 * The spend ledger.
 *
 * A run row is opened BEFORE the first request and closed after the last, so
 * a process that dies mid-run leaves a row stuck in 'running' rather than no
 * evidence at all. An orphaned running row is a visible fault; a missing row
 * is an invisible one, and invisible faults around spending are the dangerous
 * kind.
 */
interface SearchRunLedgerInterface
{
    /** Open a run and return its id. */
    public function begin(SearchQueryDefinition $definition, ?string $sinceId): int;

    public function complete(
        int $runId,
        int $postsReturned,
        int $postsNew,
        int $billableResources,
        float $costUsd,
        ?string $maxIdSeen,
        string $status = 'completed',
    ): void;

    /**
     * Close a run that failed.
     *
     * errorClass follows the taxonomy from the architecture phase:
     * transient, permanent, auth, rate_limit, budget, data.
     */
    public function fail(int $runId, string $errorClass, string $errorMessage, int $billableResources = 0, float $costUsd = 0.0): void;
}
