<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the fields GitHub enrichment produces.
 *
 * `etag` is the important one. Storing it turns every refresh into a
 * conditional request, and a conditional request that comes back 304 costs no
 * rate-limit quota when authenticated. Without it, refreshing 500 repositories
 * twice a day spends 1,000 of the 5,000 hourly budget; with it, only the ones
 * that actually changed do.
 *
 * `gone_at` is deliberately separate from `fetch_failed_count`. A deleted or
 * private repository is permanently unavailable and must leave the queue,
 * while a 500 is worth retrying. Counting both towards one number would
 * eventually blacklist repositories that were only briefly unreachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->unsignedInteger('contributors_count')->nullable()->after('open_issues');
            $table->jsonb('topics')->nullable()->after('license');
            $table->text('description')->nullable()->after('topics');
            $table->string('default_branch', 128)->nullable()->after('description');
            $table->boolean('is_archived')->default(false)->after('default_branch');
            $table->boolean('is_fork')->default(false)->after('is_archived');

            // Conditional-request token. Bounded generously: GitHub's ETags
            // are short, but weak validators carry a W/ prefix.
            $table->string('etag', 128)->nullable()->after('fetched_at');

            $table->timestampTz('gone_at')->nullable()->after('fetch_failed_count');
            $table->string('gone_reason', 255)->nullable()->after('gone_at');
            $table->text('last_error')->nullable()->after('gone_reason');
        });

        // The enrichment queue: never-fetched first, then stalest, excluding
        // repositories that are gone or have failed too often. Partial so the
        // index covers only what is actually eligible.
        DB::statement('
            CREATE INDEX repositories_enrichment_due_index
            ON repositories (fetched_at NULLS FIRST)
            WHERE gone_at IS NULL AND fetch_failed_count < 5
        ');

        // Topic filtering on the dashboard is a containment query.
        DB::statement('
            CREATE INDEX repositories_topics_index
            ON repositories USING gin (topics jsonb_path_ops)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS repositories_topics_index');
        DB::statement('DROP INDEX IF EXISTS repositories_enrichment_due_index');

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn([
                'contributors_count', 'topics', 'description', 'default_branch',
                'is_archived', 'is_fork', 'etag', 'gone_at', 'gone_reason', 'last_error',
            ]);
        });
    }
};
