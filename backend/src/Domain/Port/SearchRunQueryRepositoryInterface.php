<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Query\Paginated;

/**
 * Operational read side: search runs and pipeline health.
 *
 * BEHIND THE ADMIN BOUNDARY. These rows carry spend per run, cost per query
 * and yield per query family -- the numbers that say what the product costs
 * to operate. Public statistics live on a different endpoint and share none
 * of them.
 */
interface SearchRunQueryRepositoryInterface
{
    /** @return Paginated<array<string, mixed>> */
    public function runs(int $page, int $perPage, ?string $status = null): Paginated;

    /** @return array<string, mixed> */
    public function pipelineHealth(int $windowHours): array;
}
