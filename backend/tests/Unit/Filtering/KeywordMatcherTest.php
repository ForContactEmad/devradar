<?php

declare(strict_types=1);

use DevRadar\Domain\Filtering\KeywordMatcher;
use DevRadar\Domain\Filtering\SignalDefinition;

function signal(string $phrase, int $weight = 2, string $group = 'test', bool $wholeWord = true): SignalDefinition
{
    return new SignalDefinition($phrase, $weight, $group, 'en', $wholeWord);
}

function matcher(): KeywordMatcher
{
    return new KeywordMatcher();
}

// ------------------------------------------------------------ basic matching

it('matches a configured phrase', function () {
    expect(matcher()->matches('We just launched our new CLI', signal('just launched')))->toBeTrue();
});

it('does not match a phrase that is absent', function () {
    expect(matcher()->matches('A thought about databases', signal('just launched')))->toBeFalse();
});

// ------------------------------------------------------------ case variations

it('matches regardless of case', function () {
    foreach (['just launched', 'Just Launched', 'JUST LAUNCHED', 'jUsT lAuNcHeD'] as $variant) {
        expect(matcher()->matches("We {$variant} it", signal('just launched')))->toBeTrue();
    }
});

// ------------------------------------------------------------- word boundaries

it('does not match inside a longer word', function () {
    // Without boundaries this layer becomes a random number generator.
    expect(matcher()->matches('We rebuilt the parser', signal('built')))->toBeFalse()
        ->and(matcher()->matches('a prebuilt binary', signal('built')))->toBeFalse()
        ->and(matcher()->matches('We built the parser', signal('built')))->toBeTrue();
});

it('does not match a phrase that only prefixes a longer word', function () {
    expect(matcher()->matches('Booking a new appointment', signal('new app')))->toBeFalse()
        ->and(matcher()->matches('Trying a new approach', signal('new app')))->toBeFalse()
        ->and(matcher()->matches('Shipped a new app today', signal('new app')))->toBeTrue();
});

it('does not match relaunching as launching', function () {
    expect(matcher()->matches('We are relaunching the site', signal('launching')))->toBeFalse();
});

it('allows a trailing plural when the boundary permits it', function () {
    // "SDK" in "SDKs" is a real match; the boundary falls after the s.
    expect(matcher()->matches('Two new SDKs today', signal('SDKs')))->toBeTrue();
});

it('anchors only the ends that are word characters', function () {
    // "v1.0" ends in punctuation-adjacent digits; a naive \b on both ends
    // would never fire.
    expect(matcher()->matches('Tagged v1.0 today', signal('v1.0', wholeWord: false)))->toBeTrue();
});

// -------------------------------------------------------------- whitespace

it('matches a phrase split across a line break', function () {
    expect(matcher()->matches("we just\nlaunched it", signal('just launched')))->toBeTrue();
});

it('matches a phrase with extra internal spaces', function () {
    expect(matcher()->matches('we just    launched it', signal('just launched')))->toBeTrue();
});

// ----------------------------------------------------------------- unicode

it('matches Arabic phrases on word boundaries', function () {
    $arabic = signal('أطلقت', 3, 'launch');

    expect(matcher()->matches('أطلقت أداة جديدة اليوم', $arabic))->toBeTrue()
        ->and(matcher()->matches('نشرت مكتبة برمجية', $arabic))->toBeFalse();
});

it('is unaffected by emoji in the surrounding text', function () {
    expect(matcher()->matches('🚀 just launched 🎉 our tool', signal('just launched')))->toBeTrue();
});

it('treats accented characters as distinct', function () {
    expect(matcher()->matches('we lancé it', signal('launched')))->toBeFalse();
});

// ------------------------------------------------------------ multiple signals

it('returns every distinct phrase that fires', function () {
    $matches = matcher()->match('I built a new tool and just launched it', [
        signal('I built', 2, 'build'),
        signal('new tool', 2, 'product'),
        signal('just launched', 3, 'launch'),
        signal('open sourced', 3, 'launch'),
    ]);

    expect($matches)->toHaveCount(3);
});

it('counts repeats but contributes the weight once', function () {
    $matches = matcher()->match('new tool new tool new tool', [signal('new tool', 2)]);

    // A post repeating a phrase would otherwise outscore a genuine launch
    // that says it once.
    expect($matches[0]->occurrences)->toBe(3)
        ->and($matches[0]->contribution())->toBe(2);
});

it('returns nothing for empty text', function () {
    expect(matcher()->match('', [signal('just launched')]))->toHaveCount(0)
        ->and(matcher()->match('   ', [signal('just launched')]))->toHaveCount(0);
});

it('returns nothing when no signals are configured', function () {
    expect(matcher()->match('just launched our tool', []))->toHaveCount(0);
});
