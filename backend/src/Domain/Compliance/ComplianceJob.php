<?php

declare(strict_types=1);

namespace DevRadar\Domain\Compliance;

use DateTimeImmutable;

/**
 * A batch compliance job.
 *
 * The upload and download URLs are pre-signed and time-limited. They are
 * stored rather than derived because there is no way to derive them again:
 * losing them loses the results, and the results name content we are required
 * to stop displaying.
 *
 * Both windows are checked explicitly. Missing the download window is the
 * expensive one -- the results are gone while the posts they named are still
 * on the page -- so it is a first-class question rather than something the
 * caller has to remember to work out.
 */
final readonly class ComplianceJob
{
    /** Below this much remaining window, an upload is not worth starting. */
    public const UPLOAD_MARGIN_SECONDS = 60;

    public function __construct(
        public ?int $id,
        public ComplianceJobState $state,
        public ?string $providerJobId = null,
        public ?string $uploadUrl = null,
        public ?string $downloadUrl = null,
        public ?DateTimeImmutable $uploadExpiresAt = null,
        public ?DateTimeImmutable $downloadExpiresAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public int $idCount = 0,
    ) {}

    /**
     * Is the upload window closed, or too close to closing to be worth using?
     *
     * A SAFETY MARGIN, not a bare comparison. Thirty seconds of window left
     * is technically open, but uploading a thousand ids into it races the
     * expiry: the request either half-lands or is rejected, and either way
     * the job is spent and the posts go unverified for another cycle.
     * Failing early leaves the ids in the queue for a fresh job instead.
     */
    public function uploadWindowClosed(?DateTimeImmutable $now = null, int $marginSeconds = self::UPLOAD_MARGIN_SECONDS): bool
    {
        if ($this->uploadExpiresAt === null) {
            return false;
        }

        return ($now ?? new DateTimeImmutable())->modify("+{$marginSeconds} seconds") > $this->uploadExpiresAt;
    }

    public function downloadWindowClosed(?DateTimeImmutable $now = null): bool
    {
        return $this->downloadExpiresAt !== null
            && ($now ?? new DateTimeImmutable()) > $this->downloadExpiresAt;
    }

    public function withState(ComplianceJobState $state): self
    {
        return new self(
            $this->id, $state, $this->providerJobId, $this->uploadUrl, $this->downloadUrl,
            $this->uploadExpiresAt, $this->downloadExpiresAt, $this->createdAt, $this->idCount,
        );
    }

    public function withId(int $id): self
    {
        return new self(
            $id, $this->state, $this->providerJobId, $this->uploadUrl, $this->downloadUrl,
            $this->uploadExpiresAt, $this->downloadExpiresAt, $this->createdAt, $this->idCount,
        );
    }
}
