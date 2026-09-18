<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authors of source posts.
 *
 * This table is also the author cache. User reads cost twice what post reads
 * cost, so an author is fetched once and reused; last_fetched_at is what the
 * ingestion stage checks before paying for a lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->id();

            // Numeric platform id, stored as a string. Compliance events are
            // delivered by numeric id only, so this is the join key that makes
            // deletion reconciliation possible.
            $table->string('x_author_id', 32)->unique();

            $table->string('username', 64)->index();
            $table->string('display_name', 255)->nullable();
            $table->unsignedInteger('followers_count')->nullable();
            $table->boolean('verified')->default(false);

            // Populated later from historical accuracy. Null until earned.
            $table->decimal('credibility_score', 5, 4)->nullable();

            $table->boolean('is_blocklisted')->default(false);

            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestampTz('last_fetched_at')->nullable();

            $table->timestampsTz();

            // Author cache freshness sweep.
            $table->index('last_fetched_at', 'authors_last_fetched_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authors');
    }
};
