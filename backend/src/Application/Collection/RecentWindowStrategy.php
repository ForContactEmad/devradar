<?php

declare(strict_types=1);

namespace DevRadar\Application\Collection;

use DevRadar\Domain\Collection\SearchPlan;
use DevRadar\Domain\Ingestion\SearchCriteria;
use DevRadar\Domain\Collection\WindowResolver;
use DevRadar\Domain\Port\SearchQuerySourceInterface;
use DevRadar\Domain\Port\SearchStrategyInterface;
use DevRadar\Domain\Port\TweetRepositoryInterface;

/**
 * The default strategy: sweep the rolling window, incrementally.
 *
 * Its only job is turning the active query set into criteria. It performs no
 * requests and stores nothing, which is what makes the window logic and the
 * incremental logic testable without a network or a database.
 *
 * INCREMENTAL BY DEFAULT. For a query DevRadar has already run, the plan uses
 * since_id -- the highest post id already stored -- rather than a start time.
 * Re-fetching a post already held costs exactly as much as fetching a new
 * one, so the only free page is the one never requested.
 *
 * A query with no history falls back to the window start. The provider
 * forbids sending both, and SearchCriteria enforces that too, so the choice
 * is made once, here.
 */
final readonly class RecentWindowStrategy implements SearchStrategyInterface
{
    public function __construct(
        private SearchQuerySourceInterface $queries,
        private WindowResolver $windows,
        private TweetRepositoryInterface $repository,
        private int $pageSize = 100,
        private int $maxPostsPerQuery = 300,
        private int $maxPagesPerQuery = 5,
    ) {}

    /** @return list<SearchPlan> */
    public function plan(): array
    {
        // One window for the whole cycle. Resolving per query would let two
        // queries in the same run cover slightly different spans, which makes
        // yield comparisons between them meaningless.
        $window = $this->windows->resolve();
        $plans = [];

        foreach ($this->queries->activeQueries() as $definition) {
            $sinceId = $definition->id !== null
                ? $this->repository->highestSeenId($definition->id)
                : null;

            $plans[] = new SearchPlan(
                definition: $definition,
                criteria: new SearchCriteria(
                    query: $definition->expression,
                    // Exactly one of these is ever set.
                    sinceId: $sinceId,
                    startTime: $sinceId === null ? $window->start : null,
                    endTime: $sinceId === null ? $window->end : null,
                    pageSize: min($this->pageSize, $definition->maxResults),
                    maxPosts: $this->maxPostsPerQuery,
                    maxPages: $this->maxPagesPerQuery,
                ),
                window: $window,
            );
        }

        return $plans;
    }
}
