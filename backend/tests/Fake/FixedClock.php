<?php

declare(strict_types=1);

namespace Tests\Fake;

use DateTimeImmutable;
use DateTimeZone;
use DevRadar\Domain\Port\ClockInterface;

/** A clock that does not move, so window logic is deterministic. */
final readonly class FixedClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $instant = '2026-09-09 12:00:00')
    {
        $this->now = new DateTimeImmutable($instant, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
