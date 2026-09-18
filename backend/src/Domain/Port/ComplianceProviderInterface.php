<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Compliance\ComplianceFinding;
use DevRadar\Domain\Compliance\ComplianceJob;

/**
 * Boundary for the provider's compliance reconciliation.
 *
 * ASYNCHRONOUS, and the interface says so rather than hiding it behind a
 * blocking call. A job is created, IDs are uploaded, the provider processes
 * them on its own schedule, and results are downloaded later. A synchronous
 * facade would mean a worker holding a connection open for the whole wait,
 * and a crash losing a job the provider had already accepted.
 *
 * This is the one integration whose failure is a legal exposure rather than a
 * missing feature: content we are required to stop displaying stays up.
 */
interface ComplianceProviderInterface
{
    public function name(): string;

    /** Create a job and receive its pre-signed upload and download URLs. */
    public function createJob(string $name): ComplianceJob;

    /**
     * Upload post IDs, one per line. False when the provider refused.
     *
     * @param list<string> $postIds
     */
    public function uploadIds(ComplianceJob $job, array $postIds): bool;

    public function jobStatus(string $providerJobId): ComplianceJob;

    /**
     * Download the results.
     *
     * ONLY IDS WITH A COMPLIANCE EVENT ARE RETURNED. An uploaded ID absent
     * from the results is still live -- absence is the good answer, and a
     * caller that read it as unknown would purge the entire feed.
     *
     * @return list<ComplianceFinding>
     */
    public function downloadResults(ComplianceJob $job): array;
}
