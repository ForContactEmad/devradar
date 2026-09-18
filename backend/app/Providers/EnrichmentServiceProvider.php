<?php

declare(strict_types=1);

namespace App\Providers;

use DevRadar\Application\Enrichment\EnrichmentRunner;
use DevRadar\Application\Enrichment\RepositoryAnalyzer;
use DevRadar\Domain\Port\EnrichmentRepositoryInterface;
use DevRadar\Domain\Port\RepositoryProviderInterface;
use DevRadar\Domain\Support\BackoffPolicy;
use DevRadar\Infrastructure\GitHub\GitHubClient;
use DevRadar\Infrastructure\GitHub\RetryingRepositoryProvider;
use DevRadar\Infrastructure\Http\HttpClientInterface;
use DevRadar\Infrastructure\Persistence\EloquentEnrichmentRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for GitHub enrichment.
 *
 * This is the only file in DevRadar that knows GitHub exists. Nothing above
 * RepositoryProviderInterface names it, and the dashboard never reaches this
 * far -- it reads stored rows.
 */
final class EnrichmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EnrichmentRepositoryInterface::class, EloquentEnrichmentRepository::class);

        $this->app->bind(RepositoryProviderInterface::class, function (Application $app) {
            $token = (string) config('github.token', '');

            $client = new GitHubClient(
                http: $app->make(HttpClientInterface::class),
                logger: $app->make(\Psr\Log\LoggerInterface::class),
                token: $token,
                baseUrl: (string) config('github.base_url'),
                timeoutSeconds: (float) config('github.timeout_seconds'),
                // The second request per repository is affordable at 5,000
                // an hour and doubles the cost of every lookup at 60.
                fetchContributors: (bool) config('github.fetch_contributors') && $token !== '',
            );

            return new RetryingRepositoryProvider(
                inner: $client,
                backoff: new BackoffPolicy(
                    (float) config('github.retry.base_backoff_seconds'),
                    (float) config('github.retry.max_backoff_seconds'),
                ),
                logger: $app->make(\Psr\Log\LoggerInterface::class),
                maxAttempts: (int) config('github.retry.max_attempts'),
            );
        });

        $this->app->bind(RepositoryAnalyzer::class, fn (Application $app) => new RepositoryAnalyzer(
            provider: $app->make(RepositoryProviderInterface::class),
            repository: $app->make(EnrichmentRepositoryInterface::class),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
        ));

        $this->app->bind(EnrichmentRunner::class, fn (Application $app) => new EnrichmentRunner(
            repository: $app->make(EnrichmentRepositoryInterface::class),
            analyzer: $app->make(RepositoryAnalyzer::class),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
            refreshAfterMinutes: (int) config('github.refresh.after_minutes'),
            maxFailures: (int) config('github.refresh.max_failures'),
            quotaReserve: (int) config('github.refresh.quota_reserve'),
        ));
    }
}
