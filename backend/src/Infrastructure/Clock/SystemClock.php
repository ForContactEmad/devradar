<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;
use DevRadar\Domain\Port\ClockInterface;

/**
 * The real clock.
 *
 * THIS DID NOT EXIST. `ClockInterface` was declared, injected by
 * `WindowResolver`, and resolved from the container by
 * `IngestionServiceProvider` — but the only implementation anywhere in the
 * project was `tests/Fake/FixedClock`. Every unit test passed because each one
 * injects the fake directly; production would have thrown
 * `BindingResolutionException: Target [ClockInterface] is not instantiable`
 * on the first collection run, which is the first stage of the pipeline.
 *
 * ALWAYS UTC. The collection window is computed against X's timestamps, which
 * are UTC, and the provider's 24-hour deduplication boundary is UTC. A clock
 * returning local time would put the window edge in a different place than the
 * provider does, and the error would be small, seasonal and very hard to see.
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
