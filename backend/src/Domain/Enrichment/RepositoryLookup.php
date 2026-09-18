<?php

declare(strict_types=1);

namespace DevRadar\Domain\Enrichment;

/**
 * The result of one repository lookup.
 *
 * Carries the outcome, the facts when there are any, and the quota position
 * afterwards. The quota matters to the caller, not just the client: a runner
 * that knows only 12 requests remain can stop cleanly instead of walking into
 * a wall of 403s.
 */
final readonly class RepositoryLookup
{
    private function __construct(
        public LookupOutcome $outcome,
        public ?RepositoryFacts $facts = null,
        public ?string $message = null,
        public ?int $remainingQuota = null,
        public ?int $quotaResetsAt = null,
        public bool $countedAgainstQuota = true,
    ) {}

    public static function found(RepositoryFacts $facts, ?int $remaining = null, ?int $resetsAt = null): self
    {
        return new self(LookupOutcome::Found, $facts, null, $remaining, $resetsAt);
    }

    /**
     * A 304. Free when authenticated, and merely bandwidth-saving when not --
     * which is exactly backwards from where the saving is needed.
     */
    public static function unchanged(?int $remaining = null, ?int $resetsAt = null, bool $counted = false): self
    {
        return new self(LookupOutcome::Unchanged, null, null, $remaining, $resetsAt, $counted);
    }

    public static function notFound(string $message, ?int $remaining = null): self
    {
        return new self(LookupOutcome::NotFound, null, $message, $remaining);
    }

    public static function private_(string $message, ?int $remaining = null): self
    {
        return new self(LookupOutcome::Private_, null, $message, $remaining);
    }

    public static function rateLimited(string $message, ?int $resetsAt = null): self
    {
        return new self(LookupOutcome::RateLimited, null, $message, 0, $resetsAt);
    }

    public static function failed(string $message): self
    {
        return new self(LookupOutcome::Failed, null, $message);
    }

    public function secondsUntilReset(?int $now = null): ?int
    {
        if ($this->quotaResetsAt === null) {
            return null;
        }

        return max(0, $this->quotaResetsAt - ($now ?? time()));
    }
}
