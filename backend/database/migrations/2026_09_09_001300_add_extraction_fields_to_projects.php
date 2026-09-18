<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the structured fields extraction produces.
 *
 * The three URL columns are kept apart rather than folded into one JSON blob
 * because each is filtered and displayed differently: the repository is the
 * primary destination, the website is context, the demo is the thing a reader
 * clicks first. A blob would make "projects with a live demo" a scan.
 *
 * extraction_confidence is SEPARATE from the classifier's confidence. One is
 * "is this a launch", the other is "did we read the details correctly", and a
 * project can score high on the first and low on the second.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('project_type', 32)->nullable()->after('category');
            $table->text('repository_url')->nullable()->after('canonical_url');
            $table->text('website_url')->nullable()->after('repository_url');
            $table->text('demo_url')->nullable()->after('website_url');
            $table->text('source_post_url')->nullable()->after('demo_url');
            $table->decimal('extraction_confidence', 4, 3)->nullable()->after('source_post_url');
        });

        DB::statement('
            ALTER TABLE projects
            ADD CONSTRAINT projects_extraction_confidence_range
            CHECK (extraction_confidence IS NULL
                   OR (extraction_confidence >= 0 AND extraction_confidence <= 1))
        ');

        Schema::table('project_technology', function (Blueprint $table) {
            // WHY this technology was attached. "Do not invent technologies
            // that are not supported by evidence" is only enforceable if the
            // evidence survives; otherwise a detected tag and a hallucinated
            // one are indistinguishable after the fact.
            $table->string('source', 24)->nullable();
            $table->string('evidence', 64)->nullable();
        });

        DB::statement("
            ALTER TABLE project_technology
            ADD CONSTRAINT project_technology_source_allowed
            CHECK (source IS NULL OR source IN ('post_text','url','model_confirmed'))
        ");

        // Browsing by project type is a filter facet, same as category.
        DB::statement('
            CREATE INDEX projects_type_index
            ON projects (project_type, score DESC)
            WHERE is_visible AND aged_out_at IS NULL
        ');

        // "Projects with a repository" is a common filter and should not scan.
        DB::statement('
            CREATE INDEX projects_has_repository_index
            ON projects (score DESC)
            WHERE repository_url IS NOT NULL AND is_visible
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS projects_has_repository_index');
        DB::statement('DROP INDEX IF EXISTS projects_type_index');
        DB::statement('ALTER TABLE project_technology DROP CONSTRAINT IF EXISTS project_technology_source_allowed');
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_extraction_confidence_range');

        Schema::table('project_technology', function (Blueprint $table) {
            $table->dropColumn(['source', 'evidence']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'project_type', 'repository_url', 'website_url',
                'demo_url', 'source_post_url', 'extraction_confidence',
            ]);
        });
    }
};
