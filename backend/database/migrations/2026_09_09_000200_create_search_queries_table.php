<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The versioned query set.
 *
 * Queries are business DATA, not configuration: they change constantly during
 * tuning and their version is stamped onto every run so past results stay
 * explainable. Editing one creates a new version rather than mutating the old,
 * which is why (name, version) is the unique key and not name alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->unsignedSmallInteger('version')->default(1);

            // Query family from docs/query-set-v1.md: A-F, or 'curated' for
            // the from: account list.
            $table->string('family', 16);

            $table->text('expression');
            $table->unsignedSmallInteger('max_results')->default(100);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->unique(['name', 'version'], 'search_queries_name_version_unique');

            // Scheduler asks only for active queries.
            $table->index(['is_active', 'family'], 'search_queries_active_family_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');
    }
};
