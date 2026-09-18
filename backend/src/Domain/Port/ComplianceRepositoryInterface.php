<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Compliance\ComplianceFinding;
use DevRadar\Domain\Compliance\ComplianceJob;
use DevRadar\Domain\Compliance\ComplianceJobState;

/**
 * Persistence for compliance reconciliation.
 *
 * The obligation is to keep stored content in step with the source platform:
 * a post deleted there must stop being displayed here, within a day.
 */
interface ComplianceRepositoryInterface
{
    /**
     * The job currently in flight, if any.
     *
     * ONE AT A TIME, deliberately. Overlapping jobs would upload overlapping
     * ID sets and make it impossible to say which job verified which post.
     */
    public function openJob(): ?ComplianceJob;

    public function recordJob(ComplianceJob $job): int;

    public function updateJob(
        int $id,
        ComplianceJobState $state,
        ?ComplianceJob $details = null,
        ?string $error = null,
    ): void;

    /**
     * Post IDs not checked recently, least-recently-checked first.
     *
     * Newest-first would re-check the same recent batch forever and never
     * reach the posts that have gone longest without verification.
     *
     * @return list<string>
     */
    public function idsDueForCheck(int $limit, int $recheckAfterHours): array;

    /**
     * Mark IDs as verified still live.
     *
     * @param list<string> $postIds
     */
    public function markChecked(array $postIds): void;

    /**
     * Apply one finding. False when the post is not stored locally.
     *
     * Not an error: the local copy may have aged out between upload and
     * collection, and the provider answers about every ID we sent.
     */
    public function applyFinding(ComplianceFinding $finding): bool;
}
