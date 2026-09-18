<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source posts. The pipeline's working table.
 *
 * DEDUPLICATION happens at three levels, cheapest first:
 *   1. x_tweet_id      - unique constraint. The same post is never stored twice,
 *                        which is also what stops a retry from re-paying.
 *   2. url_hash        - sha256 of the canonical URL. Five accounts announcing
 *                        the same repository collapse to one project.
 *   3. text_fingerprint- sha256 of normalised text, plus a trigram index on
 *                        text for near-duplicate matching that hashing misses.
 *
 * URLs are hashed rather than indexed directly because a btree entry is capped
 * at roughly 2700 bytes and tracking-laden URLs exceed it.
 *
 * Hash columns are varchar, NOT char. PostgreSQL compares a char(n) column
 * against a text value by casting the column to text, which silently defeats
 * the index -- every dedup lookup would seq-scan unless the caller remembered
 * an explicit ::char(64) cast. Verified by EXPLAIN; see the phase report.
 *
 * INCREMENTAL PROCESSING is driven by status + processed_at. Each stage claims
 * rows in its input state, so a crash mid-stage costs only the unprocessed
 * remainder -- and never a re-fetch, since only ingestion spends money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tweets', function (Blueprint $table) {
            $table->id();

            // Level 1 deduplication.
            $table->string('x_tweet_id', 32)->unique();

            $table->foreignId('author_id')
                ->constrained('authors')
                ->cascadeOnDelete();

            // Which run first found this post. SET NULL so pruning old run
            // rows never destroys the posts they discovered.
            $table->foreignId('search_run_id')
                ->nullable()
                ->constrained('search_runs')
                ->nullOnDelete();

            $table->text('text');
            $table->string('lang', 8)->nullable();

            // The seven-day window pivots on this, not on created_at.
            $table->timestampTz('posted_at');

            $table->text('primary_url')->nullable();
            $table->text('canonical_url')->nullable();

            // Level 2 and 3 deduplication keys.
            $table->string('url_hash', 64)->nullable();
            $table->string('text_fingerprint', 64)->nullable();

            // Pipeline state machine. Mirrors DevRadar\Domain\Candidate\CandidateStatus.
            $table->string('status', 32)->default('raw');

            // Free-rule or classifier rejection reason. Kept, never deleted:
            // rejection data is how pre-filter rules get written.
            $table->string('reject_reason', 32)->nullable();

            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('repost_count')->default(0);
            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('quote_count')->default(0);
            $table->timestampTz('metrics_updated_at')->nullable();

            // Verbatim provider payload. Stored unmodified so any mapping bug
            // is repairable without re-fetching.
            $table->jsonb('raw_payload');

            // Self-reference: the surviving row of a duplicate group.
            $table->foreignId('duplicate_of_tweet_id')
                ->nullable()
                ->constrained('tweets')
                ->nullOnDelete();

            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('compliance_checked_at')->nullable();
            $table->timestampTz('purged_at')->nullable();

            $table->timestampsTz();

            $table->index('posted_at', 'tweets_posted_at_index');
            $table->index(['status', 'posted_at'], 'tweets_status_posted_at_index');
            $table->index('url_hash', 'tweets_url_hash_index');
            $table->index('text_fingerprint', 'tweets_text_fingerprint_index');
            $table->index(['author_id', 'posted_at'], 'tweets_author_posted_at_index');
            $table->index('compliance_checked_at', 'tweets_compliance_checked_at_index');
        });

        DB::statement("
            ALTER TABLE tweets
            ADD CONSTRAINT tweets_status_allowed
            CHECK (status IN (
                'raw','normalized','deduplicated','filtered',
                'pending_classification','classified','rejected',
                'published','aged_out','purged'
            ))
        ");

        // A row cannot be its own duplicate parent.
        DB::statement("
            ALTER TABLE tweets
            ADD CONSTRAINT tweets_not_self_duplicate
            CHECK (duplicate_of_tweet_id IS NULL OR duplicate_of_tweet_id <> id)
        ");

        // A rejection must say why. Enforced here because the reject reason is
        // the raw material for free pre-filter rules; letting it be null makes
        // the rejection data worthless.
        DB::statement("
            ALTER TABLE tweets
            ADD CONSTRAINT tweets_rejected_has_reason
            CHECK (status <> 'rejected' OR reject_reason IS NOT NULL)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('tweets');
    }
};
