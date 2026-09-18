<?php

declare(strict_types=1);

namespace App\Providers;

use DevRadar\Application\Extraction\ExtractionRunner;
use DevRadar\Application\Extraction\ProjectExtractor;
use DevRadar\Domain\Classification\PromptBuilder;
use DevRadar\Domain\Extraction\CategoryRegistry;
use DevRadar\Domain\Extraction\ExtractionResponseParser;
use DevRadar\Domain\Extraction\ExtractionValidator;
use DevRadar\Domain\Extraction\TechnologyDetector;
use DevRadar\Domain\Extraction\UrlClassifier;
use DevRadar\Domain\Filtering\KeywordMatcher;
use DevRadar\Domain\Port\LlmProviderInterface;
use DevRadar\Domain\Port\ProjectRepositoryInterface;
use DevRadar\Infrastructure\Persistence\EloquentProjectRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for extraction.
 *
 * The three concerns stay in three objects, wired here:
 *
 *   ProjectExtractor      asks the model
 *   ExtractionValidator   decides what may be asserted
 *   ProjectRepository     stores it
 *
 * Note the PromptBuilder is a NAMED binding: extraction and classification
 * use different templates and different versions, and resolving the wrong one
 * would silently send the classification prompt to the extractor.
 */
final class ExtractionServiceProvider extends ServiceProvider
{
    public const EXTRACTION_PROMPTS = 'devradar.prompts.extraction';

    public function register(): void
    {
        $this->app->bind(ProjectRepositoryInterface::class, EloquentProjectRepository::class);

        $this->app->singleton(CategoryRegistry::class, fn () => new CategoryRegistry(
            categories: (array) config('extraction.categories'),
            fallback: (string) config('extraction.fallback_category', 'other'),
        ));

        $this->app->singleton(UrlClassifier::class);

        $this->app->singleton(TechnologyDetector::class, fn (Application $app) => new TechnologyDetector(
            matcher: $app->make(KeywordMatcher::class),
            catalog: (array) config('extraction.technologies'),
        ));

        $this->app->singleton(ExtractionValidator::class, fn (Application $app) => new ExtractionValidator(
            categories: $app->make(CategoryRegistry::class),
            urls: $app->make(UrlClassifier::class),
            technologies: $app->make(TechnologyDetector::class),
            projectTypes: (array) config('extraction.project_types'),
            minimumConfidence: (float) config('extraction.minimum_confidence'),
            maxNameLength: (int) config('extraction.max_name_length'),
            maxDescriptionLength: (int) config('extraction.max_description_length'),
        ));

        $this->app->singleton(self::EXTRACTION_PROMPTS, fn () => new PromptBuilder(
            systemTemplate: (string) config('extraction.system_prompt'),
            promptVersion: (string) config('extraction.prompt_version'),
            maxTokens: (int) config('ai.max_tokens'),
            maxPostChars: (int) config('ai.max_post_chars'),
        ));

        $this->app->bind(ProjectExtractor::class, fn (Application $app) => new ProjectExtractor(
            provider: $app->make(LlmProviderInterface::class),
            prompts: $app->make(self::EXTRACTION_PROMPTS),
            parser: new ExtractionResponseParser(),
            validator: $app->make(ExtractionValidator::class),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
        ));

        $this->app->bind(ExtractionRunner::class, fn (Application $app) => new ExtractionRunner(
            repository: $app->make(ProjectRepositoryInterface::class),
            extractor: $app->make(ProjectExtractor::class),
            budget: $app->make(\DevRadar\Domain\Port\BudgetGuardInterface::class),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
        ));
    }
}
