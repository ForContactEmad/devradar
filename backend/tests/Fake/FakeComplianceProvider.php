<?php

declare(strict_types=1);

namespace Tests\Fake;

use DateTimeImmutable;
use DevRadar\Domain\Compliance\ComplianceFinding;
use DevRadar\Domain\Compliance\ComplianceJob;
use DevRadar\Domain\Compliance\ComplianceJobState;
use DevRadar\Domain\Port\ComplianceProviderInterface;
use RuntimeException;

/** Scripted compliance provider. No test may reach the real endpoint. */
final class FakeComplianceProvider implements ComplianceProviderInterface
{
    public ?ComplianceJob $createdJob = null;
    public ComplianceJobState $reportedStatus = ComplianceJobState::InProgress;

    /** @var list<ComplianceFinding> */
    public array $results = [];

    public bool $uploadSucceeds = true;
    public bool $createThrows = false;

    /** @var list<string> */
    public array $uploadedIds = [];

    public int $createCalls = 0;
    public int $statusCalls = 0;
    public int $downloadCalls = 0;

    public function name(): string
    {
        return 'fake-compliance';
    }

    public function createJob(string $name): ComplianceJob
    {
        $this->createCalls++;

        if ($this->createThrows) {
            throw new RuntimeException('provider unavailable');
        }

        $now = new DateTimeImmutable();

        return $this->createdJob ?? new ComplianceJob(
            id: null,
            state: ComplianceJobState::Created,
            providerJobId: 'job-abc',
            uploadUrl: 'https://upload.example/abc',
            downloadUrl: 'https://download.example/abc',
            uploadExpiresAt: $now->modify('+15 minutes'),
            downloadExpiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
    }

    public function uploadIds(ComplianceJob $job, array $postIds): bool
    {
        $this->uploadedIds = $postIds;

        return $this->uploadSucceeds;
    }

    public function jobStatus(string $providerJobId): ComplianceJob
    {
        $this->statusCalls++;

        return new ComplianceJob(
            id: null,
            state: $this->reportedStatus,
            providerJobId: $providerJobId,
            downloadUrl: 'https://download.example/abc',
        );
    }

    public function downloadResults(ComplianceJob $job): array
    {
        $this->downloadCalls++;

        return $this->results;
    }
}
