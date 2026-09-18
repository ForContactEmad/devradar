<?php

declare(strict_types=1);

namespace App\Providers;

use DevRadar\Application\Collection\RecentWindowStrategy;
use DevRadar\Application\Collection\TweetCollector;
use DevRadar\Domain\Budget\CycleBudgetGuard;
use DevRadar\Domain\Collection\WindowResolver;
use DevRadar\Domain\Port\BudgetGuardInterface;
use DevRadar\Domain\Port\ClockInterface;
use DevRadar\Infrastructure\Clock\SystemClock;
use DevRadar\Domain\Port\PostProviderInterface;
use DevRadar\Domain\Port\SearchQuerySourceInterface;
use DevRadar\Domain\Port\SearchRunLedgerInterface;
use DevRadar\Domain\Port\SearchStrategyInterface;
use DevRadar\Domain\Port\TweetRepositoryInterface;
use DevRadar\Infrastructure\Persistence\DatabaseSearchQuerySource;
use DevRadar\Infrastructure\Persistence\EloquentSearchRunLedger;
use DevRadar\Infrastructure\Persistence\EloquentTweetRepository;
use DevRadar\Infrastructure\Http\HttpClientInterface;
use DevRadar\Infrastructure\Http\LaravelHttpClient;
use DevRadar\Infrastructure\X\RetryPolicy;
use DevRadar\Infrastructure\X\XApiConfig;
use DevRadar\Infrastructure\X\XErrorClassifier;
use DevRadar\Infrastructure\X\XPostMapper;
use DevRadar\Infrastructure\X\XPostProvider;
use DevRadar\Infrastructure\X\XRequestBuilder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

/**
 * The composition root for post ingestion.
 *
 * This is the ONLY place where the application learns that X exists. Binding
 * PostProviderInterface to a different implementation here is the whole
 * migration path away from X -- no other file changes.
 *
 * Configuration is read here and injected. Business logic never reads config
 * directly.
 */
final class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * The clock had no production implementation at all. It was declared,
         * injected and resolved from the container, and the only class that
         * implemented it lived in tests/Fake — so every test passed while the
         * collection stage could not be constructed outside a test.
         */
        $this->app->bind(ClockInterface::class, SystemClock::class);

        $this->app->bind(HttpClientInterface::class, fn (Application $app) => new LaravelHttpClient(
            $app->make(Factory::class),
        ));

        $this->app->singleton(XApiConfig::class, fn () => new XApiConfig(
            bearerToken: (string) config('x.bearer_token'),
            baseUrl: (string) config('x.base_url'),
            maxQueryLength: (int) config('x.max_query_length'),
            timeoutSeconds: (float) config('x.timeout_seconds'),
            maxRetries: (int) config('x.retry.max_attempts'),
            baseBackoffSeconds: (float) config('x.retry.base_backoff_seconds'),
            maxBackoffSeconds: (float) config('x.retry.max_backoff_seconds'),
            expandAuthors: (bool) config('x.expand_authors'),
        ));

        $this->app->bind(PostProviderInterface::class, function (Application $app) {
            $config = $app->make(XApiConfig::class);

            return new XPostProvider(
                http: $app->make(HttpClientInterface::class),
                config: $config,
                requestBuilder: new XRequestBuilder($config),
                mapper: new XPostMapper(),
                classifier: new XErrorClassifier(),
                retryPolicy: new RetryPolicy(
                    maxAttempts: $config->maxRetries,
                    baseSeconds: $config->baseBackoffSeconds,
                    maxSeconds: $config->maxBackoffSeconds,
                ),
                // Bound in a later phase; the port exists now so the client
                // cannot be written without one.
                budget: $app->make(BudgetGuardInterface::class),
                logger: $app->make(\Psr\Log\LoggerInterface::class),
            );
        });

        $this->app->bind(TweetRepositoryInterface::class, EloquentTweetRepository::class);
        $this->app->bind(SearchRunLedgerInterface::class, EloquentSearchRunLedger::class);
        $this->app->bind(SearchQuerySourceInterface::class, DatabaseSearchQuerySource::class);

        $this->app->bind(SearchStrategyInterface::class, fn (Application $app) => new RecentWindowStrategy(
            queries: $app->make(SearchQuerySourceInterface::class),
            windows: new WindowResolver(
                clock: $app->make(\DevRadar\Domain\Port\ClockInterface::class),
                windowDays: (int) config('collection.window.days'),
                startMarginSeconds: (int) config('collection.window.start_margin_seconds'),
                endMarginSeconds: (int) config('collection.window.end_margin_seconds'),
            ),
            repository: $app->make(TweetRepositoryInterface::class),
            pageSize: (int) config('collection.limits.page_size'),
            maxPostsPerQuery: (int) config('collection.limits.max_posts_per_query'),
            maxPagesPerQuery: (int) config('collection.limits.max_pages_per_query'),
        ));

        $this->app->bind(TweetCollector::class, fn (Application $app) => new TweetCollector(
            provider: $app->make(PostProviderInterface::class),
            repository: $app->make(TweetRepositoryInterface::class),
            ledger: $app->make(SearchRunLedgerInterface::class),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
            postReadPriceUsd: (float) config('devradar.pricing.post_read_usd'),
        ));

        // Spend already incurred this cycle comes from the ledger, so the
        // guard reflects reality rather than an in-process counter that
        // resets whenever a worker restarts.
        $this->app->bind(BudgetGuardInterface::class, fn () => new CycleBudgetGuard(
            alreadySpentUsd: (float) \Illuminate\Support\Facades\DB::table('search_runs')
                ->where('started_at', '>=', now()->startOfMonth())
                ->sum('cost_usd'),
            cycleCeilingUsd: (float) config('devradar.budget.monthly_ceiling_usd'),
            resourcePriceUsd: (float) config('devradar.pricing.post_read_usd'),
            maxResourcesPerRun: (int) config('devradar.budget.max_posts_per_run') * 2,
        ));
    }
}
