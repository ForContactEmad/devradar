<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

/**
 * The first of two independent brakes on spend. The second is the spending
 * limit configured in the provider console; neither substitutes for the other.
 *
 * The guard is consulted BEFORE each request, not after, because the charge is
 * incurred by the response. Asking afterwards is asking too late.
 */
interface BudgetGuardInterface
{
    /**
     * May the caller spend on up to $estimatedResources more billable
     * resources right now?
     *
     * Returning false must cause the caller to stop cleanly, not to retry.
     */
    public function allows(int $estimatedResources): bool;

    /**
     * Record resources actually consumed, so the next check reflects reality
     * rather than the estimate.
     */
    public function record(int $billableResources): void;
}
