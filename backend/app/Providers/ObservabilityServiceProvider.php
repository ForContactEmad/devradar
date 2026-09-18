<?php

declare(strict_types=1);

namespace App\Providers;

use DevRadar\Application\Observability\LogContext;
use DevRadar\Infrastructure\Logging\ContextProcessor;
use DevRadar\Infrastructure\Logging\RedactingProcessor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the observability layer.
 *
 * The processors are registered as container singletons because Monolog
 * resolves them by class name from config/logging.php, and ContextProcessor
 * needs the same LogContext instance the request and the jobs write to.
 */
final class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ONE instance per process. Two would mean the correlation id set by
        // a job never reaches the processor that stamps log records.
        $this->app->singleton(LogContext::class);

        $this->app->singleton(RedactingProcessor::class);
        $this->app->singleton(ContextProcessor::class, fn ($app) => new ContextProcessor(
            $app->make(LogContext::class),
        ));
    }

    public function boot(): void
    {
        $this->logSlowQueries();
    }

    /**
     * Log only queries slower than the configured threshold.
     *
     * NOT every query. The pipeline issues thousands an hour, and logging
     * them all would bury the one line that matters in noise nobody reads --
     * while roughly doubling the log volume the container has to ship.
     *
     * BINDINGS ARE NOT LOGGED, only their count. A binding can be a post's
     * full text or a search term; what a slow-query log needs is the shape of
     * the query, and the shape is the SQL.
     */
    private function logSlowQueries(): void
    {
        $threshold = (float) config('logging.slow_query_ms', 500);

        DB::listen(function (QueryExecuted $query) use ($threshold) {
            if ($query->time < $threshold) {
                return;
            }

            Log::warning('db.slow_query', [
                'duration_ms' => round($query->time, 2),
                'connection' => $query->connectionName,
                // Collapsed whitespace so a multi-line query is one log line.
                'sql' => preg_replace('/\s+/', ' ', trim($query->sql)),
                'bindings' => count($query->bindings),
            ]);
        });
    }
}
