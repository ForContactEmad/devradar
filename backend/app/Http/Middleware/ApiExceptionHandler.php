<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\ApiResponse;
use Closure;
use DevRadar\Domain\Query\ProjectNotFound;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps exceptions to the API's error envelope and the right status code.
 *
 * Centralised so no controller has to catch anything, and so a status code is
 * a mapping decision rather than something each action invents.
 *
 * WHAT LEAKS AND WHAT DOES NOT. Validation and not-found messages describe
 * the caller's own mistake and are safe to return. An unexpected exception is
 * ours: its message can carry table names, query fragments and occasionally
 * credentials, so the client gets a request id and the log gets the detail.
 */
final class ApiExceptionHandler
{
    public function handle(Request $request, Closure $next)
    {
        try {
            return $next($request);
        } catch (ValidationException $e) {
            return ApiResponse::error('validation_failed', 'The request parameters are invalid.', 422, $e->errors());
        } catch (InvalidArgumentException $e) {
            // Thrown by the domain's own query validation, which enforces
            // rules the HTTP layer cannot express -- relevance needing a
            // search term, the window being seven days.
            return ApiResponse::error('invalid_query', $e->getMessage(), 422);
        } catch (ProjectNotFound|NotFoundHttpException) {
            return ApiResponse::error('not_found', 'The requested resource does not exist.', 404);
        } catch (AuthenticationException) {
            return ApiResponse::error('unauthenticated', 'Authentication is required for this endpoint.', 401);
        } catch (TooManyRequestsHttpException) {
            return ApiResponse::error('rate_limited', 'Too many requests. Slow down.', 429);
        } catch (Throwable $e) {
            $requestId = $request->header('X-Request-Id') ?? (string) Str::uuid();

            Log::error('api.unhandled_exception', [
                'request_id' => $requestId,
                'path' => $request->path(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ApiResponse::error(
                'internal_error',
                'Something went wrong on our side. Quote the request id if you report this.',
                500,
            );
        }
    }
}
