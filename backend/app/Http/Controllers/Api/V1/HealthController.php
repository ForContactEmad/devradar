<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use DevRadar\Application\Health\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * Container health probes.
 *
 * UNAUTHENTICATED AND UNTHROTTLED, deliberately. A probe that needs a token
 * is a probe the orchestrator cannot use, and one that can be rate-limited
 * will eventually be rate-limited into reporting a false outage.
 *
 * It is therefore also a reconnaissance surface, so the response carries the
 * minimum that is useful: a status and which component is down. No version,
 * no hostname, no error text.
 *
 * 503 on not-ready, not 200 with a status field. Orchestrators read the
 * status code; a 200 saying "not_ready" keeps traffic coming.
 */
final class HealthController extends Controller
{
    public function __construct(private readonly HealthCheck $health) {}

    /**
     * GET /api/v1/health — liveness.
     *
     * Touches nothing external. A database outage must not make every
     * container look dead and trigger a restart storm that guarantees the
     * outage continues.
     */
    public function live(): JsonResponse
    {
        return response()->json($this->health->liveness());
    }

    /** GET /api/v1/health/ready — readiness. */
    public function ready(): JsonResponse
    {
        $result = $this->health->readiness();

        return response()->json($result, $result['status'] === 'ready' ? 200 : 503);
    }
}
