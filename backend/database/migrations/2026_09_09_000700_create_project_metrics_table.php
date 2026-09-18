<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical metric snapshots. Append-only time series.
 *
 * Engagement matures across the seven-day window, so a two-hour-old post must
 * not be permanently outranked by a six-day-old one. The rescore sweep writes
 * a row here each time it runs, which gives three things:
 *
 *   - a trajectory (is this project accelerating or flat)
 *   - an audit trail for why a ranking changed
 *   - the ability to recompute a past ranking after a formula change
 *
 * There is no updated_at: rows are never modified. A snapshot that gets edited
 * is not a snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_metrics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->timestampTz('captured_at')->useCurrent();

            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('repost_count')->default(0);
            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('quote_count')->default(0);

            // Engagement divided by the author's typical engagement. A
            // 200-like post from a 500-follower account outranks 200 likes
            // from a 500k account, and this is the column that encodes it.
            $table->decimal('engagement_normalized', 10, 6)->nullable();

            $table->unsignedInteger('repository_stars')->nullable();

            // The score this snapshot produced.
            $table->decimal('score', 8, 5)->nullable();

            $table->timestampTz('created_at')->useCurrent();

            // One snapshot per project per instant. Also makes a re-run of the
            // sweep idempotent rather than duplicating history.
            $table->unique(['project_id', 'captured_at'], 'project_metrics_project_captured_unique');

            $table->index(['project_id', 'captured_at'], 'project_metrics_project_captured_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_metrics');
    }
};
