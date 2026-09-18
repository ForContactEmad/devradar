<?php

declare(strict_types=1);

namespace DevRadar\Application\Health;

use DevRadar\Domain\Port\HealthProbeInterface;

/**
 * Liveness and readiness, kept apart.
 *
 * LIVENESS answers "is this process working". It touches nothing external, so
 * a database outage cannot make every container look dead and trigger a
 * restart storm that guarantees the outage continues.
 *
 * READINESS answers "should traffic come here". It checks the dependencies a
 * request actually needs, so an instance that cannot reach Postgres is taken
 * out of rotation without being killed.
 *
 * Conflating them is the classic mistake: a readiness failure wired to a
 * liveness probe turns a recoverable dependency blip into a rolling restart.
 *
 * NOTHING LEAKS. The response names which component is down and no more --
 * not the host, not the error, not the version. A health endpoint is
 * unauthenticated by necessity, so it is also a reconnaissance surface.
 */
final readonly class HealthCheck
{
    /** @param list<HealthProbeInterface> $probes */
    public function __construct(private array $probes = []) {}

    /** @return array{status: string} */
    public function liveness(): array
    {
        // Deliberately constant. If this code runs, the process is alive.
        return ['status' => 'ok'];
    }

    /** @return array{status: string, checks: array<string, string>} */
    public function readiness(): array
    {
        $checks = [];
        $ready = true;

        foreach ($this->probes as $probe) {
            $up = $probe->isReachable();
            $checks[$probe->name()] = $up ? 'up' : 'down';

            if (! $up) {
                $ready = false;
            }
        }

        return ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks];
    }

    public function isReady(): bool
    {
        return $this->readiness()['status'] === 'ready';
    }
}
