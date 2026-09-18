<?php

declare(strict_types=1);

namespace DevRadar\Domain\Query;

/**
 * Repository facts as exposed by the API.
 *
 * Read from stored rows only. Nothing on the read path calls GitHub: a
 * dashboard request that triggered an API call would put the feed's
 * availability at the mercy of a third party and burn rate limit on every
 * page view.
 *
 * @param list<string> $topics
 */
final readonly class RepositoryView
{
    /** @param list<string> $topics */
    public function __construct(
        public string $url,
        public ?int $stars,
        public ?int $forks,
        public ?int $openIssues,
        public ?int $contributors,
        public ?string $primaryLanguage,
        public ?string $license,
        public array $topics,
        public ?string $lastCommitAt,
        public ?string $createdAt,
        public bool $isArchived,
        public ?string $fetchedAt,
    ) {}
}
