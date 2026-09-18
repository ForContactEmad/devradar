<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only metric snapshot.
 *
 * UPDATED_AT is disabled: a snapshot that can be edited is not a snapshot.
 */
class ProjectMetric extends Model
{
    protected $table = 'project_metrics';

    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id', 'captured_at', 'like_count', 'repost_count',
        'reply_count', 'quote_count', 'engagement_normalized',
        'repository_stars', 'score',
    ];

    protected $casts = [
        'captured_at' => 'immutable_datetime',
        'like_count' => 'integer',
        'repost_count' => 'integer',
        'reply_count' => 'integer',
        'quote_count' => 'integer',
        'engagement_normalized' => 'decimal:6',
        'repository_stars' => 'integer',
        'score' => 'decimal:5',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
