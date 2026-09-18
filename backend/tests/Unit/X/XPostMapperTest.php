<?php

declare(strict_types=1);

use DevRadar\Infrastructure\X\XPostMapper;

/**
 * Mapper tests. No network, no credentials, no cost.
 *
 * Fixtures are modelled on the X API v2 OpenAPI specification (version
 * 2.168), including the current `repost_count` / `referenced_posts` names and
 * their legacy equivalents.
 */
function fixtureJson(string $name): array
{
    $path = __DIR__ . '/../../Fixtures/' . $name . '.json';

    return json_decode((string) file_get_contents($path), true);
}

it('maps a post into DevRadar types', function () {
    $mapper = new XPostMapper();
    $result = $mapper->mapPosts(fixtureJson('x-search-page-1'));

    expect($result['posts'])->toHaveCount(2);

    $first = $result['posts'][0];

    expect($first->id)->toBe('1800000000000000001')
        ->and($first->authorId)->toBe('500001')
        ->and($first->lang)->toBe('en')
        ->and($first->likeCount)->toBe(214)
        ->and($first->replyCount)->toBe(12)
        ->and($first->createdAt->format('Y-m-d H:i'))->toBe('2026-09-08 10:15');
});

it('reads the current repost_count field name', function () {
    $mapper = new XPostMapper();
    $posts = $mapper->mapPosts(fixtureJson('x-search-page-1'))['posts'];

    // X renamed retweet_count to repost_count. Reading the wrong key would
    // silently map engagement to zero, which looks like an unpopular post
    // rather than a bug.
    expect($posts[0]->repostCount)->toBe(37);
});

it('still reads legacy retweet_count and referenced_tweets', function () {
    $mapper = new XPostMapper();
    $posts = $mapper->mapPosts(fixtureJson('x-search-legacy-fields'))['posts'];

    expect($posts[0]->repostCount)->toBe(9)
        ->and($posts[0]->isReply())->toBeTrue();
});

it('detects reposts so they can be filtered before classification', function () {
    $mapper = new XPostMapper();
    $posts = $mapper->mapPosts(fixtureJson('x-search-page-1'))['posts'];

    expect($posts[0]->isRepost())->toBeFalse()
        ->and($posts[1]->isRepost())->toBeTrue();
});

it('prefers the provider-resolved url over the shortened one', function () {
    $mapper = new XPostMapper();
    $posts = $mapper->mapPosts(fixtureJson('x-search-page-1'))['posts'];

    // unwound_url is X's own final destination after following redirects.
    // Using it saves DevRadar an HTTP request per link and gives dedup a
    // canonical target without tracking parameters.
    expect($posts[0]->bestUrl())->toBe('https://github.com/acme/pgplan');
});

it('falls back to expanded_url when no unwound url is present', function () {
    $mapper = new XPostMapper();
    $posts = $mapper->mapPosts(fixtureJson('x-search-page-2'))['posts'];

    expect($posts[0]->bestUrl())->toBe('https://github.com/rs/tinysched');
});

it('maps authors keyed by id', function () {
    $mapper = new XPostMapper();
    $authors = $mapper->mapAuthors(fixtureJson('x-search-page-1'));

    expect($authors)->toHaveCount(2)
        ->and($authors['500001']->username)->toBe('acmedev')
        ->and($authors['500001']->verified)->toBeTrue()
        ->and($authors['500001']->followersCount)->toBe(8400);
});

it('counts posts and users separately when tallying spend', function () {
    $mapper = new XPostMapper();
    $counts = $mapper->countBillableResources(fixtureJson('x-search-page-1'));

    // Author objects are billed at twice the post rate, so counting only
    // posts would understate the bill by the more expensive half.
    expect($counts['posts'])->toBe(2)
        ->and($counts['users'])->toBe(2)
        ->and($counts['total'])->toBe(4);
});

it('skips an unmappable post without discarding the paid-for page', function () {
    $mapper = new XPostMapper();
    $result = $mapper->mapPosts(fixtureJson('x-search-partial-errors'));

    expect($result['posts'])->toHaveCount(1)
        ->and($result['skipped'])->toHaveCount(1);
});

it('surfaces partial errors returned alongside a 200', function () {
    $mapper = new XPostMapper();
    $errors = $mapper->partialErrors(fixtureJson('x-search-partial-errors'));

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['resource_type'])->toBe('user');
});

it('returns nothing for an empty payload rather than failing', function () {
    $mapper = new XPostMapper();

    expect($mapper->mapPosts([])['posts'])->toHaveCount(0)
        ->and($mapper->mapAuthors([]))->toHaveCount(0)
        ->and($mapper->countBillableResources([])['total'])->toBe(0);
});
