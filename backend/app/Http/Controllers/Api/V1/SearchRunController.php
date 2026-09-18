<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use DevRadar\Domain\Port\SearchRunQueryRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Operational endpoints. Admin only.
 *
 * Separate from the public API because the data is different in kind: spend
 * per run, cost per query, pipeline health. The route group carries auth
 * middleware; the controller assumes it has already run.
 */
final class SearchRunController extends Controller
{
    public function __construct(private readonly SearchRunQueryRepositoryInterface $runs) {}

    /** GET /api/v1/admin/search-runs */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', 'in:running,completed,failed,aborted_budget'],
        ]);

        $page = $this->runs->runs(
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 20),
            $validated['status'] ?? null,
        );

        return ApiResponse::paginated($page->items, $page, $request->url(), $request->query());
    }

    /** GET /api/v1/admin/health */
    public function health(Request $request): JsonResponse
    {
        $hours = max(1, min(168, (int) $request->input('hours', 24)));

        return ApiResponse::item($this->runs->pipelineHealth($hours));
    }
}
