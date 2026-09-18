<?php

declare(strict_types=1);

use DevRadar\Domain\Extraction\CategoryRegistry;
use DevRadar\Domain\Extraction\ExtractionValidator;
use DevRadar\Domain\Extraction\TechnologyDetector;
use DevRadar\Domain\Extraction\UrlClassifier;
use DevRadar\Domain\Filtering\KeywordMatcher;

function validator(float $minConfidence = 0.6): ExtractionValidator
{
    return new ExtractionValidator(
        categories: new CategoryRegistry([
            'ai' => ['artificial intelligence', 'machine learning'],
            'developer-tools' => ['devtools', 'developer tooling'],
            'library' => ['package'],
            'cli' => ['command line'],
            'other' => [],
        ]),
        urls: new UrlClassifier(),
        technologies: new TechnologyDetector(new KeywordMatcher(), techCatalog()),
        projectTypes: ['application', 'library', 'cli', 'service', 'other'],
        minimumConfidence: $minConfidence,
    );
}

/**
 * @param array<string, mixed> $raw
 * @param list<string>         $postUrls
 */
function validate(array $raw, string $text = 'Just launched Pgplan, built with Laravel.', array $postUrls = []): DevRadar\Domain\Extraction\ValidationResult
{
    return validator()->validate(
        tweetId: 1,
        raw: $raw,
        postText: $text,
        postUrls: $postUrls,
        authorHandle: 'acmedev',
        sourcePostUrl: 'https://x.com/acmedev/status/1800000000000000001',
        publishedAt: new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('UTC')),
    );
}

function fullPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Pgplan',
        'description' => 'A Postgres query plan viewer.',
        'category' => 'developer-tools',
        'project_type' => 'cli',
        'technologies' => ['Laravel'],
        'repository_url' => 'https://github.com/acme/pgplan',
        'website_url' => null,
        'demo_url' => null,
        'confidence' => 0.9,
    ], $overrides);
}

// ------------------------------------------------------- project with GitHub

it('extracts a project with a repository', function () {
    $result = validate(fullPayload(), postUrls: ['https://github.com/acme/pgplan']);

    expect($result->isValid)->toBeTrue()
        ->and($result->project->name)->toBe('Pgplan')
        ->and($result->project->repositoryUrl)->toBe('https://github.com/acme/pgplan')
        ->and($result->project->category)->toBe('developer-tools')
        ->and($result->project->projectType)->toBe('cli')
        ->and($result->project->confidence)->toBe(0.9);
});

it('uses the repository as the primary destination', function () {
    $result = validate(fullPayload(), postUrls: ['https://github.com/acme/pgplan', 'https://pgplan.dev']);

    // The most useful destination for the target reader, and the most stable
    // identity for deduplication.
    expect($result->project->primaryUrl())->toBe('https://github.com/acme/pgplan');
});

it('separates website and demo from the repository', function () {
    $result = validate(
        fullPayload(),
        postUrls: ['https://github.com/acme/pgplan', 'https://pgplan.dev', 'https://pgplan.vercel.app'],
    );

    expect($result->project->repositoryUrl)->toBe('https://github.com/acme/pgplan')
        ->and($result->project->websiteUrl)->toBe('https://pgplan.dev')
        ->and($result->project->demoUrl)->toBe('https://pgplan.vercel.app');
});

// ---------------------------------------------------- project without GitHub

it('extracts a project with no repository at all', function () {
    $result = validate(
        fullPayload(['repository_url' => null, 'website_url' => 'https://invoicer.app']),
        postUrls: ['https://invoicer.app'],
    );

    expect($result->isValid)->toBeTrue()
        ->and($result->project->repositoryUrl)->toBeNull()
        ->and($result->project->websiteUrl)->toBe('https://invoicer.app')
        ->and($result->project->primaryUrl())->toBe('https://invoicer.app');
});

it('falls back to the source post when the project has no links', function () {
    $result = validate(fullPayload(['repository_url' => null]), postUrls: []);

    // A null is honest. Duplicating a link the reader already has is not.
    expect($result->project->websiteUrl)->toBeNull()
        ->and($result->project->primaryUrl())->toContain('x.com/acmedev');
});

// -------------------------------------------------------- invalid URLs

it('drops a URL the post never contained', function () {
    // The single most important check here. A fabricated repository link
    // looks correct, passes review, reaches the front page and goes nowhere.
    $result = validate(
        fullPayload(['repository_url' => 'https://github.com/invented/nothere']),
        postUrls: ['https://pgplan.dev'],
    );

    expect($result->project->repositoryUrl)->toBeNull()
        ->and(implode(' ', $result->corrections))->toContain('does not appear in the post');
});

it('drops a malformed URL', function () {
    $result = validate(
        fullPayload(['repository_url' => 'not-a-url', 'website_url' => 'javascript:alert(1)']),
        postUrls: [],
    );

    expect($result->project->repositoryUrl)->toBeNull()
        ->and($result->project->websiteUrl)->toBeNull()
        ->and(implode(' ', $result->corrections))->toContain('not a usable http(s) URL');
});

it('accepts a deeper path under a link the post did contain', function () {
    // A model quoting the releases page when the post linked the repo root
    // is reporting the same project, not inventing one.
    $result = validate(
        fullPayload(['repository_url' => 'https://github.com/acme/pgplan/releases/tag/v1.0']),
        postUrls: ['https://github.com/acme/pgplan'],
    );

    expect($result->project->repositoryUrl)->toContain('github.com/acme/pgplan');
});

it('keeps a link the post contained even when the model failed to report it', function () {
    $result = validate(
        fullPayload(['repository_url' => null]),
        postUrls: ['https://github.com/acme/pgplan'],
    );

    // Dropping it would lose a repository the model simply missed.
    expect($result->project->repositoryUrl)->toBe('https://github.com/acme/pgplan');
});

// ------------------------------------------------------- missing name

it('rejects an extraction with no project name', function () {
    $result = validate(fullPayload(['name' => null]));

    // Falling back to the post's first line would publish a sentence as a
    // title, which reads as broken rather than as missing data.
    expect($result->isValid)->toBeFalse()
        ->and($result->rejectReason)->toBe('no-project-name');
});

it('rejects a placeholder name', function () {
    foreach (['Unknown', 'N/A', 'untitled', 'none'] as $placeholder) {
        expect(validate(fullPayload(['name' => $placeholder]))->isValid)->toBeFalse();
    }
});

it('rejects a name the length of a sentence', function () {
    // The model returning the post text instead of a name.
    $result = validate(fullPayload(['name' => str_repeat('a very long name ', 20)]));

    expect($result->isValid)->toBeFalse();
});

// -------------------------------------------------- missing description

it('publishes a project with no description', function () {
    $result = validate(fullPayload(['description' => null]), postUrls: ['https://github.com/acme/pgplan']);

    // A missing description is a gap, not a reason to lose the project.
    expect($result->isValid)->toBeTrue()
        ->and($result->project->description)->toBeNull();
});

it('truncates an over-long description and says so', function () {
    $result = validate(fullPayload(['description' => str_repeat('x', 900)]));

    expect(mb_strlen($result->project->description))->toBe(400)
        ->and($result->corrections)->toContain('description truncated');
});

// -------------------------------------------------- low confidence

it('rejects a low-confidence extraction', function () {
    $result = validate(fullPayload(['confidence' => 0.3]));

    expect($result->isValid)->toBeFalse()
        ->and($result->rejectReason)->toBe('low-extraction-confidence');
});

it('rejects an extraction with no confidence at all', function () {
    expect(validate(fullPayload(['confidence' => null]))->rejectReason)->toBe('no-confidence');
});

it('rejects a confidence outside the range', function () {
    expect(validate(fullPayload(['confidence' => 1.7]))->rejectReason)->toBe('no-confidence');
});

// ------------------------------------------------------ ambiguous project

it('records an unregistered category as other rather than failing', function () {
    $result = validate(fullPayload(['category' => 'Web3 Infrastructure']));

    // A model inventing a category should not cost us the project.
    expect($result->isValid)->toBeTrue()
        ->and($result->project->category)->toBe('other')
        ->and(implode(' ', $result->corrections))->toContain('not registered');
});

it('resolves a category alias to its canonical slug', function () {
    expect(validate(fullPayload(['category' => 'devtools']))->project->category)->toBe('developer-tools')
        ->and(validate(fullPayload(['category' => 'Machine Learning']))->project->category)->toBe('ai');
});

it('drops an unregistered project type', function () {
    $result = validate(fullPayload(['project_type' => 'quantum-widget']));

    expect($result->project->projectType)->toBeNull()
        ->and(implode(' ', $result->corrections))->toContain('project_type');
});

it('publishes with no category information at all', function () {
    $result = validate(fullPayload(['category' => null, 'project_type' => null]));

    expect($result->isValid)->toBeTrue()
        ->and($result->project->category)->toBe('other');
});

// ------------------------------------------------- multiple technologies

it('attaches every technology the post evidences', function () {
    $result = validate(
        fullPayload(['technologies' => ['Laravel', 'Vue', 'PostgreSQL']]),
        text: 'Launched Pgplan, built with Laravel, Vue and PostgreSQL.',
    );

    expect($result->project->technologySlugs())->toHaveCount(3);
});

it('drops technologies the post does not support', function () {
    $result = validate(
        fullPayload(['technologies' => ['Laravel', 'Kubernetes', 'MongoDB']]),
        text: 'Launched Pgplan, built with Laravel.',
    );

    expect($result->project->technologySlugs())->toBe(['laravel'])
        ->and(implode(' ', $result->corrections))->toContain('nothing in the post supports it');
});

it('publishes with an empty tech stack when the post says nothing', function () {
    $result = validate(fullPayload(['technologies' => []]), text: 'Launched Pgplan today.');

    // An empty stack is correct. A guessed one is not.
    expect($result->isValid)->toBeTrue()
        ->and($result->project->technologies)->toHaveCount(0);
});

it('ignores a malformed technologies field', function () {
    $result = validate(fullPayload(['technologies' => 'Laravel, Vue']), text: 'Built with Laravel.');

    // A string where an array was asked for. The detector still finds Laravel
    // in the text, so nothing is lost.
    expect($result->project->technologySlugs())->toBe(['laravel']);
});

// ---------------------------------------------------------- metadata

it('carries author, source post and published date through', function () {
    $result = validate(fullPayload(), postUrls: ['https://github.com/acme/pgplan']);

    expect($result->project->authorHandle)->toBe('acmedev')
        ->and($result->project->sourcePostUrl)->toContain('/status/1800000000000000001')
        ->and($result->project->publishedAt->format('Y-m-d'))->toBe('2026-09-08');
});
