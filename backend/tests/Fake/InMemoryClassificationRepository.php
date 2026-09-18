<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Classification\ClassificationRequest;
use DevRadar\Domain\Classification\ClassificationResult;
use DevRadar\Domain\Port\ClassificationRepositoryInterface;
use RuntimeException;

final class InMemoryClassificationRepository implements ClassificationRepositoryInterface
{
    /** @var list<ClassificationRequest> */
    public array $pending = [];

    /** @var list<ClassificationResult> */
    public array $saved = [];

    public bool $failOnSave = false;

    public float $spend = 0.0;

    public function claimForClassification(int $limit): array
    {
        return array_slice($this->pending, 0, $limit);
    }

    public function saveAnalysis(ClassificationResult $result): void
    {
        if ($this->failOnSave) {
            throw new RuntimeException('simulated database failure');
        }

        $this->saved[] = $result;
        $this->spend += $result->costUsd;
    }

    public int $released = 0;

    public function releaseStaleClaims(int $olderThanMinutes): int
    {
        return $this->released;
    }

    public function cycleSpendUsd(): float
    {
        return $this->spend;
    }
}
