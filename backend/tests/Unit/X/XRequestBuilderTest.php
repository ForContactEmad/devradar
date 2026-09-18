<?php

declare(strict_types=1);

use DevRadar\Domain\Ingestion\SearchCriteria;
use DevRadar\Infrastructure\X\XApiConfig;
use DevRadar\Infrastructure\X\XRequestBuilder;

function builderConfig(int $maxQueryLength = 512): XApiConfig
{
    return new XApiConfig(bearerToken: 'test-token', maxQueryLength: $maxQueryLength);
}

it('uses post.fields, not tweet.fields', function () {
    $builder = new XRequestBuilder(builderConfig());
    $params = $builder->build(new SearchCriteria('rust cli'));

    // The current OpenAPI specification names this parameter post.fields.
    // Sending tweet.fields would be silently ignored, returning posts with
    // no metrics and no entities -- which looks like empty data, not an error.
    expect($params)->toHaveKey('post.fields')
        ->and($params)->not->toHaveKey('tweet.fields');
});

it('requests the fields the pipeline actually needs', function () {
    $builder = new XRequestBuilder(builderConfig());
    $fields = $builder->build(new SearchCriteria('x'))['post.fields'];

    expect($fields)->toContain('public_metrics')
        ->and($fields)->toContain('entities')
        ->and($fields)->toContain('created_at')
        ->and($fields)->toContain('referenced_posts');
});

it('expands authors and asks for user fields', function () {
    $params = (new XRequestBuilder(builderConfig()))->build(new SearchCriteria('x'));

    expect($params['expansions'])->toBe('author_id')
        ->and($params['user.fields'])->toContain('public_metrics');
});

it('omits the author expansion when it is disabled to save spend', function () {
    $config = new XApiConfig(bearerToken: 't', expandAuthors: false);
    $params = (new XRequestBuilder($config))->build(new SearchCriteria('x'));

    expect($params)->not->toHaveKey('expansions')
        ->and($params)->not->toHaveKey('user.fields');
});

it('sends since_id for incremental polling', function () {
    $params = (new XRequestBuilder(builderConfig()))
        ->build(new SearchCriteria('x', sinceId: '1800000000000000000'));

    expect($params['since_id'])->toBe('1800000000000000000')
        ->and($params)->not->toHaveKey('start_time');
});

it('formats start_time as an ISO 8601 UTC instant', function () {
    $criteria = new SearchCriteria('x', startTime: new DateTimeImmutable('2026-09-01 12:30:00', new DateTimeZone('UTC')));
    $params = (new XRequestBuilder(builderConfig()))->build($criteria);

    expect($params['start_time'])->toBe('2026-09-01T12:30:00Z');
});

it('passes the pagination token as pagination_token', function () {
    $params = (new XRequestBuilder(builderConfig()))->build(new SearchCriteria('x'), 'tok123');

    expect($params['pagination_token'])->toBe('tok123');
});

it('rejects a query longer than the configured limit instead of truncating it', function () {
    $builder = new XRequestBuilder(builderConfig(50));

    // Truncating would silently change what is matched, and the shortened
    // query would still cost money to run.
    expect(fn () => $builder->build(new SearchCriteria(str_repeat('a', 51))))
        ->toThrow(InvalidArgumentException::class, 'Split it into several queries');
});

it('accepts a query exactly at the limit', function () {
    $builder = new XRequestBuilder(builderConfig(50));
    $params = $builder->build(new SearchCriteria(str_repeat('a', 50)));

    expect($params['max_results'])->toBe(100);
});
