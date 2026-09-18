<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Extraction\ExtractedProject;

/**
 * Persistence boundary for published projects.
 *
 * Separate from extraction and validation, so that changing where projects
 * are stored never touches the logic that decides what a project is.
 */
interface ProjectRepositoryInterface
{
    /**
     * Posts classified as launches and awaiting extraction, oldest first.
     *
     * @return list<ExtractionCandidate>
     */
    public function claimForExtraction(int $limit): array;

    /**
     * Publish a project and attach its technologies.
     *
     * Must be idempotent on the source post: re-running extraction after a
     * prompt change updates the project rather than creating a second one.
     */
    public function publish(ExtractedProject $project): int;

    /** Record that a post could not be extracted, with the reason. */
    public function recordExtractionFailure(int $tweetId, string $reason): void;
}
