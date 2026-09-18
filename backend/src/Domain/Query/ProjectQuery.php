<?php

declare(strict_types=1);

namespace DevRadar\Domain\Query;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A validated request for a page of projects.
 *
 * VALIDATION LIVES HERE, NOT IN THE CONTROLLER. A controller that validates
 * is a controller that holds a business rule, and the same rule would then
 * have to be repeated for every other caller -- a console command, a feed
 * generator, a future GraphQL layer. Constructing this object is the only way
 * to express a project query, and it cannot be constructed invalid.
 *
 * The controller's whole job becomes: turn request parameters into this,
 * catch InvalidArgumentException, return 422.
 */
final readonly class ProjectQuery
{
    public const MAX_PER_PAGE = 100;
    public const DEFAULT_PER_PAGE = 20;

    /**
     * @param list<string> $categories
     * @param list<string> $technologies
     */
    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ProjectSort $sort = ProjectSort::Score,
        public ?string $search = null,
        public array $categories = [],
        public array $technologies = [],
        public ?string $projectType = null,
        public ?bool $hasRepository = null,
        public ?float $minScore = null,
        public ?int $withinDays = null,
        /** Total likes, reposts, replies and quotes on the source post. */
        public ?int $minEngagement = null,
        /** Explicit date bounds, for callers that think in dates rather than ages. */
        public ?DateTimeImmutable $since = null,
        public ?DateTimeImmutable $until = null,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be 1 or greater.');
        }

        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException(
                sprintf('per_page must be between 1 and %d.', self::MAX_PER_PAGE),
            );
        }

        if ($sort->requiresSearchTerm() && ($search === null || trim($search) === '')) {
            // Silently falling back to score would give a different result
            // than the caller asked for while reporting success.
            throw new InvalidArgumentException('sort=relevance requires a search term.');
        }

        if ($search !== null && mb_strlen(trim($search)) < 2) {
            // A single character matches almost everything and costs a full
            // scan to discover that.
            throw new InvalidArgumentException('search must be at least 2 characters.');
        }

        if ($minScore !== null && ($minScore < 0 || $minScore > 100)) {
            throw new InvalidArgumentException('min_score must be between 0 and 100.');
        }

        if ($withinDays !== null && ($withinDays < 1 || $withinDays > 7)) {
            // The feed is a rolling seven-day window; asking for 30 days
            // would return seven days of results and misreport what it did.
            throw new InvalidArgumentException('within_days must be between 1 and 7.');
        }

        if ($minEngagement !== null && $minEngagement < 0) {
            throw new InvalidArgumentException('min_engagement cannot be negative.');
        }

        if ($since !== null && $until !== null && $since > $until) {
            // Returning nothing would be technically correct and completely
            // unhelpful: the caller has almost certainly swapped them.
            throw new InvalidArgumentException('since must be earlier than until.');
        }

        if ($withinDays !== null && ($since !== null || $until !== null)) {
            // Two ways of saying the same thing, which would silently
            // intersect and produce a range the caller did not ask for.
            throw new InvalidArgumentException('Use either within_days or since/until, not both.');
        }

        foreach ($categories as $category) {
            if (! is_string($category) || trim($category) === '') {
                throw new InvalidArgumentException('categories must be non-empty strings.');
            }
        }

        foreach ($technologies as $technology) {
            if (! is_string($technology) || trim($technology) === '') {
                throw new InvalidArgumentException('technologies must be non-empty strings.');
            }
        }
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * The filters actually in force, for showing the person what they applied.
     *
     * Built here rather than in the interface, so a console command or a feed
     * generator describes a query the same way the dashboard does.
     *
     * @return list<array{key: string, label: string}>
     */
    public function activeFilters(): array
    {
        $active = [];

        if ($this->search !== null) {
            $active[] = ['key' => 'q', 'label' => 'matching "' . $this->normalisedSearch() . '"'];
        }

        foreach ($this->categories as $category) {
            $active[] = ['key' => 'category:' . $category, 'label' => $category];
        }

        foreach ($this->technologies as $technology) {
            $active[] = ['key' => 'technology:' . $technology, 'label' => $technology];
        }

        if ($this->projectType !== null) {
            $active[] = ['key' => 'type', 'label' => $this->projectType];
        }

        if ($this->hasRepository !== null) {
            $active[] = ['key' => 'repo', 'label' => $this->hasRepository ? 'with source code' : 'without source code'];
        }

        if ($this->minScore !== null) {
            $active[] = ['key' => 'min_score', 'label' => 'score ' . $this->minScore . '+'];
        }

        if ($this->minEngagement !== null) {
            $active[] = ['key' => 'min_engagement', 'label' => $this->minEngagement . '+ interactions'];
        }

        if ($this->withinDays !== null) {
            $active[] = ['key' => 'within_days', 'label' => 'last ' . $this->withinDays . ' days'];
        }

        if ($this->since !== null) {
            $active[] = ['key' => 'since', 'label' => 'from ' . $this->since->format('j M')];
        }

        if ($this->until !== null) {
            $active[] = ['key' => 'until', 'label' => 'to ' . $this->until->format('j M')];
        }

        return $active;
    }

    public function normalisedSearch(): ?string
    {
        return $this->search === null ? null : trim($this->search);
    }

    public function hasFilters(): bool
    {
        return $this->categories !== []
            || $this->technologies !== []
            || $this->projectType !== null
            || $this->hasRepository !== null
            || $this->minScore !== null
            || $this->withinDays !== null
            || $this->minEngagement !== null
            || $this->since !== null
            || $this->until !== null
            || $this->search !== null;
    }
}
