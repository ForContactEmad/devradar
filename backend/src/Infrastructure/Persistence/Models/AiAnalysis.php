<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single classification decision, stamped with what produced it.
 *
 * Re-analysis inserts a new row and clears is_current on the old one. A
 * partial unique index guarantees at most one current row per tweet.
 */
class AiAnalysis extends Model
{
    protected $table = 'ai_analyses';

    protected $fillable = [
        'tweet_id', 'provider', 'model', 'model_version', 'prompt_version',
        'is_launch', 'confidence', 'category', 'extracted_name',
        'extracted_description', 'extracted_url', 'technologies',
        'raw_response', 'input_tokens', 'output_tokens', 'cost_usd',
        'is_current', 'analyzed_at',
    ];

    protected $casts = [
        'is_launch' => 'boolean',
        'confidence' => 'decimal:3',
        'technologies' => 'array',
        'raw_response' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_usd' => 'decimal:6',
        'is_current' => 'boolean',
        'analyzed_at' => 'immutable_datetime',
    ];

    public function tweet(): BelongsTo
    {
        return $this->belongsTo(Tweet::class);
    }
}
