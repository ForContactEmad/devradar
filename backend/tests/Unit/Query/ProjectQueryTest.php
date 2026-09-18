<?php

declare(strict_types=1);

use DevRadar\Application\Query\ProjectQueryService;
use DevRadar\Application\Query\StatisticsService;
use DevRadar\Application\Query\TaxonomyQueryService;
use DevRadar\Domain\Query\Paginated;
use DevRadar\Domain\Query\ProjectDetail;
use DevRadar\Domain\Query\ProjectNotFound;
use DevRadar\Domain\Query\ProjectQuery;
use DevRadar\Domain\Query\ProjectSort;
use DevRadar\Domain\Query\ProjectSummary;
use DevRadar\Domain\Query\TaxonomyCount;
use Tests\Fake\InMemoryProjectQueryRepository;

function summary(
    string $slug,
    float $score = 50.0,
    string $category = 'developer-tools',
    array $technologies = ['laravel'],
    ?string $repositoryUrl = 'https://github.com/a/b',
    string $discovered = '2026-09-08 12:00:00',
    string $name = 'Project',
    ?string $description = 'a tool for databases',
): ProjectSummary {
    return new ProjectSummary(
        slug: $slug,
        name: $name,
        description: $description,
        category: $category,
        projectType: 'cli',
        technologies: $technologies,
        repositoryUrl: $repositoryUrl,
        websiteUrl: null,
        demoUrl: null,
        sourcePostUrl: 'https://x.com/a/status/1',
        authorHandle: 'acmedev',
        score: $score,
        discoveredAt: new DateTimeImmutable($discovered, new DateTimeZone('UTC')),
    );
}

/** @return array{0: ProjectQueryService, 1: InMemoryProjectQueryRepository} */
function projectService(array $projects = []): array
{
    $repo = new InMemoryProjectQueryRepository();
    $repo->projects = $projects;

    return [new ProjectQueryService($repo), $repo];
}

// ============================================================ VALIDATION

it('rejects a page below one', function () {
    expect(fn () => new ProjectQuery(page: 0))->toThrow(InvalidArgumentException::class, 'page must be 1');
});

it('rejects a per_page above the ceiling', function () {
    // Without a cap, one request can ask for the whole table.
    expect(fn () => new ProjectQuery(perPage: 500))->toThrow(InvalidArgumentException::class, 'per_page');
    expect(fn () => new ProjectQuery(perPage: 0))->toThrow(InvalidArgumentException::class);
    expect((new ProjectQuery(perPage: 100))->perPage)->toBe(100);
});

it('rejects relevance sorting without a search term', function () {
    // Silently falling back to score would give a different result than the
    // caller asked for while reporting success.
    expect(fn () => new ProjectQuery(sort: ProjectSort::Relevance))
        ->toThrow(InvalidArgumentException::class, 'requires a search term');

    expect((new ProjectQuery(sort: ProjectSort::Relevance, search: 'postgres'))->sort)
        ->toBe(ProjectSort::Relevance);
});

it('rejects a single-character search', function () {
    // It matches almost everything and costs a full scan to discover that.
    expect(fn () => new ProjectQuery(search: 'x'))->toThrow(InvalidArgumentException::class, 'at least 2');
});

it('rejects a window longer than the feed', function () {
    // Asking for 30 days would return seven and misreport what it did.
    expect(fn () => new ProjectQuery(withinDays: 30))->toThrow(InvalidArgumentException::class, 'within_days');
    expect(fn () => new ProjectQuery(withinDays: 0))->toThrow(InvalidArgumentException::class);
});

it('rejects a score outside the scale', function () {
    expect(fn () => new ProjectQuery(minScore: 150))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ProjectQuery(minScore: -1))->toThrow(InvalidArgumentException::class);
});

it('rejects malformed filter arrays', function () {
    expect(fn () => new ProjectQuery(categories: ['']))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ProjectQuery(technologies: [123]))->toThrow(InvalidArgumentException::class);
});

it('accepts a bare query with sensible defaults', function () {
    $query = new ProjectQuery();

    expect($query->page)->toBe(1)
        ->and($query->perPage)->toBe(20)
        ->and($query->sort)->toBe(ProjectSort::Score)
        ->and($query->hasFilters())->toBeFalse();
});

// ============================================================ SUCCESSFUL READS

it('returns a page of projects', function () {
    [$service] = projectService([summary('a', 90), summary('b', 80), summary('c', 70)]);

    $page = $service->list(new ProjectQuery());

    expect($page->items)->toHaveCount(3)
        ->and($page->total)->toBe(3)
        ->and($page->items[0]->slug)->toBe('a');
});

it('returns a project detail by slug', function () {
    [$service, $repo] = projectService();
    $repo->details['pgplan'] = new ProjectDetail(
        slug: 'pgplan', name: 'Pgplan', description: null, category: 'developer-tools',
        projectType: 'cli', technologies: [], repositoryUrl: null, websiteUrl: null,
        demoUrl: null, sourcePostUrl: 'https://x.com/a/status/1', authorHandle: 'acmedev',
        score: 88.0, scoreBreakdown: null, extractionConfidence: 0.9,
        discoveredAt: new DateTimeImmutable(), publishedAt: new DateTimeImmutable(),
    );

    expect($service->detail('pgplan')->name)->toBe('Pgplan');
});

// ================================================================ NOT FOUND

it('raises not-found for an unknown slug', function () {
    [$service] = projectService();

    expect(fn () => $service->detail('does-not-exist'))->toThrow(ProjectNotFound::class);
});

it('does not distinguish never-existed from hidden', function () {
    [$service] = projectService();

    try {
        $service->detail('some-project');
        $message = '';
    } catch (ProjectNotFound $e) {
        $message = $e->getMessage();
    }

    // Never existed, aged out of the window, and hidden by an admin are all
    // 404 to a consumer. Naming which would leak a moderation decision.
    expect($message)->toContain('No project found')
        ->and($message)->not->toContain('aged out')
        ->and($message)->not->toContain('not visible')
        ->and($message)->not->toContain('admin');
});

// =============================================================== PAGINATION

it('paginates', function () {
    $projects = [];

    for ($i = 1; $i <= 25; $i++) {
        $projects[] = summary("p{$i}", 100 - $i);
    }

    [$service] = projectService($projects);

    $first = $service->list(new ProjectQuery(page: 1, perPage: 10));
    $last = $service->list(new ProjectQuery(page: 3, perPage: 10));

    expect($first->items)->toHaveCount(10)
        ->and($first->lastPage())->toBe(3)
        ->and($first->hasMore())->toBeTrue()
        ->and($last->items)->toHaveCount(5)
        ->and($last->hasMore())->toBeFalse();
});

it('returns an empty page past the end rather than an error', function () {
    [$service] = projectService([summary('a')]);

    $page = $service->list(new ProjectQuery(page: 99));

    // 404 here would break clients that walk until empty, and the collection
    // plainly exists.
    expect($page->isEmpty())->toBeTrue()
        ->and($page->total)->toBe(1)
        ->and($page->isBeyondEnd())->toBeTrue();
});

it('reports one page for an empty collection', function () {
    [$service] = projectService();
    $page = $service->list(new ProjectQuery());

    expect($page->lastPage())->toBe(1)
        ->and($page->hasMore())->toBeFalse()
        ->and($page->isBeyondEnd())->toBeFalse();
});

it('computes offsets correctly', function () {
    expect((new ProjectQuery(page: 1, perPage: 20))->offset())->toBe(0)
        ->and((new ProjectQuery(page: 3, perPage: 20))->offset())->toBe(40);
});

// ================================================================ FILTERING

it('filters by category', function () {
    [$service] = projectService([
        summary('a', category: 'ai'),
        summary('b', category: 'cli'),
        summary('c', category: 'ai'),
    ]);

    expect($service->list(new ProjectQuery(categories: ['ai']))->total)->toBe(2);
});

it('filters by technology', function () {
    [$service] = projectService([
        summary('a', technologies: ['laravel', 'vue']),
        summary('b', technologies: ['rust']),
    ]);

    expect($service->list(new ProjectQuery(technologies: ['rust']))->items[0]->slug)->toBe('b');
});

it('filters by presence of a repository', function () {
    [$service] = projectService([
        summary('a', repositoryUrl: 'https://github.com/a/b'),
        summary('b', repositoryUrl: null),
    ]);

    expect($service->list(new ProjectQuery(hasRepository: true))->total)->toBe(1)
        ->and($service->list(new ProjectQuery(hasRepository: false))->items[0]->slug)->toBe('b');
});

it('filters by minimum score', function () {
    [$service] = projectService([summary('a', 90), summary('b', 40)]);

    expect($service->list(new ProjectQuery(minScore: 50))->total)->toBe(1);
});

it('combines filters', function () {
    [$service] = projectService([
        summary('a', 90, 'ai', ['rust']),
        summary('b', 90, 'ai', ['laravel']),
        summary('c', 20, 'ai', ['rust']),
    ]);

    $page = $service->list(new ProjectQuery(categories: ['ai'], technologies: ['rust'], minScore: 50));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->slug)->toBe('a');
});

it('returns an empty result when filters match nothing', function () {
    [$service] = projectService([summary('a', category: 'ai')]);

    // Not an error: the collection exists and is empty under this filter.
    expect($service->list(new ProjectQuery(categories: ['mobile-app']))->total)->toBe(0);
});

// ================================================================== SORTING

it('sorts by score by default', function () {
    [$service] = projectService([summary('low', 10), summary('high', 90), summary('mid', 50)]);

    $slugs = array_map(fn ($p) => $p->slug, $service->list(new ProjectQuery())->items);

    expect($slugs)->toBe(['high', 'mid', 'low']);
});

it('sorts by newest and oldest', function () {
    [$service] = projectService([
        summary('older', discovered: '2026-09-01 00:00:00'),
        summary('newer', discovered: '2026-09-08 00:00:00'),
    ]);

    expect($service->list(new ProjectQuery(sort: ProjectSort::Latest))->items[0]->slug)->toBe('newer')
        ->and($service->list(new ProjectQuery(sort: ProjectSort::Oldest))->items[0]->slug)->toBe('older');
});

it('offers trending as a sort rather than a separate resource', function () {
    // A /projects/trending route would be a second URL for the same set of
    // things, and consumers would have to learn which filters worked on which.
    expect(ProjectSort::values())->toContain('trending')
        ->and(ProjectSort::values())->toContain('latest');
});

// =================================================================== SEARCH

it('searches names and descriptions', function () {
    [$service] = projectService([
        summary('a', name: 'Pgplan', description: 'a postgres query plan viewer'),
        summary('b', name: 'Vuekit', description: 'a component library'),
    ]);

    expect($service->list(new ProjectQuery(search: 'postgres'))->items[0]->slug)->toBe('a');
});

it('combines search with filters', function () {
    [$service] = projectService([
        summary('a', 90, 'ai', name: 'Pgplan', description: 'postgres tool'),
        summary('b', 90, 'cli', name: 'Pgcli', description: 'postgres tool'),
    ]);

    $page = $service->list(new ProjectQuery(search: 'postgres', categories: ['cli']));

    expect($page->total)->toBe(1)
        ->and($page->items[0]->slug)->toBe('b');
});

it('returns nothing rather than everything for a term that matches nothing', function () {
    [$service] = projectService([summary('a', name: 'Pgplan')]);

    expect($service->list(new ProjectQuery(search: 'kubernetes'))->total)->toBe(0);
});

// ========================================================= UNEXPECTED ERRORS

it('lets an unexpected repository failure surface for the handler to map', function () {
    [$service, $repo] = projectService();
    $repo->explode = true;

    // The service does not swallow it: a database outage is a 500 and the
    // middleware turns it into one without leaking the message.
    expect(fn () => $service->list(new ProjectQuery()))->toThrow(RuntimeException::class);
});

// ================================================================= TAXONOMY

it('hides empty categories by default', function () {
    $repo = new InMemoryProjectQueryRepository();
    $repo->categoryCounts = [
        new TaxonomyCount('ai', 'AI', 5),
        new TaxonomyCount('mobile-app', 'Mobile App', 0),
    ];

    $service = new TaxonomyQueryService($repo);

    // A list offering choices that return nothing is worse than no list.
    expect($service->categories())->toHaveCount(1)
        ->and($service->categories(includeEmpty: true))->toHaveCount(2);
});

it('limits the technology list when asked', function () {
    $repo = new InMemoryProjectQueryRepository();
    $repo->technologyCounts = [
        new TaxonomyCount('laravel', 'Laravel', 9, 'framework'),
        new TaxonomyCount('vue', 'Vue', 7, 'framework'),
        new TaxonomyCount('rust', 'Rust', 3, 'language'),
    ];

    expect((new TaxonomyQueryService($repo))->technologies(2))->toHaveCount(2);
});

// =============================================================== STATISTICS

it('reports feed statistics for the window', function () {
    $repo = new InMemoryProjectQueryRepository();
    $repo->projects = [summary('a'), summary('b', repositoryUrl: null)];

    $stats = (new StatisticsService($repo, 7))->forWindow();

    expect($stats->projectsInWindow)->toBe(2)
        ->and($stats->projectsWithRepository)->toBe(1)
        ->and($stats->windowDays)->toBe(7);
});

// ============================================================== PAGINATED VO

it('handles an empty page without dividing by zero', function () {
    $page = new Paginated([], 0, 1, 20);

    expect($page->lastPage())->toBe(1)
        ->and($page->hasMore())->toBeFalse();
});
