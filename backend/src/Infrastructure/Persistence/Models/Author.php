<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Persistence mapping only.
 *
 * Models live in Infrastructure, not Domain, because they are coupled to
 * Eloquent. They carry no business rules and no query scopes that encode
 * meaning -- repositories translate these into domain objects, and the
 * architecture guard fails the build if this namespace leaks inward.
 *
 * @property int $id
 */
class Author extends Model
{
    protected $table = 'authors';

    protected $fillable = [
        'x_author_id', 'username', 'display_name', 'followers_count',
        'verified', 'credibility_score', 'is_blocklisted',
        'first_seen_at', 'last_fetched_at',
    ];

    protected $casts = [
        'followers_count' => 'integer',
        'verified' => 'boolean',
        'credibility_score' => 'float',
        'is_blocklisted' => 'boolean',
        'first_seen_at' => 'immutable_datetime',
        'last_fetched_at' => 'immutable_datetime',
    ];

    public function tweets(): HasMany
    {
        return $this->hasMany(Tweet::class);
    }
}
