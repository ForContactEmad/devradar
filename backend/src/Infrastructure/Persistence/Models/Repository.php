<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for a source repository linked to one or more projects.
 *
 * Kept for relationship navigation only; enrichment reads and writes through
 * DB::table().
 */
class Repository extends Model
{
    protected $table = 'repositories';

    protected $fillable = [
        'host', 'owner', 'name', 'url', 'stars', 'forks', 'open_issues',
        'primary_language', 'license', 'pushed_at', 'repo_created_at',
        'fetched_at', 'fetch_failed_count',
    ];

    protected $casts = [
        'stars' => 'integer',
        'forks' => 'integer',
        'open_issues' => 'integer',
        'pushed_at' => 'immutable_datetime',
        'repo_created_at' => 'immutable_datetime',
        'fetched_at' => 'immutable_datetime',
        'fetch_failed_count' => 'integer',
    ];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
