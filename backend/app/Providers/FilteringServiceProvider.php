<?php

declare(strict_types=1);

namespace App\Providers;

use DevRadar\Application\Filtering\FilterRunner;
use DevRadar\Domain\Filtering\KeywordMatcher;
use DevRadar\Domain\Filtering\ProjectSignalDetector;
use DevRadar\Domain\Filtering\SignalSetFactory;
use DevRadar\Domain\Filtering\SignalStrength;
use DevRadar\Domain\Filtering\TweetFilter;
use DevRadar\Domain\Port\FilteringRepositoryInterface;
use DevRadar\Infrastructure\Persistence\EloquentFilteringRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the pre-filter.
 *
 * Every phrase, weight and threshold is read here and injected. Nothing in
 * the domain classes knows what any of them are.
 */
final class FilteringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            FilteringRepositoryInterface::class,
            fn () => new EloquentFilteringRepository((array) config('filtering.repository_hosts', [])),
        );

        $this->app->singleton(KeywordMatcher::class);

        $this->app->singleton(ProjectSignalDetector::class, fn ($app) => new ProjectSignalDetector(
            matcher: $app->make(KeywordMatcher::class),
            signals: SignalSetFactory::fromConfig((array) config('filtering')),
            thresholds: (array) config('filtering.thresholds'),
            structural: (array) config('filtering.structural'),
        ));

        $this->app->singleton(TweetFilter::class, fn ($app) => new TweetFilter(
            detector: $app->make(ProjectSignalDetector::class),
            minimumStrength: SignalStrength::from((string) config('filtering.minimum_strength', 'weak')),
            negativeOverrideStrength: SignalStrength::from((string) config('filtering.negative_override_strength', 'strong')),
            requireEvidence: (bool) config('filtering.require_evidence', true),
        ));

        $this->app->bind(FilterRunner::class, fn ($app) => new FilterRunner(
            repository: $app->make(FilteringRepositoryInterface::class),
            filter: $app->make(TweetFilter::class),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
        ));
    }
}
