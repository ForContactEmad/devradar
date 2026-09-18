<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Enrichment\RepositoryLookup;
use DevRadar\Domain\Enrichment\RepositoryRef;
use DevRadar\Domain\Port\RepositoryProviderInterface;
use RuntimeException;

/**
 * Scripted repository provider.
 *
 * No test in DevRadar may call the real GitHub API: a suite that spends rate
 * limit is a suite that fails intermittently for reasons unrelated to the code.
 */
final class FakeRepositoryProvider implements RepositoryProviderInterface
{
    /** @var list<RepositoryLookup> */
    private array $queue = [];

    /** @var list<array{ref: string, etag: ?string}> */
    public array $calls = [];

    public function queue(RepositoryLookup ...$results): self
    {
        foreach ($results as $result) {
            $this->queue[] = $result;
        }

        return $this;
    }

    public function name(): string
    {
        return 'fake-github';
    }

    public function remainingQuota(): ?int
    {
        return null;
    }

    public function lookup(RepositoryRef $ref, ?string $knownEtag = null): RepositoryLookup
    {
        $this->calls[] = ['ref' => $ref->fullName(), 'etag' => $knownEtag];

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException(sprintf(
                'FakeRepositoryProvider ran out of scripted results after %d call(s).',
                count($this->calls),
            ));
        }

        return $next;
    }

    public function callCount(): int
    {
        return count($this->calls);
    }
}
