<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DevRadar\Domain\Collection\SearchQueryDefinition;
use DevRadar\Domain\Port\SearchQuerySourceInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reads the active query set from the database.
 *
 * The database is authoritative, not config/collection.php. Config seeds a
 * fresh install; after that the set is edited from the admin panel without a
 * deploy, because tuning queries is continuous and a deploy per experiment
 * would stop the tuning happening at all.
 *
 * Only the newest version of each named query is returned. Older versions
 * stay for attribution of past runs, never for execution.
 */
final readonly class DatabaseSearchQuerySource implements SearchQuerySourceInterface
{
    /** @return list<SearchQueryDefinition> */
    public function activeQueries(): array
    {
        $rows = DB::table('search_queries as sq')
            ->where('sq.is_active', true)
            ->whereRaw('sq.version = (
                select max(inner_sq.version) from search_queries inner_sq where inner_sq.name = sq.name
            )')
            ->orderBy('sq.family')
            ->orderBy('sq.name')
            ->get();

        $definitions = [];

        foreach ($rows as $row) {
            $definitions[] = new SearchQueryDefinition(
                id: (int) $row->id,
                name: (string) $row->name,
                family: (string) $row->family,
                expression: (string) $row->expression,
                version: (int) $row->version,
                maxResults: (int) $row->max_results,
                isActive: (bool) $row->is_active,
            );
        }

        return $definitions;
    }
}
