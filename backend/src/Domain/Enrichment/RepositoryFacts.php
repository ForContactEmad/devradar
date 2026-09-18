<?php

declare(strict_types=1);

namespace DevRadar\Domain\Enrichment;

use DateTimeImmutable;

/**
 * What GitHub reports about a repository.
 *
 * Every field is nullable because every field can genuinely be absent: a new
 * repository has no language, many have no licence, topics are optional, and
 * the contributor count comes from a separate request that may be skipped.
 * A null here is "GitHub did not tell us", never "zero".
 */
final readonly class RepositoryFacts
{
    /** @param list<string> $topics */
    public function __construct(
        public RepositoryRef $ref,
        public ?int $stars = null,
        public ?int $forks = null,
        public ?int $openIssues = null,
        public ?int $contributors = null,
        public ?string $primaryLanguage = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $pushedAt = null,
        public ?string $license = null,
        public array $topics = [],
        public ?string $description = null,
        public ?string $defaultBranch = null,
        public bool $isArchived = false,
        public bool $isFork = false,
        public ?string $etag = null,
    ) {}

    /**
     * Repository age in days at a given moment.
     *
     * Derived rather than stored: age changes every day, and a stored value
     * would be wrong the moment after it was written.
     */
    public function ageInDays(DateTimeImmutable $now): ?float
    {
        if ($this->createdAt === null) {
            return null;
        }

        return max(0.0, ($now->getTimestamp() - $this->createdAt->getTimestamp()) / 86400);
    }

    public function daysSinceLastCommit(DateTimeImmutable $now): ?float
    {
        if ($this->pushedAt === null) {
            return null;
        }

        return max(0.0, ($now->getTimestamp() - $this->pushedAt->getTimestamp()) / 86400);
    }

    public function withContributors(?int $contributors): self
    {
        return new self(
            $this->ref, $this->stars, $this->forks, $this->openIssues, $contributors,
            $this->primaryLanguage, $this->createdAt, $this->pushedAt, $this->license,
            $this->topics, $this->description, $this->defaultBranch,
            $this->isArchived, $this->isFork, $this->etag,
        );
    }
}
