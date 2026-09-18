<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use DevRadar\Application\Query\TaxonomyQueryService;
use DevRadar\Domain\Query\TaxonomyCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Categories and technologies -- the facets a client builds filters from.
 *
 * Both carry project counts, scoped to visible projects in the window. A
 * category list without counts offers choices that return nothing.
 */
final class TaxonomyController extends Controller
{
    public function __construct(private readonly TaxonomyQueryService $taxonomy) {}

    /** GET /api/v1/categories */
    public function categories(Request $request): JsonResponse
    {
        $categories = $this->taxonomy->categories(
            includeEmpty: $request->boolean('include_empty'),
        );

        return ApiResponse::collection(array_map($this->present(...), $categories));
    }

    /** GET /api/v1/technologies */
    public function technologies(Request $request): JsonResponse
    {
        $limit = $request->has('limit') ? max(1, min(200, (int) $request->input('limit'))) : null;

        return ApiResponse::collection(array_map($this->present(...), $this->taxonomy->technologies($limit)));
    }

    /** @return array<string, mixed> */
    private function present(TaxonomyCount $count): array
    {
        return array_filter([
            'slug' => $count->slug,
            'name' => $count->name,
            'kind' => $count->kind,
            'project_count' => $count->projectCount,
        ], fn ($v) => $v !== null);
    }
}
