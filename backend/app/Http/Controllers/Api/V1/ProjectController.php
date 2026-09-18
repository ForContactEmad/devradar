<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectIndexRequest;
use App\Http\Resources\ProjectDetailResource;
use App\Http\Resources\ProjectSummaryResource;
use DevRadar\Application\Query\ProjectQueryService;
use DevRadar\Domain\Query\ProjectNotFound;
use Illuminate\Http\JsonResponse;

/**
 * Projects.
 *
 * Thin by construction: each action validates via a form request, calls one
 * application service, and formats the result. There is no SQL here, no
 * business rule, and nothing that reaches a provider or a model.
 *
 * "Trending" and "latest" are NOT separate endpoints. They are orderings of
 * one collection, so they are sort values on this one. A /projects/trending
 * route would be a second URL for the same set of things, and consumers would
 * then have to learn which filters worked on which.
 */
final class ProjectController extends Controller
{
    public function __construct(private readonly ProjectQueryService $projects) {}

    /** GET /api/v1/projects */
    public function index(ProjectIndexRequest $request): JsonResponse
    {
        $query = $request->toQuery();
        $page = $this->projects->list($query);

        return ApiResponse::paginated(
            ProjectSummaryResource::collection($page->items),
            $page,
            $request->url(),
            $request->query(),
        );
    }

    /** GET /api/v1/projects/{slug} */
    public function show(string $slug): JsonResponse
    {
        try {
            $project = $this->projects->detail($slug);
        } catch (ProjectNotFound $e) {
            return ApiResponse::error('not_found', $e->getMessage(), 404);
        }

        return ApiResponse::item(new ProjectDetailResource($project));
    }
}
