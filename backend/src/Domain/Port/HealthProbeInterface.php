<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

/**
 * A dependency that can be asked whether it is reachable.
 *
 * Readiness is not the same question as liveness. A container that is running
 * but cannot reach Postgres should be restarted or pulled from the load
 * balancer; one that is merely still booting should not. Keeping the probes
 * behind a port means the health endpoint does not import a database facade
 * and can be exercised without one.
 */
interface HealthProbeInterface
{
    public function name(): string;

    /** True when the dependency answered. Must never throw. */
    public function isReachable(): bool;
}
