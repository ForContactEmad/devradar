<?php

declare(strict_types=1);

namespace App\Providers;

use DevRadar\Application\Scoring\ScoringRunner;
use DevRadar\Domain\Port\ScoringRepositoryInterface;
use DevRadar\Domain\Scoring\ConfidenceScorer;
use DevRadar\Domain\Scoring\EngagementScorer;
use DevRadar\Domain\Scoring\GitHubScorer;
use DevRadar\Domain\Scoring\GrowthScorer;
use DevRadar\Domain\Scoring\RecencyScorer;
use DevRadar\Domain\Scoring\ScoringEngine;
use DevRadar\Domain\Scoring\ScoringWeights;
use DevRadar\Infrastructure\Persistence\EloquentScoringRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for ranking.
 *
 * Every number the engine uses is read here and injected. No scorer reads
 * configuration, and none of this lives in a controller, a model or an API
 * handler -- the formula is a domain concern and stays one.
 */
final class ScoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ScoringRepositoryInterface::class, EloquentScoringRepository::class);

        $this->app->singleton(EngagementScorer::class, fn () => new EngagementScorer(
            interactionWeights: (array) config('scoring.engagement.interactions'),
            absoluteCap: (float) config('scoring.engagement.absolute_cap'),
            rateCap: (float) config('scoring.engagement.rate_cap'),
            rateShare: (float) config('scoring.engagement.rate_share'),
            reachFloor: (int) config('scoring.engagement.reach_floor'),
            anomalyRateThreshold: (float) config('scoring.engagement.anomaly_rate_threshold'),
            anomalyDampener: (float) config('scoring.engagement.anomaly_dampener'),
        ));

        $this->app->singleton(RecencyScorer::class, fn () => new RecencyScorer(
            halfLifeHours: (float) config('scoring.recency.half_life_hours'),
            windowHours: (float) config('scoring.recency.window_hours'),
            graceHours: (float) config('scoring.recency.grace_hours'),
        ));

        $this->app->singleton(ConfidenceScorer::class, fn () => new ConfidenceScorer(
            classificationShare: (float) config('scoring.confidence.classification_share'),
            extractionShare: (float) config('scoring.confidence.extraction_share'),
        ));

        $this->app->singleton(GitHubScorer::class, fn () => new GitHubScorer(
            starCap: (float) config('scoring.github.star_cap'),
            forkCap: (float) config('scoring.github.fork_cap'),
            starShare: (float) config('scoring.github.star_share'),
            forkShare: (float) config('scoring.github.fork_share'),
            freshnessShare: (float) config('scoring.github.freshness_share'),
            staleAfterDays: (float) config('scoring.github.stale_after_days'),
        ));

        $this->app->singleton(GrowthScorer::class, fn () => new GrowthScorer(
            ratePerHourCap: (float) config('scoring.growth.rate_per_hour_cap'),
            minimumIntervalHours: (float) config('scoring.growth.minimum_interval_hours'),
        ));

        $this->app->singleton(ScoringEngine::class, fn (Application $app) => new ScoringEngine(
            engagement: $app->make(EngagementScorer::class),
            recency: $app->make(RecencyScorer::class),
            confidence: $app->make(ConfidenceScorer::class),
            github: $app->make(GitHubScorer::class),
            growth: $app->make(GrowthScorer::class),
            weights: new ScoringWeights((array) config('scoring.weights')),
            scale: (float) config('scoring.scale'),
            sparseDataDampener: (float) config('scoring.sparse_data.dampener'),
            sparseDataThreshold: (int) config('scoring.sparse_data.threshold'),
        ));

        $this->app->bind(ScoringRunner::class, fn (Application $app) => new ScoringRunner(
            repository: $app->make(ScoringRepositoryInterface::class),
            engine: $app->make(ScoringEngine::class),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
            staleAfterMinutes: (int) config('scoring.rescore.stale_after_minutes'),
            snapshotEveryMinutes: (int) config('scoring.rescore.snapshot_every_minutes'),
            windowHours: (int) config('scoring.recency.window_hours'),
        ));
    }
}
