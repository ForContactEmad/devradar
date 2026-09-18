<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns the category CHECK constraint with config/extraction.php.
 *
 * THE TWO HAD DRIFTED APART. The constraint was written with one vocabulary
 * ('ai-ml', 'devtools', 'web') and the extraction config with another ('ai',
 * 'developer-tools', 'web-app'). Every extraction producing one of the newer
 * categories would have failed on insert -- after two paid model calls had
 * already been spent on that post.
 *
 * The config vocabulary wins: it is richer, it distinguishes library from
 * framework from CLI, and it is what the extraction prompt actually asks the
 * model for. Old values are migrated rather than dropped.
 *
 * The constraint and the config still have to be kept in step by hand. A
 * lookup table would remove that risk, but it would add a join to every read
 * for a set of thirteen values that changes twice a year -- so the trade is
 * deliberate, and this comment is the reminder.
 */
return new class extends Migration
{
    private const CATEGORIES = [
        'ai', 'saas', 'open-source', 'developer-tools', 'web-app', 'mobile-app',
        'library', 'framework', 'cli', 'devops', 'data', 'security', 'other',
    ];

    private const RENAMES = [
        'ai-ml' => 'ai',
        'devtools' => 'developer-tools',
        'web' => 'web-app',
        'mobile' => 'mobile-app',
        'infra' => 'devops',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_category_allowed');

        foreach (self::RENAMES as $old => $new) {
            DB::table('projects')->where('category', $old)->update(['category' => $new]);
        }

        // Anything not in the new vocabulary becomes 'other' rather than
        // blocking the migration. Losing a category label is recoverable;
        // a migration that will not apply is not.
        DB::table('projects')->whereNotIn('category', self::CATEGORIES)->update(['category' => 'other']);

        $list = "'" . implode("','", self::CATEGORIES) . "'";

        DB::statement("
            ALTER TABLE projects
            ADD CONSTRAINT projects_category_allowed
            CHECK (category IN ({$list}))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_category_allowed');

        foreach (array_flip(self::RENAMES) as $new => $old) {
            DB::table('projects')->where('category', $new)->update(['category' => $old]);
        }

        DB::statement("
            ALTER TABLE projects
            ADD CONSTRAINT projects_category_allowed
            CHECK (category IN ('ai-ml','devtools','web','mobile','infra','security','data','open-source','other'))
        ");
    }
};
