<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Compliance\ComplianceFinding;
use DevRadar\Domain\Compliance\ComplianceJob;
use DevRadar\Domain\Compliance\ComplianceJobState;
use DevRadar\Domain\Port\ComplianceRepositoryInterface;

final class InMemoryComplianceRepository implements ComplianceRepositoryInterface
{
    public ?ComplianceJob $open = null;

    /** @var list<string> */
    public array $due = [];

    /** @var list<string> */
    public array $checked = [];

    /** @var list<ComplianceFinding> */
    public array $applied = [];

    /** @var list<array{state: string, error: ?string}> */
    public array $updates = [];

    /** Post ids the repository claims to hold. */
    public array $known = [];

    private int $nextId = 1;

    public function openJob(): ?ComplianceJob
    {
        return $this->open;
    }

    public function recordJob(ComplianceJob $job): int
    {
        $id = $this->nextId++;
        $this->open = new ComplianceJob(
            id: $id,
            state: $job->state,
            providerJobId: $job->providerJobId,
            uploadUrl: $job->uploadUrl,
            downloadUrl: $job->downloadUrl,
            uploadExpiresAt: $job->uploadExpiresAt,
            downloadExpiresAt: $job->downloadExpiresAt,
        );

        return $id;
    }

    public function updateJob(int $id, ComplianceJobState $state, ?ComplianceJob $details = null, ?string $error = null): void
    {
        $this->updates[] = ['state' => $state->value, 'error' => $error];

        if ($state->isFinished()) {
            $this->open = null;
        }
    }

    public function idsDueForCheck(int $limit, int $recheckAfterHours): array
    {
        return array_slice($this->due, 0, $limit);
    }

    public function markChecked(array $postIds): void
    {
        $this->checked = array_merge($this->checked, $postIds);
    }

    public function applyFinding(ComplianceFinding $finding): bool
    {
        if ($this->known !== [] && ! in_array($finding->postId, $this->known, true)) {
            return false;
        }

        $this->applied[] = $finding;

        return true;
    }

    public function lastState(): ?string
    {
        return $this->updates === [] ? null : $this->updates[array_key_last($this->updates)]['state'];
    }
}
