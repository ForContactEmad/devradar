<?php

declare(strict_types=1);

namespace DevRadar\Domain\Ingestion;

/**
 * A post author as returned by a provider, already mapped out of the
 * provider's wire format.
 *
 * Nothing downstream of the provider knows what shape X returns. That is the
 * entire point of this type.
 */
final readonly class RawAuthor
{
    /**
     * @param array<string, mixed> $rawPayload verbatim provider object
     */
    public function __construct(
        public string $id,
        public string $username,
        public ?string $displayName,
        public bool $verified,
        public ?int $followersCount,
        public array $rawPayload,
    ) {}
}
