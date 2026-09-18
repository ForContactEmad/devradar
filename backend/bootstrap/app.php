<?php

declare(strict_types=1);

use App\Http\Middleware\ApiExceptionHandler;
use App\Http\Middleware\RequestContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
 * Application bootstrap.
 *
 * THIS FILE DID NOT EXIST, which meant more than "the app does not boot". Three
 * middleware and eight service providers were written, reviewed and tested and
 * then never wired to anything:
 *
 *   ApiExceptionHandler  - without it, unhandled exceptions return raw Laravel
 *                          stack traces carrying table names and query fragments
 *   RequestContext       - the request id never reached a log line, so a caller
 *                          quoting one in a bug report could not be traced
 *   RequireAdminToken    - referenced by class name in routes/api.php, which does
 *                          work without an alias, but only if the app boots
 *
 * The service providers are the container bindings for every port in the
 * system. Without them no controller can resolve its dependencies.
 */

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        // No health route here: DevRadar defines its own at /api/v1/health so
        // that liveness and readiness stay separate, which Laravel's single
        // built-in /up cannot express.
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * Order matters. RequestContext runs first so that a correlation id
         * exists before anything can fail, which means the exception handler's
         * own log line carries the same id the caller was given.
         */
        $middleware->api(prepend: [
            RequestContext::class,
            ApiExceptionHandler::class,
        ]);

        // The API is stateless and token-authenticated; there is no session to
        // forge a request against, so CSRF protection would be ceremony.
        $middleware->validateCsrfTokens(except: ['api/*']);

        /*
         * TRUST PROXIES MUST BE CONFIGURED PER DEPLOYMENT.
         *
         * The API rate-limits per IP. Behind a proxy without this, every
         * request keys to the proxy's address and the limit becomes global --
         * a denial of service rather than a protection against one.
         *
         * Left unset deliberately: trusting '*' blindly lets any caller spoof
         * X-Forwarded-For and bypass the limit entirely. Set TRUSTED_PROXIES to
         * your load balancer's address range.
         */
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: explode(',', (string) $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ApiExceptionHandler formats API responses. This stops the framework
        // reporting the same error a second time in its own format.
        $exceptions->dontReport([
            DevRadar\Domain\Query\ProjectNotFound::class,
        ]);
    })
    ->create();
