<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Domain\Pipeline\PipelineStage;
use DevRadar\Domain\Pipeline\StageOutcome;
use DevRadar\Domain\Port\StageRunRecorderInterface;
use RuntimeException;

final class RecordingStageRunRecorder implements StageRunRecorderInterface
{
    /** @var list<array{op: string, run_id: int, stage: ?string, status: ?string}> */
    public array $calls = [];

    /** @var array<int, StageOutcome> */
    public array $finished = [];

    public bool $failOnBegin = false;

    public bool $failOnFinish = false;

    public int $reaped = 0;

    private int $nextId = 1;

    public function begin(PipelineStage $stage): int
    {
        if ($this->failOnBegin) {
            throw new RuntimeException('recorder unavailable');
        }

        $id = $this->nextId++;
        $this->calls[] = ['op' => 'begin', 'run_id' => $id, 'stage' => $stage->value, 'status' => null];

        return $id;
    }

    public function finish(int $runId, StageOutcome $outcome): void
    {
        if ($this->failOnFinish) {
            throw new RuntimeException('recorder write failed');
        }

        $this->calls[] = ['op' => 'finish', 'run_id' => $runId, 'stage' => $outcome->stage->value, 'status' => $outcome->status];
        $this->finished[$runId] = $outcome;
    }

    public function reapStale(int $olderThanMinutes): int
    {
        return $this->reaped;
    }

    /** @return list<array<string, mixed>> */
    public function ops(string $op): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['op'] === $op));
    }
}
