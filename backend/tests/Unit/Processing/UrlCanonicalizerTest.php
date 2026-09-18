<?php

declare(strict_types=1);

use DevRadar\Domain\Processing\UrlCanonicalizer;

function canon(): UrlCanonicalizer
{
    return new UrlCanonicalizer();
}

it('strips tracking parameters', function () {
    expect(canon()->canonicalize('https://example.com/tool?utm_source=twitter&utm_campaign=launch'))
        ->toBe('https://example.com/tool');
});

it('keeps meaningful query parameters and sorts them', function () {
    expect(canon()->canonicalize('https://example.com/search?b=2&a=1&utm_source=x'))
        ->toBe('https://example.com/search?a=1&b=2');
});

it('normalises scheme, host casing and www', function () {
    expect(canon()->canonicalize('HTTP://WWW.Example.COM/Tool'))
        ->toBe('https://example.com/Tool');
});

it('drops the fragment, which is never identity', function () {
    expect(canon()->canonicalize('https://example.com/docs#installation'))
        ->toBe('https://example.com/docs');
});

it('treats a trailing slash as the same page', function () {
    expect(canon()->canonicalize('https://example.com/docs/'))
        ->toBe(canon()->canonicalize('https://example.com/docs'));
});

// ------------------------------------------------------ repository identity

it('reduces a repository URL to owner and repo', function () {
    // A launch post links to the root, a README, a release or a file, and
    // all four are the same project.
    $expected = 'https://github.com/acme/pgplan';

    expect(canon()->canonicalize('https://github.com/acme/pgplan'))->toBe($expected)
        ->and(canon()->canonicalize('https://github.com/acme/pgplan/blob/main/README.md'))->toBe($expected)
        ->and(canon()->canonicalize('https://github.com/acme/pgplan/releases/tag/v1.0.0'))->toBe($expected)
        ->and(canon()->canonicalize('https://github.com/acme/pgplan.git'))->toBe($expected);
});

it('lowercases repository owner and name', function () {
    expect(canon()->canonicalize('https://github.com/ACME/PgPlan'))
        ->toBe('https://github.com/acme/pgplan');
});

it('handles gitlab and codeberg the same way', function () {
    expect(canon()->canonicalize('https://gitlab.com/acme/tool/-/tree/main'))
        ->toBe('https://gitlab.com/acme/tool')
        ->and(canon()->canonicalize('https://codeberg.org/acme/tool/src/branch/main'))
        ->toBe('https://codeberg.org/acme/tool');
});

it('does not treat an org or topic page as a project', function () {
    expect(canon()->canonicalize('https://github.com/topics/rust'))->toBe('https://github.com/topics/rust')
        ->and(canon()->canonicalize('https://github.com/acme'))->toBe('https://github.com/acme');
});

// ------------------------------------------------------------- shorteners

it('refuses to canonicalise an unresolved shortener', function () {
    // Its destination is unknown, so two different pages could collide.
    expect(canon()->canonicalize('https://t.co/abc123'))->toBeNull()
        ->and(canon()->canonicalize('https://bit.ly/xyz'))->toBeNull()
        ->and(canon()->isShortener('https://t.co/abc123'))->toBeTrue();
});

// ------------------------------------------------------------- edge cases

it('returns null for missing or unusable input', function () {
    expect(canon()->canonicalize(null))->toBeNull()
        ->and(canon()->canonicalize(''))->toBeNull()
        ->and(canon()->canonicalize('   '))->toBeNull()
        ->and(canon()->canonicalize('not a url'))->toBeNull();
});

it('refuses non-http schemes', function () {
    expect(canon()->canonicalize('javascript:alert(1)'))->toBeNull()
        ->and(canon()->canonicalize('ftp://example.com/file'))->toBeNull();
});

it('collapses duplicate slashes in a path', function () {
    expect(canon()->canonicalize('https://example.com//docs//intro'))
        ->toBe('https://example.com/docs/intro');
});

it('hashes equivalent URLs identically and different ones differently', function () {
    $a = canon()->hash('https://github.com/acme/pgplan?utm_source=twitter');
    $b = canon()->hash('https://www.github.com/ACME/PgPlan/blob/main/README.md');
    $c = canon()->hash('https://github.com/other/project');

    expect($a)->toBe($b)->and($a)->not->toBe($c);
});

it('returns a null hash when there is nothing to hash', function () {
    expect(canon()->hash(null))->toBeNull()
        ->and(canon()->hash('https://t.co/abc'))->toBeNull();
});
