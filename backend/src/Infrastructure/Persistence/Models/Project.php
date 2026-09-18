<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The read model. Everything the public API serves comes from here.
 */
class Project extends Model
{
    protected $table = 'projects';

    protected $fillable = [
        'primary_tweet_id', 'repository_id', 'slug', 'name', 'description',
        'category', 'primary_url', 'canonical_url', 'url_hash',
        'discovered_at', 'published_at', 'score', 'score_updated_at',
        'score_breakdown', 'is_visible', 'admin_override_at',
        'admin_override_reason', 'aged_out_at',
    ];

    protected $casts = [
        'discovered_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'score_updated_at' => 'immutable_datetime',
        'admin_override_at' => 'immutable_datetime',
        'aged_out_at' => 'immutable_datetime',
        'score' => 'decimal:5',
        'score_breakdown' => 'array',
        'is_visible' => 'boolean',
    ];

    public function primaryTweet(): BelongsTo
    {
        return $this->belongsTo(Tweet::class, 'primary_tweet_id');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ProjectMetric::class);
    }

    public function technologies(): BelongsToMany
    {
        return $this->belongsToMany(Technology::class, 'project_technology');
    }
}
