<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DateTimeImmutable;
use DevRadar\Domain\Enrichment\EnrichmentTarget;
use DevRadar\Domain\Enrichment\RepositoryFacts;
use DevRadar\Domain\Enrichment\RepositoryRef;
use DevRadar\Domain\Port\EnrichmentRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Persistence for repository enrichment.
 *
 * THE REFRESH STRATEGY LIVES IN claimForEnrichment. Repositories are not
 * refreshed on a fixed timer for everyone: never-fetched first, then stalest,
 * and only for projects still inside the rolling window. A project that has
 * aged out of the feed is not worth a request, and spending quota on it takes
 * a slot from one that is currently on the front page.
 */
final readonly class EloquentEnrichmentRepository implements EnrichmentRepositoryInterface
{
    /** @return list<EnrichmentTarget> */
    public function claimForEnrichment(int $limit, int $refreshAfterMinutes, int $maxFailures): array
    {
        $threshold = now()->subMinutes($refreshAfterMinutes);

        $rows = DB::table('repositories')
            ->join('projects', 'projects.repository_id', '=', 'repositories.id')
            ->select('repositories.id', 'repositories.host', 'repositories.owner', 'repositories.name',
                'repositories.etag', 'repositories.fetched_at', 'repositories.fetch_failed_count')
            ->whereNull('repositories.gone_at')
            ->where('repositories.fetch_failed_count', '<', $maxFailures)
            // Only repositories attached to a project still in the feed.
            ->whereNull('projects.aged_out_at')
            ->where('projects.is_visible', true)
            ->where(function ($query) use ($threshold) {
                $query->whereNull('repositories.fetched_at')
                    ->orWhere('repositories.fetched_at', '<', $threshold);
            })
            ->distinct()
            ->orderByRaw('repositories.fetched_at NULLS FIRST')
            ->limit($limit)
            ->get();

        $targets = [];

        foreach ($rows as $row) {
            $targets[] = new EnrichmentTarget(
                repositoryId: (int) $row->id,
                ref: RepositoryRef::of((string) $row->owner, (string) $row->name, (string) $row->host),
                etag: $row->etag === null ? null : (string) $row->etag,
                lastFetchedAt: $row->fetched_at === null ? null : new DateTimeImmutable((string) $row->fetched_at),
                failureCount: (int) $row->fetch_failed_count,
            );
        }

        return $targets;
    }

    public function linkPendingProjects(int $limit): int
    {
        $projects = DB::table('projects')
            ->select('id', 'repository_url')
            ->whereNull('repository_id')
            ->whereNotNull('repository_url')
            ->whereNull('aged_out_at')
            ->limit($limit)
            ->get();

        $linked = 0;

        foreach ($projects as $project) {
            // Parsing decides whether GitHub is called at all. A project
            // linking to a product page never enters the queue.
            $ref = RepositoryRef::fromUrl((string) $project->repository_url);

            if ($ref === null) {
                continue;
            }

            $repositoryId = DB::table('repositories')
                ->where('host', $ref->host)
                ->whereRaw('lower(owner) = ?', [strtolower($ref->owner)])
                ->whereRaw('lower(name) = ?', [strtolower($ref->name)])
                ->value('id');

            if ($repositoryId === null) {
                $repositoryId = DB::table('repositories')->insertGetId([
                    'host' => $ref->host,
                    'owner' => $ref->owner,
                    'name' => $ref->name,
                    'url' => $ref->url(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('projects')->where('id', $project->id)->update([
                'repository_id' => (int) $repositoryId,
                'updated_at' => now(),
            ]);

            $linked++;
        }

        return $linked;
    }

    public function storeFacts(int $repositoryId, RepositoryFacts $facts): void
    {
        DB::table('repositories')->where('id', $repositoryId)->update([
            // GitHub reports the current owner/name, which differs from what
            // we asked for when a repository has been renamed.
            'owner' => $facts->ref->owner,
            'name' => $facts->ref->name,
            'url' => $facts->ref->url(),
            'stars' => $facts->stars,
            'forks' => $facts->forks,
            'open_issues' => $facts->openIssues,
            'contributors_count' => $facts->contributors,
            'primary_language' => $facts->primaryLanguage,
            'license' => $facts->license,
            'topics' => json_encode($facts->topics),
            'description' => $facts->description,
            'default_branch' => $facts->defaultBranch,
            'is_archived' => $facts->isArchived,
            'is_fork' => $facts->isFork,
            'pushed_at' => $facts->pushedAt,
            'repo_created_at' => $facts->createdAt,
            'etag' => $facts->etag,
            'fetched_at' => now(),
            // A success clears the failure budget: an intermittently
            // unreachable repository should not accumulate towards a
            // blacklist across weeks.
            'fetch_failed_count' => 0,
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    public function touchUnchanged(int $repositoryId): void
    {
        // Only freshness moves. Rewriting identical values would churn the
        // row and lose the information that nothing actually changed.
        DB::table('repositories')->where('id', $repositoryId)->update([
            'fetched_at' => now(),
            'fetch_failed_count' => 0,
            'updated_at' => now(),
        ]);
    }

    public function markGone(int $repositoryId, string $reason): void
    {
        DB::table('repositories')->where('id', $repositoryId)->update([
            'gone_at' => now(),
            'gone_reason' => mb_substr($reason, 0, 255),
            'fetched_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function recordFailure(int $repositoryId, string $reason): void
    {
        DB::table('repositories')->where('id', $repositoryId)->update([
            'fetch_failed_count' => DB::raw('fetch_failed_count + 1'),
            'last_error' => mb_substr($reason, 0, 1000),
            // fetched_at is deliberately NOT touched: a failed fetch did not
            // refresh anything, and marking it fresh would hide a repository
            // that has silently stopped updating.
            'updated_at' => now(),
        ]);
    }
}
