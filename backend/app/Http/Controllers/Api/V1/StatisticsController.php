<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use DevRadar\Application\Query\StatisticsService;
use DevRadar\Domain\Query\TaxonomyCount;
use Illuminate\Http\JsonResponse;

/**
 * Feed statistics.
 *
 * PUBLIC NUMBERS ONLY. Spend, budget position and per-query yield are
 * operational figures behind the admin boundary: they tell a competitor what
 * the product costs to run.
 */
final class StatisticsController extends Controller
{
    public function __construct(private readonly StatisticsService $statistics) {}

    /** GET /api/v1/stats */
    public function show(): JsonResponse
    {
        $stats = $this->statistics->forWindow();

        $facet = fn (TaxonomyCount $c) => ['slug' => $c->slug, 'name' => $c->name, 'project_count' => $c->projectCount];

        return ApiResponse::item([
            'window_days' => $stats->windowDays,
            'projects_in_window' => $stats->projectsInWindow,
            'projects_published_today' => $stats->projectsPublishedToday,
            'projects_with_repository' => $stats->projectsWithRepository,
            'average_score' => $stats->averageScore,
            'last_published_at' => $stats->lastPublishedAt,
            'top_categories' => array_map($facet, $stats->topCategories),
            'top_technologies' => array_map($facet, $stats->topTechnologies),
        ]);
    }
}
