<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchQuery extends Model
{
    protected $table = 'search_queries';

    protected $fillable = [
        'name', 'version', 'family', 'expression',
        'max_results', 'is_active', 'notes',
    ];

    protected $casts = [
        'version' => 'integer',
        'max_results' => 'integer',
        'is_active' => 'boolean',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(SearchRun::class);
    }
}
