<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DateTimeImmutable;
use DevRadar\Domain\Compliance\ComplianceFinding;
use DevRadar\Domain\Compliance\ComplianceJob;
use DevRadar\Domain\Compliance\ComplianceJobState;
use DevRadar\Domain\Port\ComplianceRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Persistence for the compliance cycle, including the removal itself.
 *
 * TWO KINDS OF REMOVAL, and the difference is not cosmetic:
 *
 *   SCRUB (deleted, bounced) — the content is gone for good, so the stored
 *   text and the raw payload are overwritten. Keeping a copy of a post its
 *   author deleted is exactly what the policy forbids, and "we still have it
 *   but do not show it" is not compliance.
 *
 *   HIDE (protected, suspended) — the account may be reinstated. The row is
 *   taken out of the feed but the text is kept, because scrubbing it would
 *   mean re-purchasing the post if the account came back, and the obligation
 *   is to stop displaying rather than to forget.
 *
 * Both take any project built from the post out of the feed. That is the part
 * that actually matters to a reader: a purged post whose project still ranks
 * on the front page is not compliant, it is just quieter.
 */
final readonly class EloquentComplianceRepository implements ComplianceRepositoryInterface
{
    public function openJob(): ?ComplianceJob
    {
        $row = DB::table('compliance_jobs')
            ->whereIn('state', ['pending', 'created', 'in_progress'])
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return new ComplianceJob(
            id: (int) $row->id,
            state: ComplianceJobState::from((string) $row->state),
            providerJobId: $row->provider_job_id === null ? null : (string) $row->provider_job_id,
            uploadUrl: $row->upload_url === null ? null : (string) $row->upload_url,
            downloadUrl: $row->download_url === null ? null : (string) $row->download_url,
            uploadExpiresAt: $row->upload_expires_at === null ? null : new DateTimeImmutable((string) $row->upload_expires_at),
            downloadExpiresAt: $row->download_expires_at === null ? null : new DateTimeImmutable((string) $row->download_expires_at),
            idCount: (int) $row->id_count,
            createdAt: $row->created_at === null ? null : new DateTimeImmutable((string) $row->created_at),
            error: $row->error === null ? null : (string) $row->error,
        );
    }

    public function recordJob(ComplianceJob $job): int
    {
        return (int) DB::table('compliance_jobs')->insertGetId([
            'provider_job_id' => $job->providerJobId,
            'state' => $job->state->value,
            'upload_url' => $job->uploadUrl,
            'download_url' => $job->downloadUrl,
            'upload_expires_at' => $job->uploadExpiresAt,
            'download_expires_at' => $job->downloadExpiresAt,
            'id_count' => $job->idCount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function updateJob(int $id, ComplianceJobState $state, ?ComplianceJob $details = null, ?string $error = null): void
    {
        $attributes = ['state' => $state->value, 'updated_at' => now()];

        if ($state->isFinished()) {
            $attributes['finished_at'] = now();
        }

        if ($error !== null) {
            $attributes['error'] = mb_substr($error, 0, 2000);
        }

        if ($details !== null) {
            $attributes['download_url'] = $details->downloadUrl ?? DB::raw('download_url');
            $attributes['id_count'] = $details->idCount;
        }

        DB::table('compliance_jobs')->where('id', $id)->update($attributes);
    }

    /** @return list<string> */
    public function idsDueForCheck(int $limit, int $recheckAfterHours): array
    {
        $threshold = now()->subHours($recheckAfterHours);

        return DB::table('tweets')
            ->whereNull('purged_at')
            ->where(function ($query) use ($threshold) {
                $query->whereNull('compliance_checked_at')
                    ->orWhere('compliance_checked_at', '<', $threshold);
            })
            // Least recently checked first, so nothing is starved. A post
            // never re-checked is the one most likely to be stale.
            ->orderByRaw('compliance_checked_at NULLS FIRST')
            ->limit($limit)
            ->pluck('x_tweet_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** @param list<string> $postIds */
    public function markChecked(array $postIds): void
    {
        if ($postIds === []) {
            return;
        }

        // Chunked: the batch endpoint accepts millions of IDs, and a single
        // IN clause of that size is a query no planner enjoys.
        foreach (array_chunk($postIds, 1000) as $chunk) {
            DB::table('tweets')
                ->whereIn('x_tweet_id', $chunk)
                ->update(['compliance_checked_at' => now(), 'updated_at' => now()]);
        }
    }

    public function applyFinding(ComplianceFinding $finding): bool
    {
        return DB::transaction(function () use ($finding) {
            $tweet = DB::table('tweets')->where('x_tweet_id', $finding->postId)->first();

            if ($tweet === null) {
                // Reported for a post we no longer hold. Not an error: the
                // batch may cover IDs removed by a previous cycle.
                return false;
            }

            $now = now();

            $attributes = [
                'purged_at' => $now,
                'status' => 'purged',
                'reject_reason' => mb_substr('compliance:' . $finding->event->value, 0, 32),
                'compliance_checked_at' => $now,
                'updated_at' => $now,
            ];

            if ($finding->event->isPermanent()) {
                // The content itself must go. Keeping a copy of a post its
                // author deleted is what the policy forbids.
                $attributes['text'] = '';
                $attributes['normalized_text'] = null;
                $attributes['raw_payload'] = json_encode(['redacted' => 'compliance', 'event' => $finding->event->value]);
                $attributes['primary_url'] = null;
                $attributes['canonical_url'] = null;
            }

            DB::table('tweets')->where('id', $tweet->id)->update($attributes);

            // The part a reader notices. A purged post whose project still
            // ranks on the front page is not compliant, only quieter.
            DB::table('projects')
                ->where('primary_tweet_id', $tweet->id)
                ->update([
                    'is_visible' => false,
                    'admin_override_at' => $now,
                    'admin_override_reason' => mb_substr('Removed for compliance: ' . $finding->event->value, 0, 255),
                    'updated_at' => $now,
                ]);

            return true;
        });
    }
}
