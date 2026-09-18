<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Port\BudgetGuardInterface;

/**
 * Budget guard double with an explicit allowance, so tests can prove the
 * client stops spending rather than merely assuming it does.
 */
final class FakeBudgetGuard implements BudgetGuardInterface
{
    public int $recorded = 0;

    public int $allowChecks = 0;

    public function __construct(private int $allowance = PHP_INT_MAX) {}

    public static function exhausted(): self
    {
        return new self(0);
    }

    public function allows(int $estimatedResources): bool
    {
        $this->allowChecks++;

        return $this->recorded + $estimatedResources <= $this->allowance;
    }

    public function record(int $billableResources): void
    {
        $this->recorded += $billableResources;
    }
}
