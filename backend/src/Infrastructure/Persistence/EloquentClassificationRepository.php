<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DevRadar\Domain\Classification\ClassificationOutcome;
use DevRadar\Domain\Classification\ClassificationRequest;
use DevRadar\Domain\Classification\ClassificationResult;
use DevRadar\Domain\Port\ClassificationRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Persistence for AI classification.
 *
 * Analyses are INSERTED and the previous current row is cleared, never
 * overwritten. The partial unique index on ai_analyses guarantees at most one
 * current row per post, and the history is what lets a prompt change be
 * replayed against the labelled set and compared row by row.
 */
final readonly class EloquentClassificationRepository implements ClassificationRepositoryInterface
{
    /**
     * Claim posts for classification, atomically.
     *
     * CLAIM-AND-MARK IN ONE SHORT TRANSACTION. A plain SELECT ... LIMIT lets
     * two workers claim the same rows -- verified against PostgreSQL: both
     * sessions returned ids 1-5. For a stage that pays a model per post, that
     * is paying twice for the same answer.
     *
     * FOR UPDATE SKIP LOCKED makes the claims disjoint: the second worker
     * steps over the locked rows and takes the next five. The rows are moved
     * to `pending_classification` and the transaction commits immediately, so
     * no lock is held while model calls are in flight.
     *
     * The job lock (ShouldBeUnique) already prevents two scheduled jobs for
     * this stage. This protects the cases it does not cover: a command run by
     * hand alongside a queued job, or a lock that expired under a long run.
     *
     * @return list<ClassificationRequest>
     */
    public function claimForClassification(int $limit): array
    {
        $rows = DB::transaction(function () use ($limit) {
            $claimed = DB::table('tweets')
                ->select('id', 'normalized_text', 'text', 'canonical_url', 'primary_url', 'lang')
                ->where('status', 'filtered')
                ->whereNull('purged_at')
                ->orderBy('posted_at')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            if ($claimed->isNotEmpty()) {
                DB::table('tweets')
                    ->whereIn('id', $claimed->pluck('id')->all())
                    ->update(['status' => 'pending_classification', 'updated_at' => now()]);
            }

            return $claimed;
        });

        $requests = [];

        foreach ($rows as $row) {
            $urls = array_values(array_filter([$row->canonical_url, $row->primary_url]));

            $requests[] = new ClassificationRequest(
                tweetId: (int) $row->id,
                text: (string) ($row->normalized_text ?? $row->text),
                knownUrls: $urls,
                lang: $row->lang === null ? null : (string) $row->lang,
                hasRepositoryLink: $row->canonical_url !== null
                    && str_contains((string) $row->canonical_url, 'github.com'),
            );
        }

        return $requests;
    }

    public function saveAnalysis(ClassificationResult $result): void
    {
        DB::transaction(function () use ($result) {
            // Exactly one current analysis per post; the partial unique index
            // enforces it, so the old row must be cleared first.
            DB::table('ai_analyses')
                ->where('tweet_id', $result->tweetId)
                ->where('is_current', true)
                ->update(['is_current' => false, 'updated_at' => now()]);

            DB::table('ai_analyses')->insert([
                'tweet_id' => $result->tweetId,
                'provider' => $result->identity->provider,
                'model' => $result->identity->model,
                'model_version' => $result->identity->modelVersion ?: null,
                'prompt_version' => $result->identity->promptVersion,
                'is_launch' => $result->isProject && $result->isNew === true,
                'confidence' => $result->confidence,
                'extracted_description' => $result->reason,
                'raw_response' => $result->rawResponse === null ? null : json_encode($result->rawResponse),
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'cost_usd' => round($result->costUsd, 6),
                'is_current' => true,
                'analyzed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('tweets')->where('id', $result->tweetId)->update([
                'status' => $this->statusFor($result->outcome),
                'reject_reason' => $this->rejectReasonFor($result->outcome),
                'processed_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Return posts abandoned mid-classification to the queue.
     *
     * A worker killed after claiming leaves rows in `pending_classification`
     * that nothing will ever pick up. Without this they are lost silently --
     * already paid for at collection, filtered, and then dropped.
     */
    public function releaseStaleClaims(int $olderThanMinutes): int
    {
        return DB::table('tweets')
            ->where('status', 'pending_classification')
            ->where('updated_at', '<', now()->subMinutes($olderThanMinutes))
            ->update(['status' => 'filtered', 'updated_at' => now()]);
    }

    public function cycleSpendUsd(): float
    {
        return (float) DB::table('ai_analyses')
            ->where('analyzed_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
    }

    private function statusFor(ClassificationOutcome $outcome): string
    {
        return match ($outcome) {
            ClassificationOutcome::Accepted => 'classified',
            // A failed call returns the post to the CLAIMABLE state, not the
            // claimed one, so the next run picks it up. Re-classifying costs
            // a model call, not a repeat purchase of data.
            ClassificationOutcome::Failed => 'filtered',
            default => 'rejected',
        };
    }

    private function rejectReasonFor(ClassificationOutcome $outcome): ?string
    {
        return match ($outcome) {
            ClassificationOutcome::Rejected => 'not-a-launch',
            ClassificationOutcome::LowConfidence => 'low-confidence',
            ClassificationOutcome::Unparseable => 'unparseable-response',
            default => null,
        };
    }
}
