<?php

declare(strict_types=1);

namespace DevRadar\Domain\Pipeline;

use Throwable;

/**
 * What one stage execution did.
 *
 * Recorded for every run, successful or not. A pipeline whose stages leave no
 * trace is one where "why did nothing appear yesterday" has no answer.
 */
final readonly class StageOutcome
{
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    /** @param array<string, mixed> $stats */
    private function __construct(
        public PipelineStage $stage,
        public string $status,
        public array $stats,
        public float $durationSeconds,
        public ?string $error = null,
        public ?Throwable $exception = null,
    ) {}

    /** @param array<string, mixed> $stats */
    public static function succeeded(PipelineStage $stage, array $stats, float $duration): self
    {
        return new self($stage, self::SUCCEEDED, $stats, $duration);
    }

    public static function failed(PipelineStage $stage, Throwable $e, float $duration): self
    {
        return new self($stage, self::FAILED, [], $duration, $e->getMessage(), $e);
    }

    /** A stage that declined to run: another instance held the lock. */
    public static function skipped(PipelineStage $stage, string $reason): self
    {
        return new self($stage, self::SKIPPED, [], 0.0, $reason);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCEEDED;
    }

    /**
     * Did the stage actually move anything?
     *
     * Used to decide whether nudging the next stage is worth a dispatch. A
     * run that processed nothing has given the next stage no new work.
     */
    public function didWork(): bool
    {
        if (! $this->isSuccess()) {
            return false;
        }

        foreach (['claimed', 'scored', 'published', 'linked'] as $key) {
            if ((int) ($this->stats[$key] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
