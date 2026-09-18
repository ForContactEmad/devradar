<?php

declare(strict_types=1);

namespace App\Jobs;

use DevRadar\Application\Compliance\CompliancePurgeRunner;
use DevRadar\Domain\Pipeline\PipelineStage;

/**
 * Advances the compliance cycle by one step.
 *
 * One invocation does whatever the current state allows -- create, upload,
 * poll or apply -- and returns. The provider's flow spans minutes, and
 * sleeping inside a worker to drive all four steps would hold a queue slot
 * and still time out whenever the provider took longer than expected.
 */
final class ComplyJob extends PipelineJob
{
    public function stage(): PipelineStage
    {
        return PipelineStage::Comply;
    }

    /**
     * Compliance requires X credentials, and nothing else does.
     *
     * The whole stage exists to ask X which collected posts have since been
     * deleted. With no token there is no X client to ask -- and there are also
     * no collected posts to ask about, because collection is the only thing
     * that writes them and it is unconfigured for the same reason.
     *
     * XApiConfig throws on an empty token BY DESIGN, and that is not weakened
     * here: this checks the same configuration value before anything tries to
     * build a client, so an unconfigured deployment skips the stage instead of
     * failing it eight times and abandoning the job.
     *
     * DELIBERATELY NARROW. The only condition that skips is an absent token.
     * A token that is present but rejected, a provider that is down, a job
     * that expires -- all of those still fail loudly, because they are real
     * compliance failures and a legal obligation must not fail quietly.
     */
    protected function unmetPrecondition(): ?string
    {
        $token = config('x.bearer_token');

        if (is_string($token) && trim($token) !== '') {
            return null;
        }

        return 'X_API_BEARER_TOKEN is not configured; there is no provider to reconcile against.';
    }

    protected function batchSize(): int
    {
        return (int) config('compliance.batch_size');
    }

    protected function runStage(): array
    {
        $outcome = app(CompliancePurgeRunner::class)->run();

        return [
            'phase' => $outcome->phase,
            // 'claimed' is what the executor reads to decide whether the run
            // did work; for this stage that is the number of ids submitted.
            'claimed' => $outcome->checked,
            'removed' => $outcome->removed,
            'scrubbed' => $outcome->scrubbed,
            'hidden' => $outcome->hidden,
            'by_event' => $outcome->byEvent,
            'note' => $outcome->note,
        ];
    }
}
