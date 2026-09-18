<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Published projects. This is the read model the API serves.
 *
 * The read path queries this table and nothing else, which is what makes a
 * user request structurally incapable of reaching a paid provider.
 *
 * score is denormalised onto the row rather than joined from project_metrics
 * at read time. Ranking is the hot query and a join per page load to find the
 * latest metric row would be the obvious way to make the feed slow.
 *
 * url_hash is unique: one project per canonical URL. PostgreSQL permits
 * multiple NULLs in a unique index, so projects without a resolvable URL are
 * still insertable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            // The post this project was extracted from. Unique: a tweet
            // yields at most one project. Additional posts about the same
            // project attach as duplicates on the tweets table.
            $table->foreignId('primary_tweet_id')
                ->unique()
                ->constrained('tweets')
                ->cascadeOnDelete();

            $table->foreignId('repository_id')
                ->nullable()
                ->constrained('repositories')
                ->nullOnDelete();

            $table->string('slug', 160)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();

            $table->string('category', 32);

            $table->text('primary_url');
            $table->text('canonical_url')->nullable();
            $table->string('url_hash', 64)->nullable()->unique();

            // When the source post was made -- drives the rolling window.
            $table->timestampTz('discovered_at');
            $table->timestampTz('published_at')->useCurrent();

            $table->decimal('score', 8, 5)->default(0);
            $table->timestampTz('score_updated_at')->nullable();

            // Component breakdown, so a ranking can be explained rather than
            // just asserted.
            $table->jsonb('score_breakdown')->nullable();

            // Admin override. The feed's quality floor.
            $table->boolean('is_visible')->default(true);
            $table->timestampTz('admin_override_at')->nullable();
            $table->string('admin_override_reason', 255)->nullable();

            $table->timestampTz('aged_out_at')->nullable();

            $table->timestampsTz();

            $table->index('discovered_at', 'projects_discovered_at_index');
            $table->index('published_at', 'projects_published_at_index');
            $table->index('category', 'projects_category_index');
            $table->index('score_updated_at', 'projects_score_updated_at_index');
        });

        DB::statement("
            ALTER TABLE projects
            ADD CONSTRAINT projects_category_allowed
            CHECK (category IN (
                'ai-ml','devtools','web','mobile','infra',
                'security','data','open-source','other'
            ))
        ");

        DB::statement("
            ALTER TABLE projects
            ADD CONSTRAINT projects_score_non_negative
            CHECK (score >= 0)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
