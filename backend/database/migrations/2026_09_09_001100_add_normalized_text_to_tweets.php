<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the derived, readable form of a post's text.
 *
 * Kept ALONGSIDE `text`, never in place of it. The original stays exactly as
 * the provider returned it, here and in raw_payload, because normalization
 * rules will change and every derived column has to be recomputable from
 * something that did not.
 *
 * Nullable: a post is collected before it is normalized, and the column stays
 * null until the normalization stage reaches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tweets', function (Blueprint $table) {
            $table->text('normalized_text')->nullable()->after('text');

            // Which deduplication level matched, when this row is a duplicate.
            // Recorded because "the same post arrived twice" and "five
            // accounts announced one project" are completely different
            // findings and both show up as a duplicate without it.
            $table->string('duplicate_match_level', 24)->nullable()->after('duplicate_of_tweet_id');
        });

        DB::statement("
            ALTER TABLE tweets
            ADD CONSTRAINT tweets_duplicate_level_allowed
            CHECK (duplicate_match_level IS NULL OR duplicate_match_level IN
                ('tweet_id','canonical_url','text_fingerprint'))
        ");

        // A duplicate must say how it was matched; a non-duplicate must not
        // claim a match level.
        DB::statement('
            ALTER TABLE tweets
            ADD CONSTRAINT tweets_duplicate_level_consistent
            CHECK ((duplicate_of_tweet_id IS NULL) = (duplicate_match_level IS NULL))
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tweets DROP CONSTRAINT IF EXISTS tweets_duplicate_level_consistent');
        DB::statement('ALTER TABLE tweets DROP CONSTRAINT IF EXISTS tweets_duplicate_level_allowed');

        Schema::table('tweets', function (Blueprint $table) {
            $table->dropColumn(['normalized_text', 'duplicate_match_level']);
        });
    }
};
