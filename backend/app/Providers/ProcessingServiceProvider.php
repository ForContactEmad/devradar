<?php

declare(strict_types=1);

namespace App\Providers;

use DevRadar\Domain\Port\ProcessingRepositoryInterface;
use DevRadar\Domain\Processing\TextNormalizer;
use DevRadar\Domain\Processing\TweetDeduplicator;
use DevRadar\Domain\Processing\TweetNormalizer;
use DevRadar\Domain\Processing\UrlCanonicalizer;
use DevRadar\Infrastructure\Persistence\EloquentProcessingRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the processing stages.
 *
 * Everything bound here is pure except the repository, which is the only
 * component that touches the database.
 */
final class ProcessingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProcessingRepositoryInterface::class, EloquentProcessingRepository::class);

        $this->app->singleton(TextNormalizer::class);
        $this->app->singleton(UrlCanonicalizer::class);
        $this->app->singleton(TweetDeduplicator::class);

        $this->app->singleton(TweetNormalizer::class, fn ($app) => new TweetNormalizer(
            text: $app->make(TextNormalizer::class),
            urls: $app->make(UrlCanonicalizer::class),
            requireLink: (bool) config('processing.require_link', true),
        ));
    }
}
