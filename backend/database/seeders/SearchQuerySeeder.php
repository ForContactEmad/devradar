<?php

declare(strict_types=1);

namespace Database\Seeders;

use DevRadar\Domain\Collection\QueryComposer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the initial query set from config/collection.php.
 *
 * This is a SEED, not a sync. Existing queries are left alone: the database
 * is authoritative once a system is running, and re-running the seeder must
 * never silently revert queries tuned through the admin panel.
 *
 * Groups too long for the provider's query-length limit are split into
 * numbered chunks by the composer, each stored as its own query so the ledger
 * can attribute cost and yield per chunk.
 */
final class SearchQuerySeeder extends Seeder
{
    public function run(): void
    {
        $config = config('collection');
        $composer = new QueryComposer((int) config('x.max_query_length', 512));
        $language = $config['language'] ?? 'en';

        $definitions = [];

        foreach ($config['groups'] as $name => $group) {
            $modifiers = array_merge(
                $group['extra_modifiers'] ?? [],
                $config['modifiers']['default'],
                ["lang:{$language}"],
            );

            $definitions = array_merge($definitions, $composer->defineGroup(
                name: $name,
                family: $group['family'],
                signals: $group['signals'],
                modifiers: $modifiers,
                maxResults: (int) $config['limits']['page_size'],
            ));
        }

        $now = now();
        $created = 0;

        foreach ($definitions as $definition) {
            $exists = DB::table('search_queries')
                ->where('name', $definition->name)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('search_queries')->insert([
                'name' => $definition->name,
                'version' => 1,
                'family' => $definition->family,
                'expression' => $definition->expression,
                'max_results' => $definition->maxResults,
                'is_active' => true,
                'notes' => 'Seeded from config/collection.php. Edit in the admin panel, not in config.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
        }

        $this->command?->info("Search query seed: {$created} created, " . (count($definitions) - $created) . ' already present.');
    }
}
