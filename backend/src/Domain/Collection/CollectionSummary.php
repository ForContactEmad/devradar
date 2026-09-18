<?php

declare(strict_types=1);

namespace DevRadar\Domain\Collection;

/**
 * The outcome of a full collection cycle across every active query.
 *
 * A cycle is reported as a whole because the operator's question is never
 * "did query 7 work" but "did tonight's run cost what I expected and find
 * anything new".
 */
final readonly class CollectionSummary
{
    /** @param list<CollectionReport> $reports */
    public function __construct(public array $reports) {}

    public function totalPostsStored(): int
    {
        return array_sum(array_map(fn (CollectionReport $r) => $r->postsStored, $this->reports));
    }

    public function totalBillableResources(): int
    {
        return array_sum(array_map(fn (CollectionReport $r) => $r->billableResources, $this->reports));
    }

    public function totalRequests(): int
    {
        return array_sum(array_map(fn (CollectionReport $r) => $r->requestCount, $this->reports));
    }

    /** @return list<CollectionReport> */
    public function failures(): array
    {
        return array_values(array_filter($this->reports, fn (CollectionReport $r) => $r->isFailure()));
    }

    public function allFailed(): bool
    {
        return $this->reports !== [] && count($this->failures()) === count($this->reports);
    }
}
