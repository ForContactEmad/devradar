<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Source posts. Carries the pipeline state machine in `status`.
 */
class Tweet extends Model
{
    protected $table = 'tweets';

    protected $fillable = [
        'x_tweet_id', 'author_id', 'search_run_id', 'text', 'lang',
        'posted_at', 'primary_url', 'canonical_url', 'url_hash',
        'text_fingerprint', 'status', 'reject_reason',
        'like_count', 'repost_count', 'reply_count', 'quote_count',
        'metrics_updated_at', 'raw_payload', 'duplicate_of_tweet_id',
        'processed_at', 'compliance_checked_at', 'purged_at',
    ];

    protected $casts = [
        'posted_at' => 'immutable_datetime',
        'metrics_updated_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
        'compliance_checked_at' => 'immutable_datetime',
        'purged_at' => 'immutable_datetime',
        'raw_payload' => 'array',
        'like_count' => 'integer',
        'repost_count' => 'integer',
        'reply_count' => 'integer',
        'quote_count' => 'integer',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function searchRun(): BelongsTo
    {
        return $this->belongsTo(SearchRun::class);
    }

    /** The surviving row of this tweet's duplicate group. */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_tweet_id');
    }

    /** Other posts collapsed into this one. */
    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_tweet_id');
    }

    /** Every classification attempt, including superseded ones. */
    public function analyses(): HasMany
    {
        return $this->hasMany(AiAnalysis::class);
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'primary_tweet_id');
    }
}
