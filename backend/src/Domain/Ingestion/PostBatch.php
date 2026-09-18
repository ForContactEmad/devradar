<?php

declare(strict_types=1);

namespace DevRadar\Domain\Ingestion;

/**
 * The result of one paginated fetch.
 *
 * billableResources is the honest count of what was charged: posts returned
 * plus author objects returned, since a provider bills per resource in the
 * response, not per request. It is what the run ledger records, and it is
 * deliberately separate from count($posts) -- which excludes authors and would
 * therefore understate spend.
 */
final readonly class PostBatch
{
    /**
     * @param list<RawPost>                $posts
     * @param array<string, RawAuthor>     $authors  keyed by author id
     * @param list<array<string, mixed>>   $partialErrors provider-reported per-object failures
     */
    public function __construct(
        public array $posts,
        public array $authors,
        public ?string $newestId,
        public ?string $oldestId,
        public ?string $nextToken,
        public int $requestCount,
        public int $billableResources,
        public StopReason $stopReason,
        public array $partialErrors = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->posts === [];
    }

    public function authorFor(RawPost $post): ?RawAuthor
    {
        return $this->authors[$post->authorId] ?? null;
    }
}
