<?php

declare(strict_types=1);

namespace DevRadar\Domain\Compliance;

/**
 * Where a batch compliance job has got to.
 *
 * X's flow is asynchronous in four steps -- create, upload, poll, download --
 * and the gap between them can be minutes. The state therefore has to
 * survive between scheduled runs, which is why this is persisted rather than
 * held in a variable.
 *
 * Two expiries matter and are different: the upload URL lasts 15 minutes, the
 * download URL a week. A job whose upload window closed is dead and must be
 * abandoned, not retried against a URL that no longer works.
 */
enum ComplianceJobState: string
{
    /** Created locally; the provider job does not exist yet. */
    case Pending = 'pending';
    /** Provider job created, IDs not yet uploaded. Upload URL expiring. */
    case Created = 'created';
    /** IDs uploaded; waiting for the provider. */
    case InProgress = 'in_progress';
    /** Results downloaded and applied. */
    case Complete = 'complete';
    case Failed = 'failed';
    /** The upload window closed before we used it. */
    case Expired = 'expired';

    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Created || $this === self::InProgress;
    }

    public function isFinished(): bool
    {
        return ! $this->isOpen();
    }

    /** X's own job statuses, which are not quite ours. */
    public static function fromProviderStatus(?string $status): self
    {
        return match (strtolower(trim((string) $status))) {
            'created' => self::Created,
            'in_progress' => self::InProgress,
            'complete' => self::Complete,
            'failed' => self::Failed,
            default => self::InProgress,
        };
    }
}
