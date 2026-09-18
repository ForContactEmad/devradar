<?php

declare(strict_types=1);

namespace App\Jobs;

use DevRadar\Application\Classification\ClassificationRunner;
use DevRadar\Domain\Pipeline\PipelineStage;

/**
 * Classifies filtered posts. Paid: attempted once, with the budget guard consulted per post.
 */
final class ClassifyTweetsJob extends PipelineJob
{
    public function stage(): PipelineStage
    {
        return PipelineStage::Classify;
    }

    protected function batchSize(): int
    {
        return (int) config('ai.batch_size');
    }

    /**
     * Classification requires a real model provider.
     *
     * The supported values are anthropic, openai, openai_compatible and local.
     * `mock` is NOT among them -- it exists only as a test double under
     * tests/Fake, which composer does not autoload in production -- yet it was
     * the default in config/ai.php and the value recommended by the README,
     * SETUP.md and .env.example.
     *
     * So a first-time local install followed the documentation and got a
     * RuntimeException from the container. Checking here turns that into an
     * explicit, recorded skip; the provider itself still fails loudly on a
     * genuinely unknown value, which is the behaviour worth keeping.
     */
    protected function unmetPrecondition(): ?string
    {
        $provider = (string) config('ai.provider');

        if (in_array($provider, ['anthropic', 'openai', 'openai_compatible', 'local'], true)) {
            return null;
        }

        return sprintf(
            'No AI provider configured (DEVRADAR_AI_PROVIDER=%s); set it to anthropic or openai_compatible.',
            $provider === '' ? '(empty)' : $provider,
        );
    }

    protected function runStage(): array
    {
        return app(ClassificationRunner::class)->run($this->batchSize());
    }
}
