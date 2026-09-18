<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\GitHub;

use DevRadar\Domain\Enrichment\RepositoryLookup;
use DevRadar\Domain\Enrichment\RepositoryRef;
use DevRadar\Domain\Port\RepositoryProviderInterface;
use DevRadar\Infrastructure\Http\HttpClientInterface;
use DevRadar\Infrastructure\Http\HttpResponse;
use DevRadar\Domain\Support\LogRedactor;
use DevRadar\Infrastructure\Http\HttpTransportException;
use Psr\Log\LoggerInterface;

/**
 * GitHub REST transport.
 *
 * NEVER THROWS. Every failure becomes a RepositoryLookup outcome, because
 * enrichment is off the critical path: GitHub being down must not stop the
 * feed, and an exception here would have to be caught by every caller anyway.
 *
 * AUTHENTICATION IS NOT OPTIONAL IN PRACTICE. Verified against GitHub's
 * current documentation: unauthenticated requests get 60 per hour,
 * authenticated ones get 5,000. At DevRadar's volumes 60/hour is workable for
 * a day and hopeless for a week.
 *
 * CONDITIONAL REQUESTS, WITH A CAVEAT WORTH KNOWING. Sending a stored ETag
 * as If-None-Match returns 304 when nothing changed. GitHub documents that a
 * 304 does not count against the primary rate limit -- but attaches the
 * condition "when the request was made while correctly authorized with an
 * Authorization header". Measured reports confirm that unauthenticated 304s
 * still decrement the counter. So ETags save bandwidth for everyone and quota
 * only for token holders, which is exactly backwards from where the budget is
 * tight. The client reports whether a 304 was free so the caller is not
 * misled about its remaining quota.
 */
final class GitHubClient implements RepositoryProviderInterface
{
    private const API_VERSION = '2022-11-28';

    /** Last reported quota. Mutable, which is why this class is not readonly. */
    private ?int $lastRemaining = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $token = '',
        private readonly string $baseUrl = 'https://api.github.com',
        private readonly float $timeoutSeconds = 15.0,
        /**
         * Contributor count needs a second request per repository. At 5,000
         * per hour that is affordable; without a token it doubles the cost of
         * every lookup against a 60/hour budget.
         */
        private readonly bool $fetchContributors = true,
        private readonly ?\Closure $quotaSink = null,
    ) {}

    public function name(): string
    {
        return 'github';
    }

    public function remainingQuota(): ?int
    {
        return $this->lastRemaining;
    }

    public function lookup(RepositoryRef $ref, ?string $knownEtag = null): RepositoryLookup
    {
        $headers = $this->headers();

        if ($knownEtag !== null && $knownEtag !== '') {
            $headers['If-None-Match'] = $knownEtag;
        }

        try {
            $response = $this->http->get(
                sprintf('%s/repos/%s/%s', rtrim($this->baseUrl, '/'), $ref->owner, $ref->name),
                [],
                $headers,
                $this->timeoutSeconds,
            );
        } catch (HttpTransportException $e) {
            // No response means no quota spent and the work may not have
            // happened, so this is safe to retry.
            // Redacted like every other provider's errors. Transport
            // messages can carry the full request URL, and applying the
            // redactor everywhere is cheaper than auditing which providers
            // happen to put credentials in one.
            return RepositoryLookup::failed('Transport failure: ' . LogRedactor::text($e->getMessage()));
        }

        $remaining = $this->intHeader($response, 'x-ratelimit-remaining');
        $reset = $this->intHeader($response, 'x-ratelimit-reset');
        $this->lastRemaining = $remaining;

        if ($this->quotaSink !== null && $remaining !== null) {
            ($this->quotaSink)($remaining, $reset);
        }

        return match (true) {
            $response->status === 304 => RepositoryLookup::unchanged(
                $remaining,
                $reset,
                // Free only when we sent an Authorization header.
                counted: $this->token === '',
            ),

            $response->status === 200 => $this->mapFound($response, $ref, $remaining, $reset),

            // A renamed repository answers 301 with the new location. Treated
            // as not-found so the stale reference stops being polled; the
            // project keeps its link and a later extraction can pick up the
            // new one.
            $response->status === 301 => RepositoryLookup::notFound(
                'Repository has moved permanently; the stored URL is stale.',
                $remaining,
            ),

            $response->status === 404 => RepositoryLookup::notFound(
                'Repository not found: deleted, renamed, or never public.',
                $remaining,
            ),

            // GitHub answers 403 for both "you are out of quota" and "you may
            // not see this". The remaining counter is what separates them,
            // and treating a private repository as a rate limit would stall
            // the whole queue behind one inaccessible project.
            $response->status === 403 || $response->status === 429 => $this->mapForbidden($response, $remaining, $reset),

            // Access blocked for legal reasons. Permanent, like a deletion.
            $response->status === 451 => RepositoryLookup::notFound(
                'Repository unavailable for legal reasons.',
                $remaining,
            ),

            $response->status >= 500 => RepositoryLookup::failed(
                sprintf('GitHub server error (%d).', $response->status),
            ),

            default => RepositoryLookup::failed(
                sprintf('Unexpected GitHub response (%d).', $response->status),
            ),
        };
    }

    private function mapFound(HttpResponse $response, RepositoryRef $ref, ?int $remaining, ?int $reset): RepositoryLookup
    {
        $mapper = new GitHubRepositoryMapper();
        $facts = $mapper->map($response->json(), $ref, $response->header('etag'));

        if ($this->fetchContributors) {
            $facts = $facts->withContributors($this->contributorCount($facts->ref));
        }

        return RepositoryLookup::found($facts, $remaining, $reset);
    }

    /**
     * Contributor count via the Link header's last page number.
     *
     * A failure here degrades to null rather than failing the whole lookup:
     * the repository data we already have is worth more than the contributor
     * count we could not get.
     */
    private function contributorCount(RepositoryRef $ref): ?int
    {
        try {
            $response = $this->http->get(
                sprintf('%s/repos/%s/%s/contributors', rtrim($this->baseUrl, '/'), $ref->owner, $ref->name),
                ['per_page' => 1, 'anon' => 'false'],
                $this->headers(),
                $this->timeoutSeconds,
            );
        } catch (HttpTransportException) {
            return null;
        }

        if (! $response->isSuccess()) {
            // 204 for an empty repository, 403 when the list is too large to
            // compute. Neither is worth an error.
            return null;
        }

        $items = $response->json();

        return (new GitHubRepositoryMapper())->contributorCountFromLinkHeader(
            $response->header('link'),
            is_array($items) ? count($items) : 0,
        );
    }

    private function mapForbidden(HttpResponse $response, ?int $remaining, ?int $reset): RepositoryLookup
    {
        $retryAfter = $this->intHeader($response, 'retry-after');

        // Secondary rate limits answer 403 with retry-after and a non-zero
        // primary counter, so the header is checked before the counter.
        if ($retryAfter !== null) {
            return RepositoryLookup::rateLimited(
                sprintf('Secondary rate limit; retry after %d seconds.', $retryAfter),
                time() + $retryAfter,
            );
        }

        if ($remaining !== null && $remaining <= 0) {
            $this->logger->warning('github.rate_limit_exhausted', [
                'resets_in_seconds' => $reset === null ? null : max(0, $reset - time()),
            ]);

            return RepositoryLookup::rateLimited('Primary rate limit exhausted.', $reset);
        }

        return RepositoryLookup::private_('Repository is private or access is blocked.', $remaining);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
            // GitHub asks for a User-Agent and rejects requests without one.
            'User-Agent' => 'DevRadar',
        ];

        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        return $headers;
    }

    private function intHeader(HttpResponse $response, string $name): ?int
    {
        $value = $response->header($name);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
