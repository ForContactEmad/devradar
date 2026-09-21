<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Eloquent model for a technology (a language, framework or tool).
 *
 * Kept for relationship navigation only. The repositories query with
 * DB::table() rather than through models, so this is not on any hot path.
 */
class Technology extends Model
{
    protected $table = 'technologies';

    protected $fillable = ['slug', 'name', 'kind'];

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_technology');
    }
}
