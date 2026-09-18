<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\X;

use DevRadar\Domain\Support\LogRedactor;

use DevRadar\Domain\Ingestion\PostBatch;
use DevRadar\Domain\Ingestion\SearchCriteria;
use DevRadar\Domain\Ingestion\StopReason;
use DevRadar\Domain\Port\BudgetGuardInterface;
use DevRadar\Domain\Port\PostProviderInterface;
use DevRadar\Infrastructure\Http\HttpClientInterface;
use DevRadar\Infrastructure\Http\HttpResponse;
use DevRadar\Infrastructure\Http\HttpTransportException;
use Psr\Log\LoggerInterface;

/**
 * The metered X provider. The only component in DevRadar that spends money.
 *
 * Everything upstream of this class receives DevRadar's own DTOs and never
 * learns that X exists. Replacing X means writing another implementation of
 * PostProviderInterface and changing one configuration value.
 *
 * Three behaviours here are cost-safety controls rather than features, and
 * should be treated as non-negotiable:
 *
 *   1. The budget guard is consulted BEFORE every request. The charge is
 *      incurred by the response, so asking afterwards is asking too late.
 *   2. A budget refusal returns a batch, it does not throw. Running out of
 *      budget is an expected operating condition; treating it as a fault
 *      invites a retry, and a retry is a repeat purchase.
 *   3. Permanent and auth errors are never retried. Retrying a malformed
 *      query cannot succeed and each attempt is another round trip.
 */
final readonly class XPostProvider implements PostProviderInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private XApiConfig $config,
        private XRequestBuilder $requestBuilder,
        private XPostMapper $mapper,
        private XErrorClassifier $classifier,
        private RetryPolicy $retryPolicy,
        private BudgetGuardInterface $budget,
        private LoggerInterface $logger,
        /** Injected so tests do not actually sleep. */
        private ?\Closure $sleeper = null,
    ) {}

    public function name(): string
    {
        return 'x';
    }

    public function search(SearchCriteria $criteria): PostBatch
    {
        // Fail fast, before any request, if the query cannot be sent at all.
        $this->requestBuilder->assertQueryFits($criteria->query);

        $posts = [];
        $authors = [];
        $partialErrors = [];
        $newestId = null;
        $oldestId = null;
        $nextToken = null;
        $requestCount = 0;
        $billable = 0;
        $stopReason = StopReason::Exhausted;

        $paginationToken = null;

        for ($page = 1; $page <= $criteria->maxPages; $page++) {
            $remainingPosts = $criteria->maxPosts - count($posts);

            if ($remainingPosts <= 0) {
                $stopReason = StopReason::PostCapReached;
                break;
            }

            // Never ask for more than the run's remaining allowance. The page
            // size floor of 10 is X's, not ours.
            $pageSize = max(10, min($criteria->pageSize, $remainingPosts));

            // A page can bill for posts plus one author object each, so the
            // worst case is roughly double the page size.
            if (! $this->budget->allows($pageSize * 2)) {
                $this->logger->warning('x.search.budget_refused', [
                    'provider' => $this->name(),
                    'page' => $page,
                    'posts_so_far' => count($posts),
                ]);

                $stopReason = StopReason::BudgetRefused;
                break;
            }

            $response = $this->requestWithRetry(
                $this->requestBuilder->build($criteria->withPageSize($pageSize), $paginationToken),
                $page,
            );

            $requestCount++;

            $payload = $response->json();
            $counts = $this->mapper->countBillableResources($payload);
            $billable += $counts['total'];
            $this->budget->record($counts['total']);

            $mapped = $this->mapper->mapPosts($payload);
            $posts = array_merge($posts, $mapped['posts']);
            $authors += $this->mapper->mapAuthors($payload);
            $partialErrors = array_merge($partialErrors, $this->mapper->partialErrors($payload));

            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $newestId ??= isset($meta['newest_id']) ? (string) $meta['newest_id'] : null;
            $oldestId = isset($meta['oldest_id']) ? (string) $meta['oldest_id'] : $oldestId;

            $rateLimit = RateLimitState::fromResponse($response);

            $this->logger->info('x.search.page', LogRedactor::context([
                'provider' => $this->name(),
                'page' => $page,
                'result_count' => $meta['result_count'] ?? count($mapped['posts']),
                'billable_posts' => $counts['posts'],
                'billable_users' => $counts['users'],
                'skipped' => count($mapped['skipped']),
                'partial_errors' => count($this->mapper->partialErrors($payload)),
                ...$rateLimit->toLogContext(),
            ]));

            if ($mapped['skipped'] !== []) {
                $this->logger->warning('x.search.unmappable_posts', [
                    'provider' => $this->name(),
                    'count' => count($mapped['skipped']),
                    'ids' => array_slice($mapped['skipped'], 0, 10),
                ]);
            }

            $nextToken = isset($meta['next_token']) ? (string) $meta['next_token'] : null;

            if ($nextToken === null) {
                $stopReason = StopReason::Exhausted;
                break;
            }

            $paginationToken = $nextToken;

            if ($page === $criteria->maxPages) {
                $stopReason = StopReason::PageCapReached;
            }

            // Slow down before hitting the wall rather than after.
            if ($rateLimit->isNearlyExhausted()) {
                $wait = $rateLimit->secondsUntilReset();

                $this->logger->warning('x.search.rate_limit_pacing', [
                    'provider' => $this->name(),
                    ...$rateLimit->toLogContext(),
                ]);

                if ($wait !== null && $wait > 0) {
                    $this->sleep((float) $wait);
                }
            }
        }

        $this->logger->info('x.search.complete', [
            'provider' => $this->name(),
            'posts' => count($posts),
            'authors' => count($authors),
            'requests' => $requestCount,
            'billable_resources' => $billable,
            'stop_reason' => $stopReason->value,
        ]);

        return new PostBatch(
            posts: $posts,
            authors: $authors,
            newestId: $newestId,
            oldestId: $oldestId,
            nextToken: $nextToken,
            requestCount: $requestCount,
            billableResources: $billable,
            stopReason: $stopReason,
            partialErrors: $partialErrors,
        );
    }

    /**
     * @param array<string, string|int> $params
     *
     * @throws XApiException
     */
    private function requestWithRetry(array $params, int $page): HttpResponse
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http->get(
                    $this->config->searchRecentUrl(),
                    $params,
                    $this->config->authHeaders(),
                    $this->config->timeoutSeconds,
                );

                if ($response->isSuccess()) {
                    return $response;
                }

                $error = $this->classifier->classify($response);
            } catch (HttpTransportException $e) {
                // No response means no charge, so this is always safe to retry.
                $error = new XApiException($e->getMessage(), 'transient');
            }

            if (! $this->retryPolicy->shouldRetry($error, $attempt)) {
                $this->logger->error('x.search.failed', [
                    'provider' => $this->name(),
                    'page' => $page,
                    'attempt' => $attempt,
                    'error_class' => $error->errorClass,
                    'status' => $error->status,
                    // Already redacted by XApiException's constructor.
                    'message' => $error->getMessage(),
                ]);

                throw $error;
            }

            $delay = $this->retryPolicy->delayFor($error, $attempt);

            $this->logger->warning('x.search.retrying', [
                'provider' => $this->name(),
                'page' => $page,
                'attempt' => $attempt,
                'error_class' => $error->errorClass,
                'status' => $error->status,
                'delay_seconds' => $delay,
            ]);

            $this->sleep($delay);
        }
    }

    private function sleep(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }
}
