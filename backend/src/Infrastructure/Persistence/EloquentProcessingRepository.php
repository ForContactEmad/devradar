<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DateTimeImmutable;
use DevRadar\Domain\Port\ProcessingRepositoryInterface;
use DevRadar\Domain\Processing\NormalizationResult;
use DevRadar\Domain\Processing\ProcessableTweet;
use DevRadar\Domain\Processing\SeenIndex;
use Illuminate\Support\Facades\DB;

/**
 * Persistence for the processing stages.
 *
 * Claims are ordered oldest-first so the original announcement becomes the
 * survivor of a duplicate group rather than whichever amplification arrived
 * last.
 */
final readonly class EloquentProcessingRepository implements ProcessingRepositoryInterface
{
    /** @return list<ProcessableTweet> */
    public function claimForNormalization(int $limit): array
    {
        $rows = DB::table('tweets')
            ->where('status', 'raw')
            ->whereNull('purged_at')
            ->orderBy('posted_at')
            ->limit($limit)
            ->get();

        return $this->hydrate($rows);
    }

    public function saveNormalization(int $tweetId, NormalizationResult $result): void
    {
        DB::table('tweets')->where('id', $tweetId)->update([
            'normalized_text' => $result->normalizedText,
            'canonical_url' => $result->canonicalUrl,
            'url_hash' => $result->urlHash,
            'text_fingerprint' => $result->textFingerprint,
            // Rejections are recorded, never deleted: rejection data is the
            // raw material for the free pre-filter rules.
            'status' => $result->isRejected() ? 'rejected' : 'normalized',
            'reject_reason' => $result->rejectReason,
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<ProcessableTweet> */
    public function claimForDeduplication(int $limit): array
    {
        $rows = DB::table('tweets')
            ->where('status', 'normalized')
            ->whereNull('purged_at')
            ->orderBy('posted_at')
            ->limit($limit)
            ->get();

        return $this->hydrate($rows);
    }

    /**
     * @param list<ProcessableTweet> $batch
     */
    public function loadSeenIndexFor(array $batch): SeenIndex
    {
        $index = new SeenIndex();

        if ($batch === []) {
            return $index;
        }

        $urlHashes = array_values(array_filter(array_map(fn ($t) => $t->urlHash, $batch)));
        $fingerprints = array_values(array_filter(array_map(fn ($t) => $t->textFingerprint, $batch)));
        $batchIds = array_map(fn ($t) => $t->id, $batch);

        if ($urlHashes === [] && $fingerprints === []) {
            return $index;
        }

        // Scoped to the batch's own keys rather than loading the window: the
        // index only has to answer questions this batch actually asks.
        //
        // Survivors only. A row already marked as a duplicate must never
        // become the survivor for another, or duplicate chains form that
        // point at rows nothing should reference.
        $rows = DB::table('tweets')
            ->select('id', 'x_tweet_id', 'url_hash', 'text_fingerprint')
            ->whereNull('duplicate_of_tweet_id')
            ->whereNotIn('id', $batchIds)
            ->whereIn('status', ['deduplicated', 'filtered', 'pending_classification', 'classified', 'published'])
            ->where(function ($query) use ($urlHashes, $fingerprints) {
                if ($urlHashes !== []) {
                    $query->orWhereIn('url_hash', $urlHashes);
                }

                if ($fingerprints !== []) {
                    $query->orWhereIn('text_fingerprint', $fingerprints);
                }
            })
            // Oldest wins, matching the claim order.
            ->orderBy('posted_at')
            ->get();

        foreach ($rows as $row) {
            $index->rememberTweetId((string) $row->x_tweet_id, (int) $row->id);

            if ($row->url_hash !== null) {
                $index->rememberUrlHash((string) $row->url_hash, (int) $row->id);
            }

            if ($row->text_fingerprint !== null) {
                $index->rememberFingerprint((string) $row->text_fingerprint, (int) $row->id);
            }
        }

        return $index;
    }

    public function markDuplicate(int $tweetId, int $survivorId, string $matchLevel): void
    {
        DB::table('tweets')->where('id', $tweetId)->update([
            'duplicate_of_tweet_id' => $survivorId,
            'duplicate_match_level' => $matchLevel,
            'status' => 'rejected',
            'reject_reason' => 'duplicate',
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function markDeduplicated(int $tweetId): void
    {
        DB::table('tweets')->where('id', $tweetId)->update([
            'status' => 'deduplicated',
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  iterable<int, object> $rows
     * @return list<ProcessableTweet>
     */
    private function hydrate(iterable $rows): array
    {
        $tweets = [];

        foreach ($rows as $row) {
            $tweets[] = new ProcessableTweet(
                id: (int) $row->id,
                xTweetId: (string) $row->x_tweet_id,
                text: (string) $row->text,
                primaryUrl: $row->primary_url === null ? null : (string) $row->primary_url,
                postedAt: new DateTimeImmutable((string) $row->posted_at),
                lang: $row->lang === null ? null : (string) $row->lang,
                authorId: (int) $row->author_id,
                urlHash: $row->url_hash === null ? null : (string) $row->url_hash,
                textFingerprint: $row->text_fingerprint === null ? null : (string) $row->text_fingerprint,
            );
        }

        return $tweets;
    }
}
