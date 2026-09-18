<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Extraction\ExtractedProject;
use DevRadar\Domain\Port\ExtractionCandidate;
use DevRadar\Domain\Port\ProjectRepositoryInterface;
use RuntimeException;

final class InMemoryProjectRepository implements ProjectRepositoryInterface
{
    /** @var list<ExtractionCandidate> */
    public array $pending = [];

    /** @var list<ExtractedProject> */
    public array $published = [];

    /** @var array<int, string> tweet id => reason */
    public array $failures = [];

    public bool $failOnPublish = false;

    private int $nextId = 1;

    public function claimForExtraction(int $limit): array
    {
        return array_slice($this->pending, 0, $limit);
    }

    public function publish(ExtractedProject $project): int
    {
        if ($this->failOnPublish) {
            throw new RuntimeException('simulated database failure');
        }

        $this->published[] = $project;

        return $this->nextId++;
    }

    public function recordExtractionFailure(int $tweetId, string $reason): void
    {
        $this->failures[$tweetId] = $reason;
    }
}
