<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The spend ledger. One row per ingestion execution.
 */
class SearchRun extends Model
{
    protected $table = 'search_runs';

    protected $fillable = [
        'search_query_id', 'started_at', 'finished_at', 'status',
        'since_id', 'max_id_seen', 'posts_returned', 'posts_new',
        'billable_resources', 'cost_usd', 'error_class', 'error_message',
    ];

    protected $casts = [
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
        'posts_returned' => 'integer',
        'posts_new' => 'integer',
        'billable_resources' => 'integer',
        // decimal, not float: this is money and it is reported on.
        'cost_usd' => 'decimal:5',
    ];

    public function searchQuery(): BelongsTo
    {
        return $this->belongsTo(SearchQuery::class);
    }

    public function tweets(): HasMany
    {
        return $this->hasMany(Tweet::class);
    }
}
