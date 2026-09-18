<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalises total engagement onto the project row.
 *
 * WHY. Sorting or filtering by engagement previously summed three columns on
 * `tweets` at query time, which meant a hash join of both tables and a sort
 * of every row before the LIMIT could apply. Measured at 40,000 projects:
 * two sequential scans, 40,000 rows joined, top-N heapsort. That is the shape
 * that gets slower every week the feed runs.
 *
 * The number is already denormalised in spirit -- `score` lives here for the
 * same reason -- and the rescore sweep recomputes both together, so there is
 * no new place for it to go stale.
 *
 * Quote count is included where the sort expression omitted it. A quote is a
 * repost with commentary and counts at least as much as a bare reply; leaving
 * it out was an inconsistency between the sort and the scoring engine, which
 * has always weighted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('engagement_count')->default(0)->after('score');
        });

        // Backfill from the source posts so the column is correct before
        // anything reads it, rather than only after the next rescore.
        DB::statement('
            UPDATE projects SET engagement_count = coalesce((
                SELECT t.like_count + t.repost_count + t.reply_count + t.quote_count
                FROM tweets t WHERE t.id = projects.primary_tweet_id
            ), 0)
        ');

        // The engagement ordering, partial on the same predicate as the other
        // ranking indexes so it covers only rows that can appear in a feed.
        DB::statement('
            CREATE INDEX projects_engagement_index
            ON projects (engagement_count DESC, id DESC)
            WHERE is_visible AND aged_out_at IS NULL
        ');

        // Filtering by a minimum engagement, combined with the default score
        // ordering. Without this a "popular projects" filter scans.
        DB::statement('
            CREATE INDEX projects_engagement_score_index
            ON projects (engagement_count, score DESC)
            WHERE is_visible AND aged_out_at IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS projects_engagement_score_index');
        DB::statement('DROP INDEX IF EXISTS projects_engagement_index');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('engagement_count');
        });
    }
};
