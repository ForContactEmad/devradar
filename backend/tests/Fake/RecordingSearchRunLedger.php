<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Collection\SearchQueryDefinition;
use DevRadar\Domain\Port\SearchRunLedgerInterface;

/**
 * Records ledger calls so tests can prove that every run is opened and closed
 * exactly once, in the right order, with the right status.
 */
final class RecordingSearchRunLedger implements SearchRunLedgerInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    private int $nextId = 1;

    public function begin(SearchQueryDefinition $definition, ?string $sinceId): int
    {
        $id = $this->nextId++;
        $this->calls[] = ['op' => 'begin', 'run_id' => $id, 'query' => $definition->name, 'since_id' => $sinceId];

        return $id;
    }

    public function complete(
        int $runId,
        int $postsReturned,
        int $postsNew,
        int $billableResources,
        float $costUsd,
        ?string $maxIdSeen,
        string $status = 'completed',
    ): void {
        $this->calls[] = [
            'op' => 'complete', 'run_id' => $runId, 'status' => $status,
            'posts_returned' => $postsReturned, 'posts_new' => $postsNew,
            'billable' => $billableResources, 'cost' => $costUsd, 'max_id' => $maxIdSeen,
        ];
    }

    public function fail(int $runId, string $errorClass, string $errorMessage, int $billableResources = 0, float $costUsd = 0.0): void
    {
        $this->calls[] = [
            'op' => 'fail', 'run_id' => $runId, 'error_class' => $errorClass,
            'message' => $errorMessage, 'billable' => $billableResources,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function ops(string $op): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['op'] === $op));
    }
}
