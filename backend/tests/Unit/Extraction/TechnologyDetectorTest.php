<?php

declare(strict_types=1);

use DevRadar\Domain\Extraction\DetectedTechnology;
use DevRadar\Domain\Extraction\TechnologyDetector;
use DevRadar\Domain\Filtering\KeywordMatcher;

function techCatalog(): array
{
    return [
        'laravel' => ['name' => 'Laravel', 'kind' => 'framework', 'aliases' => ['laravel']],
        'vue' => ['name' => 'Vue', 'kind' => 'framework', 'aliases' => ['vue', 'vuejs', 'vue.js']],
        'nextjs' => ['name' => 'Next.js', 'kind' => 'framework', 'aliases' => ['next.js', 'nextjs']],
        'postgresql' => ['name' => 'PostgreSQL', 'kind' => 'database', 'aliases' => ['postgresql', 'postgres']],
        'nodejs' => ['name' => 'Node.js', 'kind' => 'runtime', 'aliases' => ['node.js', 'nodejs', 'npm install']],
        'go' => ['name' => 'Go', 'kind' => 'language', 'case_sensitive' => true, 'aliases' => ['Golang', 'golang', 'go get']],
        'rust' => ['name' => 'Rust', 'kind' => 'language', 'case_sensitive' => true, 'aliases' => ['Rust', 'crates.io']],
        'openai' => ['name' => 'OpenAI', 'kind' => 'ai', 'aliases' => ['openai', 'gpt-4']],
        'docker' => ['name' => 'Docker', 'kind' => 'tool', 'aliases' => ['docker']],
    ];
}

function techDetector(): TechnologyDetector
{
    return new TechnologyDetector(new KeywordMatcher(), techCatalog());
}

it('detects a technology named in the post', function () {
    $found = techDetector()->detect('Just launched my AI invoice generator built with Laravel and Vue.');

    $slugs = array_map(fn (DetectedTechnology $t) => $t->slug, $found);

    expect($slugs)->toContain('laravel')->and($slugs)->toContain('vue');
});

it('detects multiple technologies across the stack', function () {
    $found = techDetector()->detect('Built with Next.js, PostgreSQL and Docker, deployed today.');

    expect(array_map(fn ($t) => $t->slug, $found))->toHaveCount(3);
});

it('records how each technology was evidenced', function () {
    $found = techDetector()->detect('Built with Laravel');

    expect($found[0]->source)->toBe(DetectedTechnology::SOURCE_TEXT)
        ->and($found[0]->evidence)->toBe('laravel')
        ->and($found[0]->kind)->toBe('framework');
});

it('finds evidence in a URL when the text is silent', function () {
    $found = techDetector()->detect('A new package', ['https://crates.io/crates/tinysched']);

    expect($found[0]->slug)->toBe('rust')
        ->and($found[0]->source)->toBe(DetectedTechnology::SOURCE_URL);
});

// ------------------------------------------------ the no-invention rule

it('drops a model suggestion nothing in the post supports', function () {
    // A model asked what a project is built with will happily answer even
    // when the post says nothing. "Laravel" is a plausible guess for almost
    // any web tool, and a plausible guess published as fact is worse than an
    // empty stack.
    $found = techDetector()->detect('Launched a new invoice tool today', [], ['Laravel', 'Vue']);

    expect($found)->toHaveCount(0);
});

it('keeps a model suggestion the post corroborates', function () {
    $found = techDetector()->detect('Launched an invoice tool, built with Laravel', [], ['Laravel']);

    expect($found)->toHaveCount(1)
        ->and($found[0]->slug)->toBe('laravel');
});

it('marks a corroborated suggestion as model-confirmed only when the detector missed it', function () {
    // The detector finds Laravel itself, so the source stays post_text.
    $found = techDetector()->detect('built with Laravel', [], ['Laravel']);

    expect($found[0]->source)->toBe(DetectedTechnology::SOURCE_TEXT);
});

it('reports which suggestions were dropped', function () {
    $dropped = techDetector()->unsupportedSuggestions('Launched a tool built with Laravel', [], ['Laravel', 'Vue', 'Kubernetes']);

    expect($dropped)->toContain('Vue')
        ->and($dropped)->toContain('Kubernetes')
        ->and($dropped)->not->toContain('Laravel');
});

it('ignores a suggestion for a technology it does not know', function () {
    $found = techDetector()->detect('a tool', [], ['SomeFrameworkNobodyHasHeardOf']);

    expect($found)->toHaveCount(0);
});

// -------------------------------------------------------- ambiguous names

it('does not treat the verb "go" as the language', function () {
    // Matching case-insensitively would tag a large share of any corpus.
    $found = techDetector()->detect('I decided to go ahead and ship it today');

    expect(array_map(fn ($t) => $t->slug, $found))->not->toContain('go');
});

it('detects Go from an unambiguous alias', function () {
    expect(array_map(fn ($t) => $t->slug, techDetector()->detect('Written in Golang, ships as one binary')))
        ->toContain('go');
});

it('does not treat lowercase rust as the language', function () {
    $found = techDetector()->detect('scraping rust off an old bike frame');

    expect(array_map(fn ($t) => $t->slug, $found))->not->toContain('rust');
});

it('detects Rust when capitalised as the language', function () {
    expect(array_map(fn ($t) => $t->slug, techDetector()->detect('A tiny scheduler written in Rust')))
        ->toContain('rust');
});

// ------------------------------------------------------------ edge cases

it('handles punctuation in technology names', function () {
    $slugs = array_map(fn ($t) => $t->slug, techDetector()->detect('Built on Next.js and Node.js'));

    expect($slugs)->toContain('nextjs')->and($slugs)->toContain('nodejs');
});

it('does not match a technology inside a longer word', function () {
    $found = techDetector()->detect('We discussed the vuelta and the postgresqlish thing');

    expect(array_map(fn ($t) => $t->slug, $found))->not->toContain('vue');
});

it('returns nothing for a post with no technologies', function () {
    expect(techDetector()->detect('Launched something today. Try it.'))->toHaveCount(0);
});

it('does not report the same technology twice', function () {
    $found = techDetector()->detect('Laravel, laravel, and more Laravel', [], ['Laravel']);

    expect($found)->toHaveCount(1);
});
