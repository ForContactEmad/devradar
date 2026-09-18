<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the pre-filter's preliminary score.
 *
 * The breakdown is kept, not just the total. Signal sets are tuned
 * continuously, and without the breakdown there is no way to answer why a
 * post was filtered out three weeks ago under a signal set that has since
 * changed.
 *
 * This is NOT the ranking score. That lives on `projects` and is computed
 * from engagement and credibility. Keeping them in different tables makes it
 * hard to confuse a cheap keyword estimate with the feed's actual order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tweets', function (Blueprint $table) {
            $table->smallInteger('signal_score')->nullable()->after('reject_reason');
            $table->string('signal_strength', 16)->nullable()->after('signal_score');
            $table->jsonb('signal_breakdown')->nullable()->after('signal_strength');
        });

        DB::statement("
            ALTER TABLE tweets
            ADD CONSTRAINT tweets_signal_strength_allowed
            CHECK (signal_strength IS NULL OR signal_strength IN ('strong','medium','weak','none'))
        ");

        // The filter queue: deduplicated posts awaiting a decision. Partial,
        // so the index covers only posts still in flight rather than the
        // whole archive.
        DB::statement("
            CREATE INDEX tweets_awaiting_filter_index
            ON tweets (posted_at)
            WHERE status = 'deduplicated'
        ");

        // Lets the admin panel answer 'what is the filter rejecting, and how
        // close to the threshold was it' without a sequential scan.
        DB::statement("
            CREATE INDEX tweets_signal_strength_index
            ON tweets (signal_strength, signal_score)
            WHERE signal_strength IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tweets_signal_strength_index');
        DB::statement('DROP INDEX IF EXISTS tweets_awaiting_filter_index');
        DB::statement('ALTER TABLE tweets DROP CONSTRAINT IF EXISTS tweets_signal_strength_allowed');

        Schema::table('tweets', function (Blueprint $table) {
            $table->dropColumn(['signal_score', 'signal_strength', 'signal_breakdown']);
        });
    }
};
