<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Health;

use DevRadar\Domain\Port\HealthProbeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is Postgres answering?
 *
 * `SELECT 1` rather than a real query: the question is whether the connection
 * works, and a probe that reads a table would fail for reasons that have
 * nothing to do with connectivity -- a migration in flight, a lock, an empty
 * database on first boot.
 */
final readonly class DatabaseProbe implements HealthProbeInterface
{
    public function name(): string
    {
        return 'database';
    }

    public function isReachable(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable) {
            // Swallowed by contract. A probe that throws turns a health
            // endpoint into a 500, which tells an orchestrator nothing.
            return false;
        }
    }
}
