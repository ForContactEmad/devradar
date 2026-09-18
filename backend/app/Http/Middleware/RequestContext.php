<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use DevRadar\Application\Observability\LogContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gives every API request an identifier and a duration.
 *
 * TWO GAPS THIS CLOSES.
 *
 * The request id existed only in the response body: ApiResponse generated one
 * per response, so a caller could quote it in a bug report and nobody could
 * find it, because it had never reached a log line. Now the id is established
 * here, stamped on every log record for the request's lifetime, and returned
 * in both the body and a header.
 *
 * An INBOUND X-Request-Id is honoured. When a request arrives through a proxy
 * or from the dashboard's own fetch, the caller's id is the one worth keeping
 * -- generating a fresh one breaks the trace at the boundary.
 *
 * Duration is recorded for completed requests. "How long did processing take"
 * was answerable for pipeline stages and not for the read path.
 */
final class RequestContext
{
    public function handle(Request $request, Closure $next)
    {
        $requestId = $this->inboundId($request) ?? LogContext::newId('req');

        $context = app(LogContext::class);
        $context->begin($requestId);
        $context->set('method', $request->method());
        $context->set('path', $request->path());

        $startedAt = microtime(true);
        $response = $next($request);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // One line per request, at info. Not the request body: it can carry a
        // search term, and a query string is enough to reproduce a call.
        Log::info('api.request', [
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            // Slow reads matter here: the feed is cached, so anything slow is
            // a cache miss doing real work.
            'slow' => $durationMs > 1000,
        ]);

        $response->headers->set('X-Request-Id', $requestId);

        $context->clear();

        return $response;
    }

    /**
     * An inbound id, if it looks like one we generated or a sane opaque token.
     *
     * VALIDATED, not trusted. The value ends up in every log line for this
     * request, so an unbounded header would let a caller write arbitrary
     * content into the log -- newlines included, which in a line-oriented log
     * means forging entries.
     */
    private function inboundId(Request $request): ?string
    {
        $header = $request->header('X-Request-Id');

        if (! is_string($header)) {
            return null;
        }

        $header = trim($header);

        return preg_match('/^[A-Za-z0-9_.-]{8,64}$/', $header) === 1 ? $header : null;
    }
}
