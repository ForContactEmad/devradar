<?php

declare(strict_types=1);

namespace App\Providers;

use DevRadar\Application\Classification\AiClassifier;
use DevRadar\Application\Classification\ClassificationRunner;
use DevRadar\Domain\Classification\ClassificationResponseParser;
use DevRadar\Domain\Classification\PromptBuilder;
use DevRadar\Domain\Port\ClassificationRepositoryInterface;
use DevRadar\Domain\Port\LlmProviderInterface;
use DevRadar\Infrastructure\Http\HttpClientInterface;
use DevRadar\Infrastructure\Llm\AnthropicProvider;
use DevRadar\Infrastructure\Llm\CachingLlmProvider;
use DevRadar\Infrastructure\Llm\OpenAiCompatibleProvider;
use DevRadar\Infrastructure\Llm\RetryingLlmProvider;
use DevRadar\Infrastructure\Persistence\EloquentClassificationRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Composition root for AI classification.
 *
 * The decorator stack is assembled here and nowhere else:
 *
 *   AiClassifier -> Caching -> Retrying -> <concrete provider>
 *
 * Switching providers is one configuration value. Nothing above this file
 * knows which model answered.
 */
final class ClassificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ClassificationRepositoryInterface::class, EloquentClassificationRepository::class);

        $this->app->bind(LlmProviderInterface::class, function (Application $app) {
            $provider = $this->concreteProvider($app);

            $provider = new RetryingLlmProvider(
                inner: $provider,
                logger: $app->make(\Psr\Log\LoggerInterface::class),
                maxAttempts: (int) config('ai.retry.max_attempts'),
                baseSeconds: (float) config('ai.retry.base_backoff_seconds'),
                maxSeconds: (float) config('ai.retry.max_backoff_seconds'),
            );

            if (! config('ai.cache.enabled')) {
                return $provider;
            }

            return new CachingLlmProvider(
                inner: $provider,
                get: fn (string $key) => Cache::get($key),
                put: fn (string $key, string $value, int $ttl) => Cache::put($key, $value, $ttl),
                ttlSeconds: (int) config('ai.cache.ttl_seconds'),
            );
        });

        $this->app->singleton(PromptBuilder::class, fn () => new PromptBuilder(
            systemTemplate: (string) config('ai.system_prompt'),
            promptVersion: (string) config('ai.prompt_version'),
            maxTokens: (int) config('ai.max_tokens'),
            maxPostChars: (int) config('ai.max_post_chars'),
        ));

        $this->app->bind(AiClassifier::class, fn (Application $app) => new AiClassifier(
            provider: $app->make(LlmProviderInterface::class),
            prompts: $app->make(PromptBuilder::class),
            parser: new ClassificationResponseParser(),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
            minimumConfidence: (float) config('ai.minimum_confidence'),
            inputTokenPriceUsd: (float) config('ai.pricing.input_token_usd'),
            outputTokenPriceUsd: (float) config('ai.pricing.output_token_usd'),
        ));

        $this->app->bind(ClassificationRunner::class, fn (Application $app) => new ClassificationRunner(
            repository: $app->make(ClassificationRepositoryInterface::class),
            classifier: $app->make(AiClassifier::class),
            budget: $app->make(\DevRadar\Domain\Port\BudgetGuardInterface::class),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
        ));
    }

    private function concreteProvider(Application $app): LlmProviderInterface
    {
        $http = $app->make(HttpClientInterface::class);
        $timeout = (float) config('ai.timeout_seconds');

        return match ((string) config('ai.provider')) {
            'anthropic' => new AnthropicProvider(
                http: $http,
                apiKey: (string) config('ai.anthropic.api_key'),
                model: (string) config('ai.anthropic.model'),
                baseUrl: (string) config('ai.anthropic.base_url'),
                apiVersion: (string) config('ai.anthropic.version'),
                timeoutSeconds: $timeout,
            ),
            'openai', 'openai_compatible', 'local' => new OpenAiCompatibleProvider(
                http: $http,
                apiKey: (string) config('ai.openai_compatible.api_key'),
                model: (string) config('ai.openai_compatible.model'),
                baseUrl: (string) config('ai.openai_compatible.base_url'),
                providerName: (string) config('ai.openai_compatible.provider_name'),
                timeoutSeconds: $timeout,
            ),
            // Fails loudly rather than silently classifying nothing, which
            // would look like a quiet week rather than a misconfiguration.
            default => throw new RuntimeException(sprintf(
                'Unknown AI provider "%s". Set DEVRADAR_AI_PROVIDER to anthropic or openai_compatible.',
                (string) config('ai.provider'),
            )),
        };
    }
}
