<?php

declare(strict_types=1);

namespace DevRadar\Application\Compliance;

use DateTimeImmutable;
use DevRadar\Domain\Compliance\ComplianceJob;
use DevRadar\Domain\Compliance\ComplianceJobState;
use DevRadar\Domain\Compliance\PurgeOutcome;
use DevRadar\Domain\Port\ComplianceProviderInterface;
use DevRadar\Domain\Port\ComplianceRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps stored posts in step with what is still public on X.
 *
 * THIS IS A LEGAL OBLIGATION, NOT A FEATURE. X's developer policy requires
 * that anyone storing X content offline keeps it reflecting the current state
 * of that content: when someone deletes a post, protects their account or is
 * suspended, the content must stop being displayed. Non-compliance risks
 * losing API access, which for DevRadar is an extinction-level event.
 *
 * ONE INVOCATION ADVANCES ONE STEP. The provider's flow is asynchronous and
 * spans minutes, so a run does whatever the current state allows and returns.
 * The next scheduled run picks up where it left off:
 *
 *   no job        -> create one, upload the IDs due for a check
 *   created       -> upload (or abandon, if the 15-minute window closed)
 *   in_progress   -> poll
 *   complete      -> download, apply findings, close the job
 *
 * Trying to drive all four steps in one invocation would mean sleeping inside
 * a worker for minutes while holding a queue slot, and would still fail
 * whenever the provider took longer than the timeout.
 *
 * FAILING CLOSED IS NOT AN OPTION HERE. If the provider is unreachable, the
 * right response is to alert, not to hide the whole feed -- posts are
 * presumed compliant until told otherwise, which is what the policy asks for.
 * But a cycle that has not completed in days is a compliance incident, and
 * the runner reports its age so an alert can fire on it.
 */
final readonly class CompliancePurgeRunner
{
    public function __construct(
        private ComplianceProviderInterface $provider,
        private ComplianceRepositoryInterface $repository,
        private LoggerInterface $logger,
        private int $batchSize = 10000,
        private int $recheckAfterHours = 24,
        private ?\Closure $clock = null,
    ) {}

    public function run(): PurgeOutcome
    {
        $now = $this->now();

        try {
            $job = $this->repository->openJob();

            return $job === null
                ? $this->startCycle($now)
                : $this->advance($job, $now);
        } catch (Throwable $e) {
            // Escalated, not swallowed: this is the one stage whose failure is
            // urgent regardless of the hour.
            $this->logger->error('compliance.cycle_failed', [
                'provider' => $this->provider->name(),
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return PurgeOutcome::failed($e->getMessage());
        }
    }

    private function startCycle(DateTimeImmutable $now): PurgeOutcome
    {
        $ids = $this->repository->idsDueForCheck($this->batchSize, $this->recheckAfterHours);

        if ($ids === []) {
            // Everything stored has been checked recently. Nothing to do, and
            // that is the healthy state rather than a problem.
            return PurgeOutcome::idle('No posts are due for a compliance check.');
        }

        $job = $this->provider->createJob('devradar-' . $now->format('Ymd-His'));
        $id = $this->repository->recordJob($job);

        $this->logger->info('compliance.job_created', [
            'job_id' => $id,
            'provider_job_id' => $job->providerJobId,
            'ids' => count($ids),
        ]);

        return $this->upload(new ComplianceJob(
            id: $id,
            state: $job->state,
            providerJobId: $job->providerJobId,
            uploadUrl: $job->uploadUrl,
            downloadUrl: $job->downloadUrl,
            uploadExpiresAt: $job->uploadExpiresAt,
            downloadExpiresAt: $job->downloadExpiresAt,
        ), $ids, $now);
    }

    private function advance(ComplianceJob $job, DateTimeImmutable $now): PurgeOutcome
    {
        return match ($job->state) {
            ComplianceJobState::Pending, ComplianceJobState::Created => $this->resumeUpload($job, $now),
            ComplianceJobState::InProgress => $this->poll($job, $now),
            // An open job in a finished state should not exist; closing it
            // rather than looping is the safe reading.
            default => $this->close($job, 'Job was already finished.'),
        };
    }

    private function resumeUpload(ComplianceJob $job, DateTimeImmutable $now): PurgeOutcome
    {
        if ($job->uploadWindowClosed($now)) {
            // The 15-minute upload URL has gone. Retrying against it fails
            // silently and leaves the job stuck, so it is abandoned and the
            // next run starts a fresh cycle.
            $this->repository->updateJob($job->id, ComplianceJobState::Expired, error: 'Upload window closed before the IDs were uploaded.');

            $this->logger->warning('compliance.upload_window_expired', ['job_id' => $job->id]);

            return PurgeOutcome::failed('Upload window closed; the cycle will restart.');
        }

        $ids = $this->repository->idsDueForCheck($this->batchSize, $this->recheckAfterHours);

        if ($ids === []) {
            return $this->close($job, 'Nothing left to check.');
        }

        return $this->upload($job, $ids, $now);
    }

    /** @param list<string> $ids */
    private function upload(ComplianceJob $job, array $ids, DateTimeImmutable $now): PurgeOutcome
    {
        if (! $this->provider->uploadIds($job, $ids)) {
            $this->repository->updateJob($job->id, ComplianceJobState::Failed, error: 'Upload rejected by the provider.');

            return PurgeOutcome::failed('Upload rejected by the provider.');
        }

        // Marked as checked at UPLOAD time, not at download. The provider
        // reports only changed posts, so a post absent from the results is
        // compliant and would otherwise never have its timestamp advanced --
        // leaving it permanently "due" and re-uploaded every cycle.
        $this->repository->markChecked($ids);
        $this->repository->updateJob($job->id, ComplianceJobState::InProgress);

        $this->logger->info('compliance.ids_uploaded', [
            'job_id' => $job->id,
            'ids' => count($ids),
        ]);

        return PurgeOutcome::submitted(count($ids));
    }

    private function poll(ComplianceJob $job, DateTimeImmutable $now): PurgeOutcome
    {
        if ($job->downloadWindowClosed($now)) {
            $this->repository->updateJob($job->id, ComplianceJobState::Expired, error: 'Results expired before they were downloaded.');

            $this->logger->error('compliance.results_expired', ['job_id' => $job->id]);

            return PurgeOutcome::failed('Results expired undownloaded; the cycle will restart.');
        }

        $status = $this->provider->jobStatus($job->providerJobId);

        return match ($status->state) {
            ComplianceJobState::Complete => $this->applyResults($job, $status),
            ComplianceJobState::Failed => $this->fail($job, $status->error ?? 'The provider reported a failed job.'),
            default => PurgeOutcome::waiting('The provider is still processing the batch.'),
        };
    }

    private function applyResults(ComplianceJob $job, ComplianceJob $status): PurgeOutcome
    {
        $findings = $this->provider->downloadResults(
            new ComplianceJob(
                id: $job->id,
                state: ComplianceJobState::Complete,
                providerJobId: $job->providerJobId,
                downloadUrl: $status->downloadUrl ?? $job->downloadUrl,
            ),
        );

        $removed = 0;
        $scrubbed = 0;
        $hidden = 0;
        $byEvent = [];

        foreach ($findings as $finding) {
            $byEvent[$finding->event->value] = ($byEvent[$finding->event->value] ?? 0) + 1;

            if (! $finding->event->requiresRemoval()) {
                // A geo scrub asks us to drop location data we never stored.
                continue;
            }

            if (! $this->repository->applyFinding($finding)) {
                continue;
            }

            $removed++;
            $finding->event->isPermanent() ? $scrubbed++ : $hidden++;
        }

        $this->close($job, null);

        $this->logger->info('compliance.results_applied', [
            'job_id' => $job->id,
            'findings' => count($findings),
            'removed' => $removed,
            'scrubbed' => $scrubbed,
            'hidden' => $hidden,
            'by_event' => $byEvent,
        ]);

        return PurgeOutcome::applied($job->idCount, $removed, $scrubbed, $hidden, $byEvent);
    }

    private function fail(ComplianceJob $job, string $reason): PurgeOutcome
    {
        $this->repository->updateJob($job->id, ComplianceJobState::Failed, error: $reason);

        $this->logger->error('compliance.job_failed', ['job_id' => $job->id, 'reason' => $reason]);

        return PurgeOutcome::failed($reason);
    }

    private function close(ComplianceJob $job, ?string $note): PurgeOutcome
    {
        $this->repository->updateJob($job->id, ComplianceJobState::Complete);

        return PurgeOutcome::idle($note ?? 'Cycle complete.');
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new DateTimeImmutable();
    }
}
