<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source-code repositories, populated by enrichment.
 *
 * Enrichment is off the critical path: a project publishes without a
 * repository row and is enriched afterwards. fetched_at drives the enrichment
 * queue (null first, then stalest), and fetch_failed_count lets a repeatedly
 * failing repository fall to the back without blocking anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();

            $table->string('host', 32);
            $table->string('owner', 128);
            $table->string('name', 128);
            $table->text('url');

            $table->unsignedInteger('stars')->nullable();
            $table->unsignedInteger('forks')->nullable();
            $table->unsignedInteger('open_issues')->nullable();
            $table->string('primary_language', 64)->nullable();
            $table->string('license', 64)->nullable();

            $table->timestampTz('pushed_at')->nullable();
            $table->timestampTz('repo_created_at')->nullable();

            $table->timestampTz('fetched_at')->nullable();
            $table->unsignedSmallInteger('fetch_failed_count')->default(0);

            $table->timestampsTz();

            $table->unique(['host', 'owner', 'name'], 'repositories_host_owner_name_unique');
            $table->index('fetched_at', 'repositories_fetched_at_index');
            $table->index('primary_language', 'repositories_primary_language_index');
        });

        DB::statement("
            ALTER TABLE repositories
            ADD CONSTRAINT repositories_host_allowed
            CHECK (host IN ('github','gitlab','bitbucket','codeberg','other'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
