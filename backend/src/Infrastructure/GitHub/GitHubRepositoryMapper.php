<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\GitHub;

use DateTimeImmutable;
use DevRadar\Domain\Enrichment\RepositoryFacts;
use DevRadar\Domain\Enrichment\RepositoryRef;

/**
 * Maps GitHub's payload into DevRadar's own shape. Pure, no I/O.
 *
 * The containment boundary for GitHub's field names. `stargazers_count`,
 * `pushed_at` and `license.spdx_id` are GitHub's vocabulary and stop here.
 *
 * NOTE ON open_issues_count: GitHub counts pull requests as issues in this
 * field. It is stored as reported rather than corrected, because subtracting
 * a PR count we do not have would be a guess, and the field is documented as
 * what it is.
 */
final readonly class GitHubRepositoryMapper
{
    /** @param array<string, mixed> $payload */
    public function map(array $payload, RepositoryRef $requested, ?string $etag = null): RepositoryFacts
    {
        // A repository that has been renamed answers under its new name. Use
        // what GitHub reports, not what we asked for, or the next conditional
        // request goes to the old path forever.
        $ref = $this->refFromPayload($payload) ?? $requested;

        return new RepositoryFacts(
            ref: $ref,
            stars: $this->int($payload['stargazers_count'] ?? null),
            forks: $this->int($payload['forks_count'] ?? null),
            openIssues: $this->int($payload['open_issues_count'] ?? null),
            // Not in this payload: it needs a separate request.
            contributors: null,
            primaryLanguage: $this->string($payload['language'] ?? null),
            createdAt: $this->timestamp($payload['created_at'] ?? null),
            pushedAt: $this->timestamp($payload['pushed_at'] ?? null),
            // spdx_id is the stable identifier; `name` is display text that
            // changes wording between licences of the same family.
            license: $this->string($payload['license']['spdx_id'] ?? null),
            topics: $this->topics($payload['topics'] ?? null),
            description: $this->string($payload['description'] ?? null),
            defaultBranch: $this->string($payload['default_branch'] ?? null),
            isArchived: (bool) ($payload['archived'] ?? false),
            isFork: (bool) ($payload['fork'] ?? false),
            etag: $etag,
        );
    }

    /**
     * Contributor count from a paginated listing.
     *
     * GitHub does not expose a count directly. Requesting one contributor per
     * page and reading the last page number from the Link header gives the
     * total in a single request instead of walking every page.
     */
    public function contributorCountFromLinkHeader(?string $linkHeader, int $itemsReturned): ?int
    {
        if ($linkHeader === null || trim($linkHeader) === '') {
            // No Link header means a single page: the count is what came back.
            return $itemsReturned;
        }

        if (preg_match('/[?&]page=(\d+)[^>]*>;\s*rel="last"/', $linkHeader, $matches) === 1) {
            return (int) $matches[1];
        }

        return $itemsReturned > 0 ? $itemsReturned : null;
    }

    /** @param array<string, mixed> $payload */
    private function refFromPayload(array $payload): ?RepositoryRef
    {
        $owner = $this->string($payload['owner']['login'] ?? null);
        $name = $this->string($payload['name'] ?? null);

        if ($owner === null || $name === null) {
            return null;
        }

        return RepositoryRef::of($owner, $name);
    }

    /** @return list<string> */
    private function topics(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $topics = [];

        foreach ($value as $topic) {
            if (is_string($topic) && trim($topic) !== '') {
                $topics[] = mb_strtolower(trim($topic));
            }
        }

        return array_values(array_unique($topics));
    }

    private function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
