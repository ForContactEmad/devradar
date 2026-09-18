<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use DevRadar\Application\Statistics\TrendsService;
use Illuminate\Http\JsonResponse;

/**
 * Statistics and trends.
 *
 * One request returns the whole report. The alternative -- an endpoint per
 * metric -- would make the dashboard as slow as its slowest query and spread
 * the aggregation across eight round trips.
 *
 * Thin, like every other controller here: it calls one service and formats
 * the result. No SQL, no arithmetic.
 */
final class TrendsController extends Controller
{
    public function __construct(private readonly TrendsService $trends) {}

    /** GET /api/v1/trends */
    public function show(): JsonResponse
    {
        return ApiResponse::item($this->trends->report()->toArray());
    }
}
