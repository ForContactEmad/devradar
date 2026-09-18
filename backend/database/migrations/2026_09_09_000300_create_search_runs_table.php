<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per ingestion execution. This is the spend ledger.
 *
 * since_id is what makes ingestion incremental: each run records the highest
 * post id it saw, and the next run starts there instead of re-fetching (and
 * re-paying for) the same window.
 *
 * cost_usd is recorded here rather than derived at read time because provider
 * pricing changes. A run's cost is what it cost on the day it ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_runs', function (Blueprint $table) {
            $table->id();

            // RESTRICT, not CASCADE: deleting a query must never silently
            // erase the spend history attributable to it.
            $table->foreignId('search_query_id')
                ->constrained('search_queries')
                ->restrictOnDelete();

            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();

            // running | completed | failed | aborted_budget
            $table->string('status', 24)->default('running');

            $table->string('since_id', 32)->nullable();
            $table->string('max_id_seen', 32)->nullable();

            $table->unsignedInteger('posts_returned')->default(0);
            $table->unsignedInteger('posts_new')->default(0);
            $table->unsignedInteger('billable_resources')->default(0);
            $table->decimal('cost_usd', 10, 5)->default(0);

            // transient | permanent | budget | data | compliance
            $table->string('error_class', 24)->nullable();
            $table->text('error_message')->nullable();

            $table->timestampsTz();

            $table->index('started_at', 'search_runs_started_at_index');
            $table->index(['status', 'started_at'], 'search_runs_status_started_index');
            $table->index(['search_query_id', 'started_at'], 'search_runs_query_started_index');
        });

        // A run is either finished or it is not; a finish time before a start
        // time means the ledger is lying about duration.
        DB::statement("
            ALTER TABLE search_runs
            ADD CONSTRAINT search_runs_finished_after_started
            CHECK (finished_at IS NULL OR finished_at >= started_at)
        ");

        DB::statement("
            ALTER TABLE search_runs
            ADD CONSTRAINT search_runs_status_allowed
            CHECK (status IN ('running','completed','failed','aborted_budget'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('search_runs');
    }
};
