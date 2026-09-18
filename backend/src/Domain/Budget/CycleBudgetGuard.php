<?php

declare(strict_types=1);

namespace DevRadar\Domain\Budget;

use DevRadar\Domain\Port\BudgetGuardInterface;

/**
 * The in-code half of the two-brake spend design. The other half is the
 * spending limit configured in the provider console; neither substitutes for
 * the other.
 *
 * Pure: spend already incurred this cycle is passed in, so this class does no
 * I/O and is fully testable. Two independent ceilings apply, and the tighter
 * one wins:
 *
 *   - a monetary ceiling for the billing cycle
 *   - a resource cap for this run, which bounds the damage a runaway
 *     pagination loop can do before anyone notices
 *
 * The estimate is checked BEFORE a request because the charge is incurred by
 * the response. Checking afterwards is checking too late.
 */
final class CycleBudgetGuard implements BudgetGuardInterface
{
    private int $consumedThisRun = 0;

    public function __construct(
        private readonly float $alreadySpentUsd,
        private readonly float $cycleCeilingUsd,
        private readonly float $resourcePriceUsd = 0.005,
        private readonly int $maxResourcesPerRun = 600,
    ) {}

    public function allows(int $estimatedResources): bool
    {
        if ($this->consumedThisRun + $estimatedResources > $this->maxResourcesPerRun) {
            return false;
        }

        $projected = $this->alreadySpentUsd
            + (($this->consumedThisRun + $estimatedResources) * $this->resourcePriceUsd);

        return $projected <= $this->cycleCeilingUsd;
    }

    public function record(int $billableResources): void
    {
        $this->consumedThisRun += $billableResources;
    }

    public function consumedThisRun(): int
    {
        return $this->consumedThisRun;
    }

    public function projectedSpendUsd(): float
    {
        return $this->alreadySpentUsd + ($this->consumedThisRun * $this->resourcePriceUsd);
    }

    public function remainingCycleUsd(): float
    {
        return max(0.0, $this->cycleCeilingUsd - $this->projectedSpendUsd());
    }
}
