<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\X;

use DevRadar\Domain\Ingestion\SearchCriteria;
use InvalidArgumentException;

/**
 * Builds recent-search query parameters.
 *
 * All parameter names verified against the X API v2 OpenAPI specification
 * (version 2.168) for GET /2/tweets/search/recent.
 *
 * QUERY LENGTH: the OpenAPI schema declares maxLength 4096, but the product
 * documentation states 512 characters for recent search (4,096 for
 * Enterprise). The stricter of the two is enforced, configurably, because a
 * request rejected for length still costs a round trip and the discrepancy is
 * unresolved in X's own documentation.
 */
final readonly class XRequestBuilder
{
    public function __construct(private XApiConfig $config) {}

    /**
     * @return array<string, string|int>
     */
    public function build(SearchCriteria $criteria, ?string $paginationToken = null): array
    {
        $this->assertQueryFits($criteria->query);

        $params = [
            'query' => $criteria->query,
            'max_results' => $criteria->pageSize,
            // NOT `tweet.fields`: the current specification names this
            // parameter `post.fields`.
            'post.fields' => implode(',', $this->config->postFields),
        ];

        if ($this->config->expandAuthors) {
            $params['expansions'] = implode(',', XApiConfig::DEFAULT_EXPANSIONS);
            $params['user.fields'] = implode(',', $this->config->userFields);
        }

        // Mutual exclusivity is already guaranteed by SearchCriteria; this
        // just maps whichever side was supplied.
        if ($criteria->sinceId !== null) {
            $params['since_id'] = $criteria->sinceId;
        } elseif ($criteria->startTime !== null) {
            $params['start_time'] = $criteria->startTime->format('Y-m-d\TH:i:s\Z');
        }

        if ($criteria->untilId !== null) {
            $params['until_id'] = $criteria->untilId;
        } elseif ($criteria->endTime !== null) {
            $params['end_time'] = $criteria->endTime->format('Y-m-d\TH:i:s\Z');
        }

        if ($criteria->sortOrder !== null) {
            $params['sort_order'] = $criteria->sortOrder;
        }

        if ($paginationToken !== null) {
            $params['pagination_token'] = $paginationToken;
        }

        return $params;
    }

    /**
     * Author expansion is what makes a search cost more than the posts alone:
     * user objects are billed separately and at twice the per-resource rate
     * of posts. Turning it off is a real cost lever, so the caller is told
     * plainly what it buys.
     */
    public function assertQueryFits(string $query): void
    {
        $length = mb_strlen($query);

        if ($length > $this->config->maxQueryLength) {
            throw new InvalidArgumentException(sprintf(
                'Query is %d characters; the configured limit is %d. Split it into several queries '
                . 'rather than truncating, which would silently change what is matched.',
                $length,
                $this->config->maxQueryLength,
            ));
        }
    }
}
