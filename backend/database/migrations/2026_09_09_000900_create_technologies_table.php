<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technology tags, normalised rather than stored as a JSON array on projects.
 *
 * Technology filtering is a stated requirement, and "show me every Rust
 * project" against a jsonb array cannot use a plain btree index. A pivot table
 * makes that query an indexed join, and it also keeps the tag vocabulary
 * controlled -- "postgres", "PostgreSQL" and "psql" resolve to one row instead
 * of three unfilterable strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('technologies', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 64);
            $table->string('kind', 32)->nullable(); // language | framework | database | tool
            $table->timestampsTz();
        });

        Schema::create('project_technology', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();

            $table->foreignId('technology_id')
                ->constrained('technologies')
                ->cascadeOnDelete();

            $table->primary(['project_id', 'technology_id']);

            // Reverse lookup: every project using a given technology.
            $table->index('technology_id', 'project_technology_technology_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_technology');
        Schema::dropIfExists('technologies');
    }
};
