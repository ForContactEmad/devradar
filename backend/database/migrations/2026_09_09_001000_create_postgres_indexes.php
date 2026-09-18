<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-specific indexes.
 *
 * Kept in their own migration because Blueprint cannot express partial
 * indexes, GIN indexes, operator classes or expression indexes. Putting them
 * here keeps the table migrations readable and makes the PostgreSQL
 * dependency explicit in one place rather than scattered.
 *
 * These are the indexes that justify choosing PostgreSQL over MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // --- deduplication ------------------------------------------------
        // Trigram index for near-duplicate matching that hashing misses:
        // the same project announced with slightly different wording.
        DB::statement('
            CREATE INDEX tweets_text_trgm_index
            ON tweets USING gin (text gin_trgm_ops)
        ');

        // --- incremental processing ---------------------------------------
        // Each stage claims rows in its input state. Partial indexes stay
        // small because they only cover the rows still in flight, not the
        // published archive.
        DB::statement("
            CREATE INDEX tweets_unprocessed_index
            ON tweets (posted_at)
            WHERE status IN ('raw','normalized','deduplicated','filtered','pending_classification')
        ");

        // Compliance sweep: reconcile rows never checked, or checked longest ago.
        DB::statement('
            CREATE INDEX tweets_compliance_pending_index
            ON tweets (compliance_checked_at NULLS FIRST)
            WHERE purged_at IS NULL
        ');

        // --- raw payload ---------------------------------------------------
        // Lets a mapping bug be diagnosed against stored payloads without
        // re-fetching. jsonb_path_ops is smaller and faster than the default
        // for containment queries, which is all this is used for.
        DB::statement('
            CREATE INDEX tweets_raw_payload_index
            ON tweets USING gin (raw_payload jsonb_path_ops)
        ');

        // --- ranking -------------------------------------------------------
        // The hot query: visible projects in the window, best first, filtered
        // by category. Partial on is_visible because hidden projects are never
        // ranked and should not bloat the index.
        DB::statement('
            CREATE INDEX projects_ranking_index
            ON projects (category, score DESC, discovered_at DESC)
            WHERE is_visible AND aged_out_at IS NULL
        ');

        // Same query without a category filter.
        DB::statement('
            CREATE INDEX projects_ranking_all_index
            ON projects (score DESC, discovered_at DESC)
            WHERE is_visible AND aged_out_at IS NULL
        ');

        // --- search --------------------------------------------------------
        // Full-text search over the read model. Native FTS is the reason this
        // project needs no separate search engine.
        DB::statement("
            CREATE INDEX projects_fulltext_index
            ON projects USING gin (
                to_tsvector('english', coalesce(name,'') || ' ' || coalesce(description,''))
            )
        ");

        // Fuzzy name matching for admin lookup and project entity resolution.
        DB::statement('
            CREATE INDEX projects_name_trgm_index
            ON projects USING gin (name gin_trgm_ops)
        ');

        // --- enrichment queue ----------------------------------------------
        // Never-fetched repositories first, then the stalest.
        DB::statement('
            CREATE INDEX repositories_enrichment_queue_index
            ON repositories (fetched_at NULLS FIRST)
            WHERE fetch_failed_count < 5
        ');
    }

    public function down(): void
    {
        foreach ([
            'tweets_text_trgm_index',
            'tweets_unprocessed_index',
            'tweets_compliance_pending_index',
            'tweets_raw_payload_index',
            'projects_ranking_index',
            'projects_ranking_all_index',
            'projects_fulltext_index',
            'projects_name_trgm_index',
            'repositories_enrichment_queue_index',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};
