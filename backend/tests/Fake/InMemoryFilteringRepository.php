<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Filtering\FilterDecision;
use DevRadar\Domain\Port\FilteringRepositoryInterface;
use RuntimeException;

final class InMemoryFilteringRepository implements FilteringRepositoryInterface
{
    /** @var list<mixed> */
    public array $due = [];

    /** @var array<int, FilterDecision> */
    public array $decisions = [];

    /** Tweet ids whose save should blow up. */
    public array $failOn = [];

    public function claimForFiltering(int $limit): array
    {
        return array_slice($this->due, 0, $limit);
    }

    public function saveDecision(int $tweetId, FilterDecision $decision): void
    {
        if (in_array($tweetId, $this->failOn, true)) {
            throw new RuntimeException("database unavailable for tweet {$tweetId}");
        }

        $this->decisions[$tweetId] = $decision;
    }
}
