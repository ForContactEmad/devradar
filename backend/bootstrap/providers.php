<?php

declare(strict_types=1);

/*
 * Service providers.
 *
 * These are the container bindings for every port in the system: without them
 * no controller, job or command can resolve a dependency. They existed as
 * files and were registered nowhere.
 */

return [
    App\Providers\ObservabilityServiceProvider::class,
    App\Providers\RateLimitServiceProvider::class,
    App\Providers\IngestionServiceProvider::class,
    App\Providers\ProcessingServiceProvider::class,
    App\Providers\FilteringServiceProvider::class,
    App\Providers\ClassificationServiceProvider::class,
    App\Providers\ExtractionServiceProvider::class,
    App\Providers\EnrichmentServiceProvider::class,
    App\Providers\ScoringServiceProvider::class,
    App\Providers\ComplianceServiceProvider::class,
    App\Providers\PipelineServiceProvider::class,
    App\Providers\ApiServiceProvider::class,
];
