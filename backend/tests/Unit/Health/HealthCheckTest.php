<?php

declare(strict_types=1);

use DevRadar\Application\Health\HealthCheck;
use DevRadar\Domain\Port\HealthProbeInterface;

/**
 * Liveness and readiness must stay separate.
 *
 * A readiness failure wired to a liveness probe turns a recoverable
 * dependency blip into a rolling restart that guarantees the outage
 * continues. That is worth a test, because the two endpoints look almost
 * identical and are easy to collapse into one during a refactor.
 */

function probe(string $name, bool $up, bool $throws = false): HealthProbeInterface
{
    return new class($name, $up, $throws) implements HealthProbeInterface {
        public function __construct(
            private string $probeName,
            private bool $up,
            private bool $throws,
        ) {}

        public function name(): string
        {
            return $this->probeName;
        }

        public function isReachable(): bool
        {
            if ($this->throws) {
                throw new RuntimeException('probe exploded');
            }

            return $this->up;
        }
    };
}

it('reports liveness without touching a dependency', function () {
    // Every probe is down. Liveness must still say ok, or a database outage
    // marks every container dead and the restart storm prolongs it.
    $health = new HealthCheck([probe('database', false), probe('redis', false)]);

    expect($health->liveness())->toBe(['status' => 'ok']);
});

it('reports ready when every dependency answers', function () {
    $health = new HealthCheck([probe('database', true), probe('redis', true)]);
    $result = $health->readiness();

    expect($result['status'])->toBe('ready')
        ->and($result['checks'])->toBe(['database' => 'up', 'redis' => 'up'])
        ->and($health->isReady())->toBeTrue();
});

it('reports not ready when any dependency is down', function () {
    $health = new HealthCheck([probe('database', true), probe('redis', false)]);
    $result = $health->readiness();

    // One failing dependency is enough: an instance that cannot enqueue
    // should not take traffic while a healthy one exists.
    expect($result['status'])->toBe('not_ready')
        ->and($result['checks']['redis'])->toBe('down')
        ->and($health->isReady())->toBeFalse();
});

it('names which dependency is down and nothing more', function () {
    $health = new HealthCheck([probe('database', false)]);
    $encoded = json_encode($health->readiness());

    // The endpoint is unauthenticated by necessity, so it is also a
    // reconnaissance surface. A host, a version or an error string here is
    // free information for anyone scanning.
    expect($encoded)->toContain('database')
        ->and($encoded)->not->toContain('host')
        ->and($encoded)->not->toContain('version')
        ->and($encoded)->not->toContain('password');
});

it('is ready with no probes configured', function () {
    // Nothing to check means nothing is broken. Defaulting to not-ready would
    // make a bare install permanently unable to receive traffic.
    expect((new HealthCheck())->readiness()['status'])->toBe('ready');
});
