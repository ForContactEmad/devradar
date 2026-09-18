<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Health;

use DevRadar\Domain\Port\HealthProbeInterface;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Is Redis answering?
 *
 * Redis carries the queue, so an API instance that cannot reach it can still
 * serve the feed -- the read path touches only Postgres. It is reported as a
 * readiness failure anyway, because an instance that cannot enqueue is not
 * fully functional and should not receive traffic while a healthy one exists.
 */
final readonly class RedisProbe implements HealthProbeInterface
{
    public function name(): string
    {
        return 'redis';
    }

    public function isReachable(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
