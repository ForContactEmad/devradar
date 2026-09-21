<?php

declare(strict_types=1);

namespace App\Providers;

use DevRadar\Application\Query\ProjectQueryService;
use DevRadar\Application\Health\HealthCheck;
use DevRadar\Application\Query\StatisticsService;
use DevRadar\Application\Statistics\TrendsService;
use DevRadar\Application\Query\TaxonomyQueryService;
use DevRadar\Domain\Port\ProjectQueryRepositoryInterface;
use DevRadar\Domain\Port\SearchRunQueryRepositoryInterface;
use DevRadar\Domain\Port\StatisticsRepositoryInterface;
use DevRadar\Infrastructure\Persistence\EloquentProjectQueryRepository;
use DevRadar\Infrastructure\Persistence\CachedStatisticsRepository;
use DevRadar\Infrastructure\Persistence\EloquentSearchRunQueryRepository;
use DevRadar\Infrastructure\Persistence\EloquentStatisticsRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the read path: the query services behind the HTTP API.
 *
 * Nothing registered here can reach a paid provider. That is structural, not a
 * convention -- the read path depends only on query repositories, so rendering
 * a page is incapable of spending money.
 */
final class ApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProjectQueryRepositoryInterface::class, EloquentProjectQueryRepository::class);
        $this->app->bind(SearchRunQueryRepositoryInterface::class, EloquentSearchRunQueryRepository::class);

        /*
         * Aggregations are cached by date range. The feed only changes when a
         * pipeline run publishes, so a fifteen-minute-stale count is not a
         * meaningful inaccuracy -- and it turns a burst of dashboard loads
         * into one set of GROUP BY queries.
         */
        $this->app->bind(StatisticsRepositoryInterface::class, fn () => new CachedStatisticsRepository(
            inner: new EloquentStatisticsRepository(),
            remember: fn (string $key, int $ttl, \Closure $compute) => \Illuminate\Support\Facades\Cache::remember($key, $ttl, $compute),
            ttlSeconds: (int) config('scoring.rescore.stale_after_minutes', 15) * 60,
        ));

        $this->app->bind(TrendsService::class, fn (Application $app) => new TrendsService(
            repository: $app->make(StatisticsRepositoryInterface::class),
            windowDays: (int) config('collection.window.days', 7),
            minimumSample: (int) config('statistics.minimum_sample', 8),
            deadBand: (float) config('statistics.dead_band', 0.05),
            topLimit: (int) config('statistics.top_limit', 10),
        ));

        $this->app->singleton(HealthCheck::class, fn () => new HealthCheck([
            new \DevRadar\Infrastructure\Health\DatabaseProbe(),
            new \DevRadar\Infrastructure\Health\RedisProbe(),
        ]));

        $this->app->bind(ProjectQueryService::class, fn (Application $app) => new ProjectQueryService(
            $app->make(ProjectQueryRepositoryInterface::class),
        ));

        $this->app->bind(TaxonomyQueryService::class, fn (Application $app) => new TaxonomyQueryService(
            $app->make(ProjectQueryRepositoryInterface::class),
        ));

        $this->app->bind(StatisticsService::class, fn (Application $app) => new StatisticsService(
            $app->make(ProjectQueryRepositoryInterface::class),
            (int) config('collection.window.days', 7),
        ));
    }
}
