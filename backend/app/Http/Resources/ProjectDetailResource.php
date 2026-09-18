<?php

declare(strict_types=1);

namespace App\Http\Resources;

use DevRadar\Domain\Query\ProjectDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property ProjectDetail $resource */
final class ProjectDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $repo = $p->repository;

        return [
            'slug' => $p->slug,
            'name' => $p->name,
            'description' => $p->description,
            'category' => $p->category,
            'project_type' => $p->projectType,
            'technologies' => $p->technologies,
            'links' => [
                'repository' => $p->repositoryUrl,
                'website' => $p->websiteUrl,
                'demo' => $p->demoUrl,
                'source_post' => $p->sourcePostUrl,
            ],
            'author' => $p->authorHandle,
            'score' => round($p->score, 2),
            // Exposed because ranking is what people disagree with, and "why
            // is this above that" should be answerable from the API.
            'score_breakdown' => $p->scoreBreakdown,
            'extraction_confidence' => $p->extractionConfidence,
            'discovered_at' => $p->discoveredAt->format(DATE_ATOM),
            'published_at' => $p->publishedAt->format(DATE_ATOM),
            'repository' => $repo === null ? null : [
                'url' => $repo->url,
                'stars' => $repo->stars,
                'forks' => $repo->forks,
                'open_issues' => $repo->openIssues,
                'contributors' => $repo->contributors,
                'primary_language' => $repo->primaryLanguage,
                'license' => $repo->license,
                'topics' => $repo->topics,
                'last_commit_at' => $repo->lastCommitAt,
                'created_at' => $repo->createdAt,
                'is_archived' => $repo->isArchived,
                // So a consumer can tell stale data from missing data.
                'fetched_at' => $repo->fetchedAt,
            ],
            'history' => $p->history,
        ];
    }
}
