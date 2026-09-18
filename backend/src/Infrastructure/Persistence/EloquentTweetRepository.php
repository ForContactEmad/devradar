<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DevRadar\Domain\Ingestion\PostBatch;
use DevRadar\Domain\Ingestion\RawPost;
use DevRadar\Domain\Port\StoreResult;
use DevRadar\Domain\Port\TweetRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Stores collected posts.
 *
 * IDEMPOTENCE IS ENFORCED BY THE DATABASE, not by a read-then-write check.
 * `INSERT ... ON CONFLICT DO NOTHING` against the unique post id makes a
 * duplicate a no-op even when two workers insert the same post concurrently.
 * A SELECT-then-INSERT would race, and the race would surface as an exception
 * on a page that had already been paid for.
 *
 * The count of rows actually inserted is the duplicate signal the collector
 * reports, and it comes free from the same statement.
 */
final readonly class EloquentTweetRepository implements TweetRepositoryInterface
{
    public function store(PostBatch $batch, ?int $searchRunId): StoreResult
    {
        if ($batch->isEmpty()) {
            return new StoreResult(0, 0, 0);
        }

        return DB::transaction(function () use ($batch, $searchRunId) {
            $authorsStored = $this->storeAuthors($batch);
            $authorIds = $this->authorIdMap($batch);

            $rows = [];
            $now = now();

            foreach ($batch->posts as $post) {
                // A post whose author was not expanded cannot satisfy the
                // NOT NULL foreign key. Skipping is correct: the post is
                // recoverable from the raw payload later, and failing the
                // whole batch would discard pages already charged for.
                if (! isset($authorIds[$post->authorId])) {
                    continue;
                }

                $rows[] = [
                    'x_tweet_id' => $post->id,
                    'author_id' => $authorIds[$post->authorId],
                    'search_run_id' => $searchRunId,
                    'text' => $post->text,
                    'lang' => $post->lang,
                    'posted_at' => $post->createdAt,
                    'primary_url' => $post->bestUrl(),
                    'like_count' => $post->likeCount,
                    'repost_count' => $post->repostCount,
                    'reply_count' => $post->replyCount,
                    'quote_count' => $post->quoteCount,
                    'metrics_updated_at' => $now,
                    'raw_payload' => json_encode($post->rawPayload),
                    'status' => 'raw',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $inserted = $rows === []
                ? 0
                : DB::table('tweets')->insertOrIgnore($rows);

            return new StoreResult(
                postsStored: $inserted,
                duplicates: count($batch->posts) - $inserted,
                authorsStored: $authorsStored,
                newestStoredId: $this->newestId($batch->posts),
            );
        });
    }

    public function highestSeenId(int $searchQueryId): ?string
    {
        // Read from the ledger, not by scanning tweets. Scanning is O(posts)
        // per query per cycle -- verified by EXPLAIN as a full sequential scan
        // plus sort -- while the ledger holds one row per run.
        //
        // Only completed runs advance the mark, so a run whose storage failed
        // leaves its window to be retried rather than silently skipped.
        //
        // Ordering is NUMERIC, not lexicographic: '999' sorts above '1000' as
        // text, which would make the incremental window go backwards.
        $value = DB::table('search_runs')
            ->where('search_query_id', $searchQueryId)
            ->where('status', 'completed')
            ->whereNotNull('max_id_seen')
            ->orderByRaw('max_id_seen::numeric DESC')
            ->value('max_id_seen');

        return $value === null ? null : (string) $value;
    }

    private function storeAuthors(PostBatch $batch): int
    {
        if ($batch->authors === []) {
            return 0;
        }

        $now = now();
        $rows = [];

        foreach ($batch->authors as $author) {
            $rows[] = [
                'x_author_id' => $author->id,
                'username' => $author->username,
                'display_name' => $author->displayName,
                'verified' => $author->verified,
                'followers_count' => $author->followersCount,
                'first_seen_at' => $now,
                'last_fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Authors are refreshed, not ignored: follower counts and display
        // names change, and the author cache exists precisely so these are
        // not re-purchased. first_seen_at is deliberately not updated.
        DB::table('authors')->upsert(
            $rows,
            ['x_author_id'],
            ['username', 'display_name', 'verified', 'followers_count', 'last_fetched_at', 'updated_at'],
        );

        return count($rows);
    }

    /** @return array<string, int> provider author id => local id */
    private function authorIdMap(PostBatch $batch): array
    {
        if ($batch->authors === []) {
            return [];
        }

        return DB::table('authors')
            ->whereIn('x_author_id', array_keys($batch->authors))
            ->pluck('id', 'x_author_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @param list<RawPost> $posts */
    private function newestId(array $posts): ?string
    {
        $newest = null;

        foreach ($posts as $post) {
            if ($newest === null || strlen($post->id) > strlen($newest)
                || (strlen($post->id) === strlen($newest) && $post->id > $newest)) {
                $newest = $post->id;
            }
        }

        return $newest;
    }
}
