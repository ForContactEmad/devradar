<?php

declare(strict_types=1);

use DevRadar\Infrastructure\Persistence\Models\AiAnalysis;
use DevRadar\Infrastructure\Persistence\Models\Author;
use DevRadar\Infrastructure\Persistence\Models\Project;
use DevRadar\Infrastructure\Persistence\Models\ProjectMetric;
use DevRadar\Infrastructure\Persistence\Models\Repository;
use DevRadar\Infrastructure\Persistence\Models\SearchQuery;
use DevRadar\Infrastructure\Persistence\Models\SearchRun;
use DevRadar\Infrastructure\Persistence\Models\Technology;
use DevRadar\Infrastructure\Persistence\Models\Tweet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Schema behaviour, exercised through Eloquent.
 *
 * These assertions mirror tests/Support/verify-schema.sh, which was written to
 * verify the same guarantees in raw SQL while Composer was unavailable. Once
 * this file runs green, delete the shell harness and the schema transpiler
 * beside it -- they exist only to cover that gap.
 */
uses(RefreshDatabase::class);

function fixture(): array
{
    $author = Author::create([
        'x_author_id' => '900001',
        'username' => 'alice',
    ]);

    $query = SearchQuery::create([
        'name' => 'family-a',
        'family' => 'A',
        'expression' => 'test expression',
    ]);

    $run = SearchRun::create(['search_query_id' => $query->id]);

    $tweet = Tweet::create([
        'x_tweet_id' => '100001',
        'author_id' => $author->id,
        'search_run_id' => $run->id,
        'text' => 'just open sourced our cli tool',
        'posted_at' => now(),
        'raw_payload' => [],
    ]);

    return compact('author', 'query', 'run', 'tweet');
}

// ------------------------------------------------------------ deduplication

it('rejects a post that has already been stored', function () {
    ['author' => $author] = fixture();

    expect(fn () => Tweet::create([
        'x_tweet_id' => '100001',
        'author_id' => $author->id,
        'text' => 'duplicate',
        'posted_at' => now(),
        'raw_payload' => [],
    ]))->toThrow(QueryException::class);
});

it('stores separate posts that share a url hash so they can be collapsed later', function () {
    ['author' => $author, 'tweet' => $tweet] = fixture();
    $hash = str_repeat('a', 64);

    $tweet->update(['url_hash' => $hash]);

    $second = Tweet::create([
        'x_tweet_id' => '100002',
        'author_id' => $author->id,
        'text' => 'same project, different account',
        'posted_at' => now(),
        'url_hash' => $hash,
        'raw_payload' => [],
    ]);

    expect(Tweet::where('url_hash', $hash)->count())->toBe(2);
    expect($second->exists)->toBeTrue();
});

it('links duplicates to a surviving row', function () {
    ['author' => $author, 'tweet' => $tweet] = fixture();

    $dup = Tweet::create([
        'x_tweet_id' => '100002',
        'author_id' => $author->id,
        'text' => 'dup',
        'posted_at' => now(),
        'duplicate_of_tweet_id' => $tweet->id,
        'raw_payload' => [],
    ]);

    expect($dup->duplicateOf->id)->toBe($tweet->id);
    expect($tweet->duplicates)->toHaveCount(1);
});

it('refuses to let a post be its own duplicate', function () {
    ['tweet' => $tweet] = fixture();

    expect(fn () => $tweet->update(['duplicate_of_tweet_id' => $tweet->id]))
        ->toThrow(QueryException::class);
});

it('allows only one project per canonical url', function () {
    ['author' => $author, 'tweet' => $tweet] = fixture();
    $hash = str_repeat('b', 64);

    $second = Tweet::create([
        'x_tweet_id' => '100002', 'author_id' => $author->id, 'text' => 'b',
        'posted_at' => now(), 'raw_payload' => [],
    ]);

    Project::create([
        'primary_tweet_id' => $tweet->id, 'slug' => 'a', 'name' => 'A',
        'category' => 'devtools', 'primary_url' => 'https://x',
        'url_hash' => $hash, 'discovered_at' => now(),
    ]);

    expect(fn () => Project::create([
        'primary_tweet_id' => $second->id, 'slug' => 'b', 'name' => 'B',
        'category' => 'devtools', 'primary_url' => 'https://y',
        'url_hash' => $hash, 'discovered_at' => now(),
    ]))->toThrow(QueryException::class);
});

// --------------------------------------------------------------- pipeline

it('rejects an unknown pipeline status', function () {
    ['tweet' => $tweet] = fixture();

    expect(fn () => $tweet->update(['status' => 'banana']))
        ->toThrow(QueryException::class);
});

it('requires a reason whenever a post is rejected', function () {
    ['tweet' => $tweet] = fixture();

    expect(fn () => $tweet->update(['status' => 'rejected']))
        ->toThrow(QueryException::class);

    $tweet->update(['status' => 'rejected', 'reject_reason' => 'hiring']);
    expect($tweet->fresh()->reject_reason)->toBe('hiring');
});

// -------------------------------------------------------------- re-analysis

it('keeps every analysis so a prompt change can be replayed', function () {
    ['tweet' => $tweet] = fixture();

    AiAnalysis::create([
        'tweet_id' => $tweet->id, 'provider' => 'anthropic', 'model' => 'm',
        'prompt_version' => 'v1', 'is_launch' => true, 'confidence' => 0.80,
        'is_current' => false,
    ]);

    AiAnalysis::create([
        'tweet_id' => $tweet->id, 'provider' => 'anthropic', 'model' => 'm',
        'prompt_version' => 'v2', 'is_launch' => true, 'confidence' => 0.91,
        'is_current' => true,
    ]);

    expect($tweet->analyses)->toHaveCount(2);
});

it('allows only one current analysis per post', function () {
    ['tweet' => $tweet] = fixture();

    AiAnalysis::create([
        'tweet_id' => $tweet->id, 'provider' => 'anthropic', 'model' => 'm',
        'prompt_version' => 'v1', 'is_current' => true,
    ]);

    expect(fn () => AiAnalysis::create([
        'tweet_id' => $tweet->id, 'provider' => 'anthropic', 'model' => 'm',
        'prompt_version' => 'v2', 'is_current' => true,
    ]))->toThrow(QueryException::class);
});

it('rejects a confidence outside zero to one', function () {
    ['tweet' => $tweet] = fixture();

    expect(fn () => AiAnalysis::create([
        'tweet_id' => $tweet->id, 'provider' => 'anthropic', 'model' => 'm',
        'prompt_version' => 'v1', 'confidence' => 1.5,
    ]))->toThrow(QueryException::class);
});

// ------------------------------------------------------------ relationships

it('cascades an author deletion through tweets, analyses and projects', function () {
    ['author' => $author, 'tweet' => $tweet] = fixture();

    AiAnalysis::create([
        'tweet_id' => $tweet->id, 'provider' => 'anthropic',
        'model' => 'm', 'prompt_version' => 'v1',
    ]);

    Project::create([
        'primary_tweet_id' => $tweet->id, 'slug' => 'a', 'name' => 'A',
        'category' => 'devtools', 'primary_url' => 'https://x',
        'discovered_at' => now(),
    ]);

    $author->delete();

    expect(Tweet::count())->toBe(0)
        ->and(Project::count())->toBe(0)
        ->and(AiAnalysis::count())->toBe(0);
});

it('protects spend history from query deletion', function () {
    ['query' => $query] = fixture();

    expect(fn () => $query->delete())->toThrow(QueryException::class);
});

it('keeps discovered posts when a run row is removed', function () {
    ['run' => $run, 'tweet' => $tweet] = fixture();

    $run->delete();

    expect(Tweet::count())->toBe(1)
        ->and($tweet->fresh()->search_run_id)->toBeNull();
});

it('keeps a project when its repository is removed', function () {
    ['tweet' => $tweet] = fixture();

    $repo = Repository::create([
        'host' => 'github', 'owner' => 'o', 'name' => 'n',
        'url' => 'https://github.com/o/n',
    ]);

    $project = Project::create([
        'primary_tweet_id' => $tweet->id, 'repository_id' => $repo->id,
        'slug' => 'a', 'name' => 'A', 'category' => 'devtools',
        'primary_url' => 'https://x', 'discovered_at' => now(),
    ]);

    $repo->delete();

    expect(Project::count())->toBe(1)
        ->and($project->fresh()->repository_id)->toBeNull();
});

// ---------------------------------------------------------- metrics history

it('retains a metric snapshot per rescore', function () {
    ['tweet' => $tweet] = fixture();

    $project = Project::create([
        'primary_tweet_id' => $tweet->id, 'slug' => 'a', 'name' => 'A',
        'category' => 'devtools', 'primary_url' => 'https://x',
        'discovered_at' => now(),
    ]);

    ProjectMetric::create([
        'project_id' => $project->id, 'captured_at' => now()->subHours(2),
        'like_count' => 10, 'score' => 1.5,
    ]);

    ProjectMetric::create([
        'project_id' => $project->id, 'captured_at' => now()->subHour(),
        'like_count' => 40, 'score' => 2.8,
    ]);

    expect($project->metrics)->toHaveCount(2);
});

// -------------------------------------------------------------- filtering

it('rejects an unknown category', function () {
    ['tweet' => $tweet] = fixture();

    expect(fn () => Project::create([
        'primary_tweet_id' => $tweet->id, 'slug' => 'a', 'name' => 'A',
        'category' => 'crypto-nonsense', 'primary_url' => 'https://x',
        'discovered_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('attaches and detaches technology tags without touching the project', function () {
    ['tweet' => $tweet] = fixture();

    $project = Project::create([
        'primary_tweet_id' => $tweet->id, 'slug' => 'a', 'name' => 'A',
        'category' => 'devtools', 'primary_url' => 'https://x',
        'discovered_at' => now(),
    ]);

    $rust = Technology::create(['slug' => 'rust', 'name' => 'Rust']);
    $project->technologies()->attach($rust);

    expect($project->technologies)->toHaveCount(1);

    $rust->delete();

    expect(Project::count())->toBe(1)
        ->and($project->fresh()->technologies)->toHaveCount(0);
});

// ------------------------------------------------------------------ ledger

it('refuses a run that finishes before it starts', function () {
    ['run' => $run] = fixture();

    expect(fn () => $run->update(['finished_at' => $run->started_at->subHour()]))
        ->toThrow(QueryException::class);
});

it('applies the documented defaults', function () {
    ['run' => $run, 'tweet' => $tweet, 'query' => $query] = fixture();

    expect($tweet->fresh()->status)->toBe('raw')
        ->and($tweet->fresh()->like_count)->toBe(0)
        ->and($run->fresh()->status)->toBe('running')
        ->and((float) $run->fresh()->cost_usd)->toBe(0.0)
        ->and($query->fresh()->is_active)->toBeTrue();
});
