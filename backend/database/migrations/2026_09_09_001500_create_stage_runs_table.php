<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operational record of every pipeline stage execution.
 *
 * WHY A TABLE AND NOT JUST LOGS. Logs answer "what happened at 3am" if you
 * know to look. A table answers "is the pipeline healthy", "which stage is
 * slowest", and "when did classification last succeed" as queries -- which is
 * what an admin panel and an alert both need.
 *
 * Deliberately narrow: counts and timings, not payloads. This table will have
 * the most rows in the system and must stay cheap to write and prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_runs', function (Blueprint $table) {
            $table->id();

            $table->string('stage', 32);

            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();

            // running | succeeded | failed | skipped | abandoned
            $table->string('status', 16)->default('running');

            $table->unsignedInteger('duration_ms')->nullable();

            // Per-stage counters, whatever the runner returned. Schemaless
            // because each stage counts different things and a column per
            // counter would be a migration per tuning change.
            $table->jsonb('stats')->nullable();

            $table->text('error')->nullable();

            $table->timestampsTz();

            $table->index(['stage', 'started_at'], 'stage_runs_stage_started_index');
            $table->index('started_at', 'stage_runs_started_index');
        });

        DB::statement("
            ALTER TABLE stage_runs
            ADD CONSTRAINT stage_runs_status_allowed
            CHECK (status IN ('running','succeeded','failed','skipped','abandoned'))
        ");

        DB::statement('
            ALTER TABLE stage_runs
            ADD CONSTRAINT stage_runs_finished_after_started
            CHECK (finished_at IS NULL OR finished_at >= started_at)
        ');

        // Finding runs stuck in 'running' after a worker died. Partial, so it
        // indexes only the handful of rows in flight rather than the archive.
        DB::statement("
            CREATE INDEX stage_runs_in_flight_index
            ON stage_runs (started_at)
            WHERE status = 'running'
        ");

        // "When did each stage last succeed" -- the health question.
        DB::statement("
            CREATE INDEX stage_runs_last_success_index
            ON stage_runs (stage, finished_at DESC)
            WHERE status = 'succeeded'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_runs');
    }
};
