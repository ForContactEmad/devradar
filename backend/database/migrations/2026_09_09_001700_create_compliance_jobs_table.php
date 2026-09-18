<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch compliance jobs submitted to the provider.
 *
 * The pre-signed URLs are stored because there is no way to derive them
 * again: losing them means losing the results, and the results name content
 * we are legally required to stop displaying.
 *
 * `id_count` rather than the IDs themselves. Storing a thousand ids per job
 * would duplicate the tweets table for no gain -- what matters is whether the
 * job was collected, and which posts it named comes from the results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_jobs', function (Blueprint $table) {
            $table->id();

            $table->string('provider_job_id', 64)->nullable()->unique();

            // `state` rather than `status`: a job is created locally before
            // the provider has one, so `pending` is a state we own and the
            // provider does not report.
            $table->string('state', 24)->default('pending');

            // Pre-signed and time-limited. Never logged: they carry their own
            // credentials in the query string.
            $table->text('upload_url')->nullable();
            $table->text('download_url')->nullable();

            $table->timestampTz('upload_expires_at')->nullable();
            $table->timestampTz('download_expires_at')->nullable();

            $table->unsignedInteger('id_count')->default(0);

            $table->timestampTz('collected_at')->nullable();
            $table->text('error')->nullable();

            $table->timestampsTz();

            $table->index(['state', 'created_at'], 'compliance_jobs_state_created_index');
        });

        DB::statement("
            ALTER TABLE compliance_jobs
            ADD CONSTRAINT compliance_jobs_status_allowed
            CHECK (state IN ('pending','created','in_progress','complete','failed','expired'))
        ");

        // Jobs still awaiting results. Partial, so it covers only what is in
        // flight rather than the archive.
        DB::statement("
            CREATE INDEX compliance_jobs_open_index
            ON compliance_jobs (created_at)
            WHERE collected_at IS NULL AND state NOT IN ('failed','expired')
        ");

        // Drives the reconciliation queue: least-recently-checked first.
        Schema::table('tweets', function (Blueprint $table) {
            $table->index('compliance_checked_at', 'tweets_compliance_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('tweets', function (Blueprint $table) {
            $table->dropIndex('tweets_compliance_due_index');
        });

        Schema::dropIfExists('compliance_jobs');
    }
};
