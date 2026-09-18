<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DateTimeImmutable;
use DevRadar\Domain\Port\ProjectQueryRepositoryInterface;
use DevRadar\Domain\Query\Paginated;
use DevRadar\Domain\Query\ProjectDetail;
use DevRadar\Domain\Query\ProjectQuery;
use DevRadar\Domain\Query\ProjectSort;
use DevRadar\Domain\Query\ProjectSummary;
use DevRadar\Domain\Query\RepositoryView;
use DevRadar\Domain\Query\Statistics;
use DevRadar\Domain\Query\TaxonomyCount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Every SQL statement the API issues.
 *
 * VISIBILITY IS DEFINED ONCE, in visible(). "Published, not hidden by an
 * admin, not aged out of the window" is a business rule, and repeating it
 * across six endpoints is how one of them eventually forgets a clause and
 * leaks a hidden project.
 *
 * The read path touches the serving tables only. It cannot reach a provider
 * or a model, which is what makes a page view structurally free.
 */
final readonly class EloquentProjectQueryRepository implements ProjectQueryRepositoryInterface
{
    /** @return Paginated<ProjectSummary> */
    public function search(ProjectQuery $query): Paginated
    {
        $base = $this->filtered($query);

        // Counted before ordering and limiting: the total is a property of
        // the filter, not of the page.
        $total = (clone $base)->count('projects.id');

        $rows = $this->applySort($base, $query)
            ->select(
                'projects.slug', 'projects.name', 'projects.description', 'projects.category',
                'projects.project_type', 'projects.repository_url', 'projects.website_url',
                'projects.demo_url', 'projects.source_post_url', 'projects.score',
                'projects.engagement_count', 'projects.discovered_at',
                'authors.username', 'repositories.stars',
            )
            ->offset($query->offset())
            ->limit($query->perPage)
            ->get();

        $technologies = $this->technologiesForSlugs($rows->pluck('slug')->all());

        $items = [];

        foreach ($rows as $row) {
            $items[] = new ProjectSummary(
                slug: (string) $row->slug,
                name: (string) $row->name,
                description: $row->description === null ? null : (string) $row->description,
                category: (string) $row->category,
                projectType: $row->project_type === null ? null : (string) $row->project_type,
                technologies: $technologies[(string) $row->slug] ?? [],
                repositoryUrl: $row->repository_url === null ? null : (string) $row->repository_url,
                websiteUrl: $row->website_url === null ? null : (string) $row->website_url,
                demoUrl: $row->demo_url === null ? null : (string) $row->demo_url,
                sourcePostUrl: (string) ($row->source_post_url ?? ''),
                authorHandle: $row->username === null ? null : (string) $row->username,
                score: (float) $row->score,
                discoveredAt: new DateTimeImmutable((string) $row->discovered_at),
                repositoryStars: $row->stars === null ? null : (int) $row->stars,
                engagementCount: (int) ($row->engagement_count ?? 0),
            );
        }

        return new Paginated($items, $total, $query->page, $query->perPage);
    }

    public function findBySlug(string $slug): ?ProjectDetail
    {
        $row = $this->visible()
            ->leftJoin('repositories', 'projects.repository_id', '=', 'repositories.id')
            ->where('projects.slug', $slug)
            ->select('projects.*', 'authors.username', 'repositories.url as repo_url',
                'repositories.stars', 'repositories.forks', 'repositories.open_issues',
                'repositories.contributors_count', 'repositories.primary_language',
                'repositories.license', 'repositories.topics', 'repositories.pushed_at',
                'repositories.repo_created_at', 'repositories.is_archived', 'repositories.fetched_at')
            ->first();

        if ($row === null) {
            return null;
        }

        $technologies = DB::table('project_technology')
            ->join('technologies', 'technologies.id', '=', 'project_technology.technology_id')
            ->where('project_technology.project_id', $row->id)
            ->select('technologies.slug', 'technologies.name', 'technologies.kind')
            ->orderBy('technologies.name')
            ->get()
            ->map(fn ($t) => ['slug' => (string) $t->slug, 'name' => (string) $t->name,
                'kind' => $t->kind === null ? null : (string) $t->kind])
            ->all();

        return new ProjectDetail(
            slug: (string) $row->slug,
            name: (string) $row->name,
            description: $row->description === null ? null : (string) $row->description,
            category: (string) $row->category,
            projectType: $row->project_type === null ? null : (string) $row->project_type,
            technologies: $technologies,
            repositoryUrl: $row->repository_url === null ? null : (string) $row->repository_url,
            websiteUrl: $row->website_url === null ? null : (string) $row->website_url,
            demoUrl: $row->demo_url === null ? null : (string) $row->demo_url,
            sourcePostUrl: (string) ($row->source_post_url ?? ''),
            authorHandle: $row->username === null ? null : (string) $row->username,
            score: (float) $row->score,
            scoreBreakdown: $row->score_breakdown === null ? null : json_decode((string) $row->score_breakdown, true),
            extractionConfidence: $row->extraction_confidence === null ? null : (float) $row->extraction_confidence,
            discoveredAt: new DateTimeImmutable((string) $row->discovered_at),
            publishedAt: new DateTimeImmutable((string) $row->published_at),
            repository: $row->repo_url === null ? null : new RepositoryView(
                url: (string) $row->repo_url,
                stars: $row->stars === null ? null : (int) $row->stars,
                forks: $row->forks === null ? null : (int) $row->forks,
                openIssues: $row->open_issues === null ? null : (int) $row->open_issues,
                contributors: $row->contributors_count === null ? null : (int) $row->contributors_count,
                primaryLanguage: $row->primary_language === null ? null : (string) $row->primary_language,
                license: $row->license === null ? null : (string) $row->license,
                topics: $row->topics === null ? [] : (array) json_decode((string) $row->topics, true),
                lastCommitAt: $row->pushed_at === null ? null : (string) $row->pushed_at,
                createdAt: $row->repo_created_at === null ? null : (string) $row->repo_created_at,
                isArchived: (bool) $row->is_archived,
                fetchedAt: $row->fetched_at === null ? null : (string) $row->fetched_at,
            ),
            history: $this->history((int) $row->id),
        );
    }

    /** @return list<TaxonomyCount> */
    public function categories(): array
    {
        $rows = $this->visible()
            ->select('projects.category', DB::raw('count(*) as project_count'))
            ->groupBy('projects.category')
            ->orderByDesc('project_count')
            ->get();

        return $rows->map(fn ($r) => new TaxonomyCount(
            slug: (string) $r->category,
            name: $this->humanise((string) $r->category),
            projectCount: (int) $r->project_count,
        ))->all();
    }

    /** @return list<TaxonomyCount> */
    public function technologies(?int $limit = null): array
    {
        $query = $this->visible()
            ->join('project_technology', 'project_technology.project_id', '=', 'projects.id')
            ->join('technologies', 'technologies.id', '=', 'project_technology.technology_id')
            ->select('technologies.slug', 'technologies.name', 'technologies.kind',
                DB::raw('count(distinct projects.id) as project_count'))
            ->groupBy('technologies.slug', 'technologies.name', 'technologies.kind')
            ->orderByDesc('project_count')
            ->orderBy('technologies.name');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map(fn ($r) => new TaxonomyCount(
            slug: (string) $r->slug,
            name: (string) $r->name,
            projectCount: (int) $r->project_count,
            kind: $r->kind === null ? null : (string) $r->kind,
        ))->all();
    }

    public function statistics(int $windowDays): Statistics
    {
        $aggregate = $this->visible()
            ->selectRaw('
                count(*) as total,
                count(*) filter (where projects.published_at >= current_date) as today,
                count(*) filter (where projects.repository_url is not null) as with_repository,
                coalesce(avg(projects.score), 0) as average_score,
                max(projects.published_at) as last_published
            ')
            ->first();

        return new Statistics(
            projectsInWindow: (int) $aggregate->total,
            projectsPublishedToday: (int) $aggregate->today,
            projectsWithRepository: (int) $aggregate->with_repository,
            averageScore: round((float) $aggregate->average_score, 2),
            topCategories: array_slice($this->categories(), 0, 5),
            topTechnologies: $this->technologies(10),
            lastPublishedAt: $aggregate->last_published === null ? null : (string) $aggregate->last_published,
            windowDays: $windowDays,
        );
    }

    /**
     * What "a project the public may see" means. Defined once.
     */
    private function visible(): Builder
    {
        return DB::table('projects')
            ->join('tweets', 'projects.primary_tweet_id', '=', 'tweets.id')
            ->join('authors', 'tweets.author_id', '=', 'authors.id')
            ->where('projects.is_visible', true)
            ->whereNull('projects.aged_out_at')
            // A purged source post must take its project out of the feed:
            // the compliance obligation is to stop displaying the content.
            ->whereNull('tweets.purged_at');
    }

    private function filtered(ProjectQuery $query): Builder
    {
        $builder = $this->visible()->leftJoin('repositories', 'projects.repository_id', '=', 'repositories.id');

        if ($query->categories !== []) {
            $builder->whereIn('projects.category', $query->categories);
        }

        if ($query->projectType !== null) {
            $builder->where('projects.project_type', $query->projectType);
        }

        if ($query->hasRepository !== null) {
            $query->hasRepository
                ? $builder->whereNotNull('projects.repository_url')
                : $builder->whereNull('projects.repository_url');
        }

        if ($query->minScore !== null) {
            $builder->where('projects.score', '>=', $query->minScore);
        }

        if ($query->withinDays !== null) {
            $builder->where('projects.discovered_at', '>=', now()->subDays($query->withinDays));
        }

        if ($query->since !== null) {
            $builder->where('projects.discovered_at', '>=', $query->since);
        }

        if ($query->until !== null) {
            $builder->where('projects.discovered_at', '<=', $query->until);
        }

        if ($query->minEngagement !== null) {
            // The denormalised column, not a sum over the joined post. The
            // sum could not use an index and forced a full join before the
            // LIMIT applied.
            $builder->where('projects.engagement_count', '>=', $query->minEngagement);
        }

        if ($query->technologies !== []) {
            // EXISTS rather than a join: joining the pivot would multiply
            // rows and make the count wrong, and DISTINCT to fix that would
            // defeat the ordering indexes.
            $builder->whereExists(function ($sub) use ($query) {
                $sub->from('project_technology')
                    ->join('technologies', 'technologies.id', '=', 'project_technology.technology_id')
                    ->whereColumn('project_technology.project_id', 'projects.id')
                    ->whereIn('technologies.slug', $query->technologies);
            });
        }

        $search = $query->normalisedSearch();

        if ($search !== null) {
            $builder->whereRaw(
                "to_tsvector('english', coalesce(projects.name,'') || ' ' || coalesce(projects.description,''))
                 @@ plainto_tsquery('english', ?)",
                [$search],
            );
        }

        return $builder;
    }

    private function applySort(Builder $builder, ProjectQuery $query): Builder
    {
        return match ($query->sort) {
            // Ties broken by id so pagination is deterministic. Without it,
            // two projects with equal scores can swap between pages and a
            // client sees one twice and misses the other.
            ProjectSort::Score => $builder->orderByDesc('projects.score')->orderByDesc('projects.id'),
            ProjectSort::Latest => $builder->orderByDesc('projects.discovered_at')->orderByDesc('projects.id'),
            ProjectSort::Oldest => $builder->orderBy('projects.discovered_at')->orderBy('projects.id'),
            // Reads the denormalised column so the ordering is indexed.
            // Summing the post's counters here meant a hash join and a sort
            // of every row before the LIMIT could apply.
            ProjectSort::Engagement => $builder
                ->orderByDesc('projects.engagement_count')
                ->orderByDesc('projects.id'),
            // Growth-weighted: what is accelerating, which is a different
            // question from what is currently biggest. Falls back to score
            // for projects with no growth component recorded yet.
            ProjectSort::Trending => $builder
                ->orderByRaw("coalesce((projects.score_breakdown #>> '{components,growth,contribution}')::numeric, 0) desc")
                ->orderByDesc('projects.score')
                ->orderByDesc('projects.id'),
            ProjectSort::Relevance => $builder->orderByRaw(
                "ts_rank(to_tsvector('english', coalesce(projects.name,'') || ' ' || coalesce(projects.description,'')),
                         plainto_tsquery('english', ?)) desc",
                [$query->normalisedSearch()],
            )->orderByDesc('projects.id'),
        };
    }

    /**
     * @param  list<string> $slugs
     * @return array<string, list<string>>
     */
    private function technologiesForSlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        // One query for the whole page rather than one per project.
        $rows = DB::table('projects')
            ->join('project_technology', 'project_technology.project_id', '=', 'projects.id')
            ->join('technologies', 'technologies.id', '=', 'project_technology.technology_id')
            ->whereIn('projects.slug', $slugs)
            ->select('projects.slug', 'technologies.slug as tech_slug')
            ->orderBy('technologies.name')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row->slug][] = (string) $row->tech_slug;
        }

        return $map;
    }

    /** @return list<array{captured_at: string, score: ?float, engagement: int}> */
    private function history(int $projectId): array
    {
        return DB::table('project_metrics')
            ->where('project_id', $projectId)
            ->orderBy('captured_at')
            ->limit(50)
            ->get()
            ->map(fn ($m) => [
                'captured_at' => (string) $m->captured_at,
                'score' => $m->score === null ? null : (float) $m->score,
                'engagement' => (int) $m->like_count + (int) $m->repost_count
                    + (int) $m->reply_count + (int) $m->quote_count,
            ])->all();
    }

    private function humanise(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }
}
