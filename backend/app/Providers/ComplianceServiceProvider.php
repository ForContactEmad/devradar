<?php

declare(strict_types=1);

namespace App\Providers;

use DevRadar\Application\Compliance\CompliancePurgeRunner;
use DevRadar\Domain\Port\ComplianceProviderInterface;
use DevRadar\Domain\Port\ComplianceRepositoryInterface;
use DevRadar\Infrastructure\Http\HttpClientInterface;
use DevRadar\Infrastructure\Persistence\EloquentComplianceRepository;
use DevRadar\Infrastructure\X\XApiConfig;
use DevRadar\Infrastructure\X\XComplianceClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class ComplianceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ComplianceRepositoryInterface::class, EloquentComplianceRepository::class);

                $this->app->bind(ComplianceProviderInterface::class, function (Application $app) {
            // XApiConfig holds the bearer token privately and exposes it only
            // through authHeaders(), so it is read here rather than passed
            // around as a string between layers.
            $config = $app->make(XApiConfig::class);

            return new XComplianceClient(
                http: $app->make(HttpClientInterface::class),
                bearerToken: (string) config('x.bearer_token'),
                baseUrl: $config->baseUrl,
                timeoutSeconds: (float) config('compliance.timeout_seconds'),
            );
        });

        $this->app->bind(CompliancePurgeRunner::class, fn (Application $app) => new CompliancePurgeRunner(
            provider: $app->make(ComplianceProviderInterface::class),
            repository: $app->make(ComplianceRepositoryInterface::class),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
            batchSize: (int) config('compliance.batch_size'),
            recheckAfterHours: (int) config('compliance.recheck_after_hours'),
        ));
    }
}
