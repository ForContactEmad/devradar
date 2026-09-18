<?php

declare(strict_types=1);

use DevRadar\Domain\Enrichment\LookupOutcome;
use DevRadar\Domain\Enrichment\RepositoryRef;
use DevRadar\Domain\Support\BackoffPolicy;
use DevRadar\Infrastructure\GitHub\GitHubClient;
use DevRadar\Infrastructure\GitHub\RetryingRepositoryProvider;
use DevRadar\Infrastructure\Http\HttpResponse;
use DevRadar\Infrastructure\Http\HttpTransportException;
use Tests\Fake\FakeHttpClient;
use Tests\Fake\RecordingLogger;

function repoRef(): RepositoryRef
{
    return RepositoryRef::of('acme', 'pgplan');
}

function repoPayload(array $overrides = []): string
{
    return json_encode(array_merge([
        'name' => 'pgplan',
        'owner' => ['login' => 'acme'],
        'description' => 'A Postgres query plan viewer.',
        'stargazers_count' => 3200,
        'forks_count' => 210,
        'open_issues_count' => 17,
        'language' => 'Rust',
        'created_at' => '2026-08-20T09:00:00Z',
        'pushed_at' => '2026-09-08T18:30:00Z',
        'license' => ['spdx_id' => 'MIT', 'name' => 'MIT License'],
        'topics' => ['postgres', 'CLI', 'postgres'],
        'default_branch' => 'main',
        'archived' => false,
        'fork' => false,
    ], $overrides), JSON_THROW_ON_ERROR);
}

function githubClient(FakeHttpClient $http, string $token = 'ghp_test', bool $contributors = false): GitHubClient
{
    return new GitHubClient($http, new RecordingLogger(), $token, 'https://api.github.com', 15.0, $contributors);
}

function rateHeaders(int $remaining = 4990, ?int $reset = null): array
{
    return [
        'x-ratelimit-limit' => '5000',
        'x-ratelimit-remaining' => (string) $remaining,
        'x-ratelimit-reset' => (string) ($reset ?? (time() + 1800)),
        'etag' => 'W/"abc123"',
    ];
}

// ---------------------------------------------------------- valid repository

it('extracts every documented field from a valid repository', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, repoPayload(), rateHeaders()));
    $result = githubClient($http)->lookup(repoRef());

    $facts = $result->facts;

    expect($result->outcome)->toBe(LookupOutcome::Found)
        ->and($facts->stars)->toBe(3200)
        ->and($facts->forks)->toBe(210)
        ->and($facts->openIssues)->toBe(17)
        ->and($facts->primaryLanguage)->toBe('Rust')
        ->and($facts->license)->toBe('MIT')
        ->and($facts->defaultBranch)->toBe('main')
        ->and($facts->pushedAt->format('Y-m-d'))->toBe('2026-09-08')
        ->and($facts->createdAt->format('Y-m-d'))->toBe('2026-08-20');
});

it('normalises and deduplicates topics', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, repoPayload(), rateHeaders()));
    $facts = githubClient($http)->lookup(repoRef())->facts;

    expect($facts->topics)->toBe(['postgres', 'cli']);
});

it('derives repository age and days since last commit', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, repoPayload(), rateHeaders()));
    $facts = githubClient($http)->lookup(repoRef())->facts;

    $now = new DateTimeImmutable('2026-09-09 12:00:00', new DateTimeZone('UTC'));

    // Derived, not stored: age changes every day and a stored value would be
    // wrong the moment after it was written.
    expect(round($facts->ageInDays($now)))->toBe(20.0)
        ->and(round($facts->daysSinceLastCommit($now), 1))->toBe(0.7);
});

it('sends the token and the API version', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, repoPayload(), rateHeaders()));
    githubClient($http, 'ghp_secret')->lookup(repoRef());

    $headers = $http->requests[0]['headers'];

    expect($headers['Authorization'])->toBe('Bearer ghp_secret')
        ->and($headers['X-GitHub-Api-Version'])->toBe('2022-11-28')
        ->and($headers)->toHaveKey('User-Agent');
});

it('follows a rename reported by GitHub rather than the requested path', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(
        200,
        repoPayload(['name' => 'pgplan-cli', 'owner' => ['login' => 'acme-labs']]),
        rateHeaders(),
    ));

    $facts = githubClient($http)->lookup(repoRef())->facts;

    // Otherwise the next conditional request goes to the old path forever.
    expect($facts->ref->fullName())->toBe('acme-labs/pgplan-cli');
});

// ------------------------------------------------------- conditional requests

it('sends a stored etag as a conditional request', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(304, '', rateHeaders(4999)));
    $result = githubClient($http)->lookup(repoRef(), 'W/"abc123"');

    expect($http->requests[0]['headers']['If-None-Match'])->toBe('W/"abc123"')
        ->and($result->outcome)->toBe(LookupOutcome::Unchanged)
        ->and($result->facts)->toBeNull();
});

it('reports a 304 as free when authenticated', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(304, '', rateHeaders()));
    $result = githubClient($http, 'ghp_test')->lookup(repoRef(), 'W/"abc"');

    expect($result->countedAgainstQuota)->toBeFalse();
});

it('reports a 304 as charged when unauthenticated', function () {
    // GitHub attaches "while correctly authorized with an Authorization
    // header" to the free-304 claim, and measured reports confirm
    // unauthenticated 304s still decrement the counter. Reporting it as free
    // would mislead the runner about its remaining budget.
    $http = (new FakeHttpClient())->queue(new HttpResponse(304, '', rateHeaders()));
    $result = githubClient($http, token: '')->lookup(repoRef(), 'W/"abc"');

    expect($result->countedAgainstQuota)->toBeTrue();
});

it('omits the authorization header when no token is configured', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, repoPayload(), rateHeaders()));
    githubClient($http, token: '')->lookup(repoRef());

    expect($http->requests[0]['headers'])->not->toHaveKey('Authorization');
});

// ------------------------------------------------------ missing and invalid

it('treats a deleted repository as permanently gone', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(404, '{"message":"Not Found"}', rateHeaders()));
    $result = githubClient($http)->lookup(repoRef());

    expect($result->outcome)->toBe(LookupOutcome::NotFound)
        ->and($result->outcome->isPermanent())->toBeTrue();
});

it('treats a moved repository as stale rather than retryable', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(301, '', rateHeaders()));

    expect(githubClient($http)->lookup(repoRef())->outcome)->toBe(LookupOutcome::NotFound);
});

it('treats a legally blocked repository as gone', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(451, '{}', rateHeaders()));

    expect(githubClient($http)->lookup(repoRef())->outcome)->toBe(LookupOutcome::NotFound);
});

it('distinguishes a private repository from a rate limit', function () {
    // Both answer 403. Treating a private repository as a rate limit would
    // stall the whole queue behind one inaccessible project.
    $http = (new FakeHttpClient())->queue(new HttpResponse(403, '{"message":"Forbidden"}', rateHeaders(4900)));
    $result = githubClient($http)->lookup(repoRef());

    expect($result->outcome)->toBe(LookupOutcome::Private_)
        ->and($result->outcome->isPermanent())->toBeTrue();
});

it('rejects a URL that is not a repository before spending a request', function () {
    // Parsing decides whether GitHub is called at all.
    expect(RepositoryRef::fromUrl('https://pgplan.dev'))->toBeNull()
        ->and(RepositoryRef::fromUrl('https://github.com/acme'))->toBeNull()
        ->and(RepositoryRef::fromUrl('https://github.com/topics/rust'))->toBeNull()
        ->and(RepositoryRef::fromUrl(null))->toBeNull()
        ->and(RepositoryRef::fromUrl('not a url'))->toBeNull();
});

it('parses a repository URL in its several forms', function () {
    foreach ([
        'https://github.com/acme/pgplan',
        'https://www.github.com/acme/pgplan',
        'https://github.com/acme/pgplan.git',
        'https://github.com/acme/pgplan/blob/main/README.md',
        'https://github.com/acme/pgplan/releases/tag/v1.0',
    ] as $url) {
        expect(RepositoryRef::fromUrl($url)?->fullName())->toBe('acme/pgplan');
    }
});

it('does not analyse non-GitHub hosts', function () {
    // They parse fine but have no client behind them, and returning a
    // reference we cannot service would queue work that permanently fails.
    expect(RepositoryRef::fromUrl('https://gitlab.com/acme/tool'))->toBeNull();
});

// ------------------------------------------------------------- rate limits

it('recognises an exhausted primary rate limit', function () {
    $reset = time() + 900;
    $http = (new FakeHttpClient())->queue(new HttpResponse(403, '{}', rateHeaders(0, $reset)));
    $result = githubClient($http)->lookup(repoRef());

    expect($result->outcome)->toBe(LookupOutcome::RateLimited)
        ->and($result->secondsUntilReset())->toBeGreaterThan(800);
});

it('recognises a secondary rate limit from retry-after', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(403, '{}', ['retry-after' => '60']));
    $result = githubClient($http)->lookup(repoRef());

    expect($result->outcome)->toBe(LookupOutcome::RateLimited);
});

it('handles a 429 as a rate limit', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(429, '{}', ['retry-after' => '30']));

    expect(githubClient($http)->lookup(repoRef())->outcome)->toBe(LookupOutcome::RateLimited);
});

it('reports remaining quota so the runner can stop cleanly', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(200, repoPayload(), rateHeaders(42)));
    $client = githubClient($http);

    expect($client->lookup(repoRef())->remainingQuota)->toBe(42)
        ->and($client->remainingQuota())->toBe(42);
});

// ------------------------------------------------------- failures and retry

it('treats a server error as transient', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(503, '', []));

    expect(githubClient($http)->lookup(repoRef())->outcome)->toBe(LookupOutcome::Failed);
});

it('treats a timeout as transient', function () {
    $http = (new FakeHttpClient())->queue(new HttpTransportException('cURL error 28: operation timed out'));
    $result = githubClient($http)->lookup(repoRef());

    expect($result->outcome)->toBe(LookupOutcome::Failed)
        ->and($result->message)->toContain('timed out');
});

it('never throws, whatever GitHub does', function () {
    // Enrichment is off the critical path: GitHub being down must not stop
    // the feed, and an exception would have to be caught by every caller.
    foreach ([500, 502, 418, 404, 403] as $status) {
        $http = (new FakeHttpClient())->queue(new HttpResponse($status, '{}', []));

        expect(githubClient($http)->lookup(repoRef()))->toBeInstanceOf(
            DevRadar\Domain\Enrichment\RepositoryLookup::class,
        );
    }
});

it('retries a transient failure and succeeds', function () {
    $http = (new FakeHttpClient())
        ->queue(new HttpResponse(503, '', []))
        ->queue(new HttpResponse(200, repoPayload(), rateHeaders()));

    $provider = new RetryingRepositoryProvider(
        githubClient($http), new BackoffPolicy(0.001, 1.0), new RecordingLogger(), 3,
        static fn (float $s) => null,
    );

    expect($provider->lookup(repoRef())->outcome)->toBe(LookupOutcome::Found)
        ->and($http->requestCount())->toBe(2);
});

it('never retries a permanent outcome', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(404, '{}', rateHeaders()));

    $provider = new RetryingRepositoryProvider(
        githubClient($http), new BackoffPolicy(0.001, 1.0), new RecordingLogger(), 3,
        static fn (float $s) => null,
    );

    // Retrying spends quota on something that will never resolve.
    expect($provider->lookup(repoRef())->outcome)->toBe(LookupOutcome::NotFound)
        ->and($http->requestCount())->toBe(1);
});

it('never retries a rate limit in process', function () {
    $http = (new FakeHttpClient())->queue(new HttpResponse(403, '{}', rateHeaders(0)));

    $provider = new RetryingRepositoryProvider(
        githubClient($http), new BackoffPolicy(0.001, 1.0), new RecordingLogger(), 3,
        static fn (float $s) => null,
    );

    // The window can be an hour away; blocking a worker that long starves
    // every other project in the queue.
    expect($provider->lookup(repoRef())->outcome)->toBe(LookupOutcome::RateLimited)
        ->and($http->requestCount())->toBe(1);
});

it('gives up at the attempt ceiling', function () {
    $http = new FakeHttpClient();

    for ($i = 0; $i < 3; $i++) {
        $http->queue(new HttpResponse(500, '', []));
    }

    $provider = new RetryingRepositoryProvider(
        githubClient($http), new BackoffPolicy(0.001, 1.0), new RecordingLogger(), 3,
        static fn (float $s) => null,
    );

    expect($provider->lookup(repoRef())->outcome)->toBe(LookupOutcome::Failed)
        ->and($http->requestCount())->toBe(3);
});

// ------------------------------------------------------------- contributors

it('reads the contributor count from the link header', function () {
    $mapper = new DevRadar\Infrastructure\GitHub\GitHubRepositoryMapper();

    $link = '<https://api.github.com/repos/a/b/contributors?per_page=1&page=2>; rel="next", '
        . '<https://api.github.com/repos/a/b/contributors?per_page=1&page=47>; rel="last"';

    // GitHub exposes no count field, so the last page number of a
    // one-per-page listing gives the total in a single request.
    expect($mapper->contributorCountFromLinkHeader($link, 1))->toBe(47);
});

it('treats a missing link header as a single page', function () {
    $mapper = new DevRadar\Infrastructure\GitHub\GitHubRepositoryMapper();

    expect($mapper->contributorCountFromLinkHeader(null, 1))->toBe(1);
});

it('degrades to a null contributor count rather than failing the lookup', function () {
    $http = (new FakeHttpClient())
        ->queue(new HttpResponse(200, repoPayload(), rateHeaders()))
        ->queue(new HttpResponse(403, '{}', []));

    $facts = githubClient($http, contributors: true)->lookup(repoRef())->facts;

    // The repository data we have is worth more than the count we could not
    // get. GitHub answers 403 when the contributor list is too large.
    expect($facts->contributors)->toBeNull()
        ->and($facts->stars)->toBe(3200);
});
