<?php

declare(strict_types=1);

namespace App\Providers;

use App\Jobs\ClassifyTweetsJob;
use App\Jobs\ComplyJob;
use App\Jobs\CollectTweetsJob;
use App\Jobs\DeduplicateTweetsJob;
use App\Jobs\EnrichRepositoriesJob;
use App\Jobs\ExtractProjectsJob;
use App\Jobs\FilterTweetsJob;
use App\Jobs\NormalizeTweetsJob;
use App\Jobs\ScoreProjectsJob;
use DevRadar\Application\Pipeline\StageExecutor;
use DevRadar\Domain\Pipeline\PipelineStage;
use DevRadar\Domain\Port\StageRunRecorderInterface;
use DevRadar\Infrastructure\Persistence\EloquentStageRunRecorder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for background processing.
 *
 * The stage-to-job mapping lives here and nowhere else, so the executor can
 * dispatch a follow-on without importing a single job class -- which is what
 * keeps it framework-free and testable.
 */
final class PipelineServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string> */
    private const JOBS = [
        'collect' => CollectTweetsJob::class,
        'normalize' => NormalizeTweetsJob::class,
        'deduplicate' => DeduplicateTweetsJob::class,
        'filter' => FilterTweetsJob::class,
        'classify' => ClassifyTweetsJob::class,
        'extract' => ExtractProjectsJob::class,
        'enrich' => EnrichRepositoriesJob::class,
        'score' => ScoreProjectsJob::class,
        'comply' => ComplyJob::class,
    ];

    public function register(): void
    {
        $this->app->bind(StageRunRecorderInterface::class, EloquentStageRunRecorder::class);

        $this->app->bind(StageExecutor::class, fn (Application $app) => new StageExecutor(
            recorder: $app->make(StageRunRecorderInterface::class),
            logger: $app->make(\Psr\Log\LoggerInterface::class),
            dispatcher: static function (PipelineStage $stage): void {
                $job = self::JOBS[$stage->value] ?? null;

                if ($job !== null) {
                    dispatch(new $job());
                }
            },
            followOnEnabled: (bool) config('pipeline.follow_on_dispatch', true),
        ));
    }

    /** @return array<string, class-string> */
    public static function jobs(): array
    {
        return self::JOBS;
    }
}
