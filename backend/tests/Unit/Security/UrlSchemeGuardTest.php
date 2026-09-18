<?php

declare(strict_types=1);

use DevRadar\Domain\Extraction\CategoryRegistry;
use DevRadar\Domain\Extraction\ExtractionValidator;
use DevRadar\Domain\Extraction\TechnologyDetector;
use DevRadar\Domain\Extraction\UrlClassifier;
use DevRadar\Domain\Filtering\KeywordMatcher;
use DevRadar\Domain\Ingestion\RawPost;

/**
 * The URL scheme gate, at both ends of the chain.
 *
 * A `javascript:` URI reaching a rendered href was the one stored-XSS path in
 * the application: post URLs were appended to a project's links without the
 * scheme check that model-supplied URLs received, and the only thing keeping
 * one out was the provider never emitting it.
 */

function securityValidator(): ExtractionValidator
{
    return new ExtractionValidator(
        categories: new CategoryRegistry(['ai' => [], 'other' => []]),
        urls: new UrlClassifier(),
        technologies: new TechnologyDetector(new KeywordMatcher(), []),
        projectTypes: ['application'],
        minimumConfidence: 0.6,
    );
}

/** @param list<string> $postUrls */
function validateWithUrls(array $postUrls): DevRadar\Domain\Extraction\ValidationResult
{
    return securityValidator()->validate(
        tweetId: 1,
        raw: ['name' => 'Tool', 'category' => 'ai', 'confidence' => 0.9, 'technologies' => []],
        postText: 'Launched Tool',
        postUrls: $postUrls,
        authorHandle: 'dev',
        sourcePostUrl: 'https://x.com/dev/status/1',
        publishedAt: new DateTimeImmutable(),
    );
}

function rawPostWithUrl(string $url): RawPost
{
    return new RawPost(
        id: '1',
        authorId: '1',
        text: 'x',
        lang: 'en',
        createdAt: new DateTimeImmutable(),
        likeCount: 0,
        repostCount: 0,
        replyCount: 0,
        quoteCount: 0,
        bookmarkCount: null,
        impressionCount: null,
        urls: [['url' => $url, 'expanded' => $url, 'unwound' => $url]],
        referencedPosts: [],
        possiblySensitive: false,
        rawPayload: [],
    );
}

// ------------------------------------------------- ingestion boundary

it('refuses to store a javascript URI as a post link', function () {
    // Checked once at the boundary, so no downstream consumer has to
    // remember to re-check it.
    expect(rawPostWithUrl('javascript:alert(document.domain)')->bestUrl())->toBeNull();
});

it('refuses data and vbscript URIs', function () {
    expect(rawPostWithUrl('data:text/html;base64,PHNjcmlwdD4=')->bestUrl())->toBeNull()
        ->and(rawPostWithUrl('vbscript:msgbox(1)')->bestUrl())->toBeNull();
});

it('still stores ordinary links', function () {
    expect(rawPostWithUrl('https://github.com/acme/tool')->bestUrl())->toBe('https://github.com/acme/tool')
        ->and(rawPostWithUrl('http://example.dev')->bestUrl())->toBe('http://example.dev');
});

// ------------------------------------------------- extraction boundary

it('drops a hostile post URL instead of publishing it as a project link', function () {
    // This is the path that previously bypassed the check entirely.
    $result = validateWithUrls(['javascript:alert(1)']);

    expect($result->isValid)->toBeTrue()
        ->and($result->project->websiteUrl)->toBeNull()
        ->and($result->project->repositoryUrl)->toBeNull()
        ->and(implode(' ', $result->corrections))->toContain('not a usable http(s) URL');
});

it('keeps the safe links when one in the set is hostile', function () {
    // Dropping the whole project over one bad link would be its own bug.
    $result = validateWithUrls(['javascript:alert(1)', 'https://github.com/acme/tool']);

    expect($result->project->repositoryUrl)->toBe('https://github.com/acme/tool');
});

it('applies the same gate to model-supplied and post-supplied URLs', function () {
    $viaModel = securityValidator()->validate(
        tweetId: 1,
        raw: ['name' => 'Tool', 'category' => 'ai', 'confidence' => 0.9,
            'website_url' => 'javascript:alert(1)', 'technologies' => []],
        postText: 'Launched Tool',
        postUrls: [],
        authorHandle: 'dev',
        sourcePostUrl: 'https://x.com/dev/status/1',
        publishedAt: new DateTimeImmutable(),
    );

    expect($viaModel->project->websiteUrl)->toBeNull()
        ->and(validateWithUrls(['javascript:alert(1)'])->project->websiteUrl)->toBeNull();
});
