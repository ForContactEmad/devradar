<?php

declare(strict_types=1);

namespace App\Http\Resources;

use DevRadar\Domain\Query\ProjectSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire format for a project in a list.
 *
 * Explicit rather than a model dump, so the API's shape is a decision rather
 * than a side effect of the schema. A column added to `projects` does not
 * silently appear in the public contract.
 *
 * @property ProjectSummary $resource
 */
final class ProjectSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

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
            'stars' => $p->repositoryStars,
            'engagement' => $p->engagementCount,
            'discovered_at' => $p->discoveredAt->format(DATE_ATOM),
        ];
    }
}
