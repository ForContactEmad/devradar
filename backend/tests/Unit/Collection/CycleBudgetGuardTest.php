<?php

declare(strict_types=1);

use DevRadar\Domain\Budget\CycleBudgetGuard;

it('allows spending inside both ceilings', function () {
    $guard = new CycleBudgetGuard(alreadySpentUsd: 0.0, cycleCeilingUsd: 40.0);

    expect($guard->allows(100))->toBeTrue();
});

it('refuses once the monetary ceiling would be crossed', function () {
    $guard = new CycleBudgetGuard(alreadySpentUsd: 39.95, cycleCeilingUsd: 40.0, resourcePriceUsd: 0.005);

    // 39.95 + (20 * 0.005) = 40.05
    expect($guard->allows(20))->toBeFalse()
        ->and($guard->allows(10))->toBeTrue();
});

it('refuses once the per-run resource cap would be crossed', function () {
    $guard = new CycleBudgetGuard(0.0, 1000.0, maxResourcesPerRun: 50);

    // Bounds the damage a runaway pagination loop can do before anyone looks.
    expect($guard->allows(60))->toBeFalse()
        ->and($guard->allows(50))->toBeTrue();
});

it('applies the tighter of the two ceilings', function () {
    $guard = new CycleBudgetGuard(0.0, cycleCeilingUsd: 1000.0, maxResourcesPerRun: 10);

    expect($guard->allows(11))->toBeFalse();
});

it('tracks consumption across a run', function () {
    $guard = new CycleBudgetGuard(0.0, 1000.0, maxResourcesPerRun: 100);

    $guard->record(40);
    $guard->record(40);

    expect($guard->consumedThisRun())->toBe(80)
        ->and($guard->allows(30))->toBeFalse()
        ->and($guard->allows(20))->toBeTrue();
});

it('reports projected spend and remaining allowance', function () {
    $guard = new CycleBudgetGuard(10.0, 40.0, resourcePriceUsd: 0.005);
    $guard->record(1000);

    expect($guard->projectedSpendUsd())->toBe(15.0)
        ->and($guard->remainingCycleUsd())->toBe(25.0);
});

it('refuses everything when the cycle is already over budget', function () {
    $guard = new CycleBudgetGuard(alreadySpentUsd: 45.0, cycleCeilingUsd: 40.0);

    expect($guard->allows(1))->toBeFalse()
        ->and($guard->remainingCycleUsd())->toBe(0.0);
});
