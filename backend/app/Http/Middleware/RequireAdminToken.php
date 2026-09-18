<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Authenticates operational endpoints with a shared token.
 *
 * REPLACES A PLACEHOLDER THAT WAS NOT A CONTROL. The admin routes previously
 * declared `auth:sanctum`, but Sanctum was never installed and no `sanctum`
 * guard was ever defined -- so the only thing standing between the internet
 * and DevRadar's cost figures was Laravel throwing "Auth guard [sanctum] is
 * not defined". That fails closed today, but by accident: installing Sanctum,
 * or publishing a default config/auth.php, turns the 500 into a 200.
 *
 * FAIL-CLOSED BY DESIGN. An unset or short token denies every request rather
 * than allowing them. A guard that switches off when its configuration is
 * missing is the failure mode this replaces, and repeating it here would be
 * worse than having no guard at all -- it would look protected.
 *
 * Constant-time comparison, because `===` on a secret leaks its length and
 * prefix to anyone willing to measure. These endpoints are low-traffic, so an
 * attacker has all the quiet they need to measure carefully.
 *
 * This is deliberately simple. Token auth is right for a handful of
 * operational endpoints used by one operator; swap in Sanctum or OIDC when
 * there are actual user accounts to authenticate.
 */
final class RequireAdminToken
{
    /** Short enough to brute force is the same as absent. */
    private const MINIMUM_TOKEN_LENGTH = 32;

    public function handle(Request $request, Closure $next)
    {
        $expected = (string) config('devradar.admin_token', '');

        if ($expected === '' || strlen($expected) < self::MINIMUM_TOKEN_LENGTH) {
            // Refusing is the only safe answer. Serving operational data
            // because nobody configured a token is exactly the accident this
            // middleware exists to prevent.
            Log::warning('admin.token_not_configured', ['path' => $request->path()]);

            return ApiResponse::error(
                'unauthenticated',
                'Administrative access is not configured on this deployment.',
                401,
            );
        }

        $presented = $this->presentedToken($request);

        if ($presented === null || ! hash_equals($expected, $presented)) {
            // Deliberately identical to the unconfigured response: telling a
            // caller whether the token was wrong or merely absent confirms
            // that the endpoint exists and is guarded by a token.
            Log::warning('admin.access_denied', [
                'path' => $request->path(),
                // The token itself is never logged, correct or not.
                'presented' => $presented === null ? 'absent' : 'invalid',
            ]);

            return ApiResponse::error('unauthenticated', 'Authentication is required for this endpoint.', 401);
        }

        return $next($request);
    }

    /**
     * Read the token from the Authorization header only.
     *
     * Never from a query parameter: query strings land in access logs, proxy
     * logs, browser history and Referer headers, which turns one careless
     * request into a permanently leaked credential.
     */
    private function presentedToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
