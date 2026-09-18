<?php

declare(strict_types=1);

namespace DevRadar\Domain\Compliance;

/**
 * What a compliance cycle did.
 *
 * `checked` and `removed` are reported separately because the interesting
 * number is usually zero removals: that is the system working. A cycle that
 * checked nothing, however, is a cycle that did not run, and the two must not
 * look alike in a log.
 */
final readonly class PurgeOutcome
{
    /** @param array<string, int> $byEvent */
    public function __construct(
        public string $phase,
        public int $checked = 0,
        public int $removed = 0,
        public int $scrubbed = 0,
        public int $hidden = 0,
        public array $byEvent = [],
        public ?string $note = null,
    ) {}

    /** @param array<string, int> $byEvent */
    public static function applied(int $checked, int $removed, int $scrubbed, int $hidden, array $byEvent): self
    {
        return new self('applied', $checked, $removed, $scrubbed, $hidden, $byEvent);
    }

    public static function waiting(string $note): self
    {
        return new self('waiting', note: $note);
    }

    public static function submitted(int $idCount): self
    {
        return new self('submitted', checked: $idCount);
    }

    public static function idle(string $note): self
    {
        return new self('idle', note: $note);
    }

    public static function failed(string $note): self
    {
        return new self('failed', note: $note);
    }
}
