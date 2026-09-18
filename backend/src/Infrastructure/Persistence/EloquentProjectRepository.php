<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DateTimeImmutable;
use DevRadar\Domain\Extraction\DetectedTechnology;
use DevRadar\Domain\Extraction\ExtractedProject;
use DevRadar\Domain\Port\ExtractionCandidate;
use DevRadar\Domain\Port\ProjectRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persistence for published projects.
 *
 * Publishing is IDEMPOTENT on the source post. Re-running extraction after a
 * prompt change must update the project, not create a second one -- the
 * unique constraint on primary_tweet_id would reject the insert anyway, and
 * an exception is a worse outcome than an update.
 */
final readonly class EloquentProjectRepository implements ProjectRepositoryInterface
{
    /** @return list<ExtractionCandidate> */
    public function claimForExtraction(int $limit): array
    {
        $rows = DB::table('tweets')
            ->join('authors', 'tweets.author_id', '=', 'authors.id')
            ->leftJoin('projects', 'projects.primary_tweet_id', '=', 'tweets.id')
            ->select(
                'tweets.id', 'tweets.x_tweet_id', 'tweets.normalized_text', 'tweets.text',
                'tweets.canonical_url', 'tweets.primary_url', 'tweets.posted_at', 'tweets.lang',
                'authors.username',
            )
            ->where('tweets.status', 'classified')
            ->whereNull('tweets.purged_at')
            // Already published: extraction has run and succeeded.
            ->whereNull('projects.id')
            ->orderBy('tweets.posted_at')
            ->limit($limit)
            ->get();

        $candidates = [];

        foreach ($rows as $row) {
            $urls = array_values(array_unique(array_filter([$row->canonical_url, $row->primary_url])));

            $candidates[] = new ExtractionCandidate(
                tweetId: (int) $row->id,
                text: (string) ($row->normalized_text ?? $row->text),
                knownUrls: $urls,
                authorHandle: (string) $row->username,
                sourcePostUrl: sprintf('https://x.com/%s/status/%s', $row->username, $row->x_tweet_id),
                postedAt: new DateTimeImmutable((string) $row->posted_at),
                lang: $row->lang === null ? null : (string) $row->lang,
            );
        }

        return $candidates;
    }

    public function publish(ExtractedProject $project): int
    {
        return DB::transaction(function () use ($project) {
            $primaryUrl = $project->primaryUrl();

            $attributes = [
                'name' => $project->name,
                'description' => $project->description,
                'category' => $project->category,
                'project_type' => $project->projectType,
                'primary_url' => $primaryUrl,
                'canonical_url' => $project->repositoryUrl ?? $project->websiteUrl,
                'url_hash' => hash('sha256', $primaryUrl),
                'repository_url' => $project->repositoryUrl,
                'website_url' => $project->websiteUrl,
                'demo_url' => $project->demoUrl,
                'source_post_url' => $project->sourcePostUrl,
                'extraction_confidence' => round($project->confidence, 3),
                'discovered_at' => $project->publishedAt,
                'published_at' => now(),
                'updated_at' => now(),
            ];

            $existing = DB::table('projects')->where('primary_tweet_id', $project->tweetId)->first();

            if ($existing !== null) {
                // The slug is deliberately NOT regenerated: it may already be
                // linked to from outside, and a project whose URL changes on
                // every re-extraction is a broken link generator.
                DB::table('projects')->where('id', $existing->id)->update($attributes);
                $projectId = (int) $existing->id;
            } else {
                $projectId = (int) DB::table('projects')->insertGetId($attributes + [
                    'primary_tweet_id' => $project->tweetId,
                    'slug' => $this->uniqueSlug($project->name),
                    'created_at' => now(),
                ]);
            }

            $this->syncTechnologies($projectId, $project->technologies);

            return $projectId;
        });
    }

    public function recordExtractionFailure(int $tweetId, string $reason): void
    {
        DB::table('tweets')->where('id', $tweetId)->update([
            'status' => 'rejected',
            'reject_reason' => mb_substr('extraction:' . $reason, 0, 32),
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<DetectedTechnology> $technologies */
    private function syncTechnologies(int $projectId, array $technologies): void
    {
        // Replaced wholesale rather than merged: a re-extraction that no
        // longer finds evidence for a technology must remove it, or a
        // hallucination corrected in the prompt would survive forever.
        DB::table('project_technology')->where('project_id', $projectId)->delete();

        foreach ($technologies as $technology) {
            $technologyId = DB::table('technologies')->where('slug', $technology->slug)->value('id');

            if ($technologyId === null) {
                $technologyId = DB::table('technologies')->insertGetId([
                    'slug' => $technology->slug,
                    'name' => $technology->name,
                    'kind' => $technology->kind,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('project_technology')->insert([
                'project_id' => $projectId,
                'technology_id' => (int) $technologyId,
                'source' => $technology->source,
                'evidence' => mb_substr($technology->evidence, 0, 64),
            ]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'project';
        $base = mb_substr($base, 0, 140);
        $slug = $base;
        $suffix = 2;

        while (DB::table('projects')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }
}
