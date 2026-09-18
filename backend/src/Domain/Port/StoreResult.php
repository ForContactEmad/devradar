<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

/**
 * What a store operation actually changed.
 *
 * duplicates is the number the operator cares about: posts paid for and
 * already held. A query with a high duplicate rate is an expensive query,
 * however good its hit rate looks.
 */
final readonly class StoreResult
{
    public function __construct(
        public int $postsStored,
        public int $duplicates,
        public int $authorsStored,
        public ?string $newestStoredId = null,
    ) {}
}
