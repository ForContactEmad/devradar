<?php

declare(strict_types=1);

use DevRadar\Domain\Processing\TextNormalizer;

function normalizer(): TextNormalizer
{
    return new TextNormalizer();
}

// ------------------------------------------------------------- whitespace

it('collapses runs of whitespace and newlines', function () {
    expect(normalizer()->readable("Just   launched\n\n  our   CLI\ttool "))
        ->toBe('Just launched our CLI tool');
});

it('treats a non-breaking space as a space', function () {
    expect(normalizer()->readable("just\u{00A0}launched"))->toBe('just launched');
});

// ---------------------------------------------------------- empty content

it('reports text that is only whitespace as empty', function () {
    expect(normalizer()->isEffectivelyEmpty("   \n\t  "))->toBeTrue()
        ->and(normalizer()->readable('   '))->toBe('');
});

it('reports text that is only invisible characters as empty', function () {
    expect(normalizer()->isEffectivelyEmpty("\u{200B}\u{FEFF}\u{200E}"))->toBeTrue();
});

it('does not report a single meaningful character as empty', function () {
    expect(normalizer()->isEffectivelyEmpty('x'))->toBeFalse();
});

// ------------------------------------------------------------------ unicode

it('composes equivalent unicode forms to the same string', function () {
    $composed = "caf\u{00E9}";          // é as one codepoint
    $decomposed = "cafe\u{0301}";       // e + combining acute

    // Without NFC these hash differently and identical posts look distinct.
    expect(normalizer()->readable($composed))->toBe(normalizer()->readable($decomposed));
});

it('strips zero-width and directional marks that change a hash but not meaning', function () {
    $withMarks = "just\u{200B}launched\u{200F} our tool";

    expect(normalizer()->readable($withMarks))->toBe('justlaunched our tool');
});

it('keeps Arabic text intact and fingerprintable', function () {
    $arabic = 'أطلقت أداة جديدة مفتوحة المصدر للمطورين';

    // A naive [a-z0-9] filter would reduce this to nothing and make every
    // Arabic post a duplicate of every other.
    expect(normalizer()->readable($arabic))->toBe($arabic)
        ->and(normalizer()->fingerprint($arabic))->not->toBeNull();
});

it('gives different Arabic posts different fingerprints', function () {
    $a = normalizer()->fingerprint('أطلقت أداة جديدة مفتوحة المصدر للمطورين');
    $b = normalizer()->fingerprint('نشرت مكتبة برمجية جديدة لتحليل البيانات');

    expect($a)->not->toBe($b);
});

it('handles emoji without corrupting surrounding text', function () {
    expect(normalizer()->readable('Just shipped 🚀 our CLI'))->toBe('Just shipped 🚀 our CLI');
});

// -------------------------------------------------------------- metadata

it('decodes HTML entities that arrive in payloads', function () {
    expect(normalizer()->readable('Rust &amp; Go &lt;3'))->toBe('Rust & Go <3');
});

// ------------------------------------------------------------------ URLs

it('keeps URLs in the readable text', function () {
    $text = 'Launched https://github.com/acme/tool today';

    // Readable text is what a classifier reads; destroying the link it needs
    // to judge the post would be a bad trade for tidiness.
    expect(normalizer()->readable($text))->toContain('https://github.com/acme/tool');
});

it('removes URLs from the fingerprint source', function () {
    $a = normalizer()->fingerprintSource('Just launched our postgres plan viewer https://t.co/aaaaaa');
    $b = normalizer()->fingerprintSource('Just launched our postgres plan viewer https://t.co/bbbbbb');

    // The same project is announced with a different shortened link every
    // time; matching on the link would never fire.
    expect($a)->toBe($b);
});

// -------------------------------------------------------------- hashtags

it('keeps hashtags in the readable text', function () {
    expect(normalizer()->readable('Shipped #OpenSource today'))->toContain('#OpenSource');
});

it('drops the hashtag marker in the fingerprint so tagged and untagged match', function () {
    $tagged = normalizer()->fingerprintSource('we just launched an #opensource database tool');
    $plain = normalizer()->fingerprintSource('we just launched an opensource database tool');

    expect($tagged)->toBe($plain);
});

it('extracts hashtags lowercased and deduplicated', function () {
    expect(normalizer()->hashtags('#Rust and #rust and #WebDev'))->toBe(['rust', 'webdev']);
});

// -------------------------------------------------------------- mentions

it('keeps mentions in the readable text', function () {
    expect(normalizer()->readable('Built with @laravelphp'))->toContain('@laravelphp');
});

it('removes mentions from the fingerprint source', function () {
    $a = normalizer()->fingerprintSource('@alice check out our new postgres plan viewer');
    $b = normalizer()->fingerprintSource('@bob check out our new postgres plan viewer');

    expect($a)->toBe($b);
});

it('extracts mentions lowercased and deduplicated', function () {
    expect(normalizer()->mentions('cc @Alice @bob @alice'))->toBe(['alice', 'bob']);
});

it('strips a repost prefix from the fingerprint source', function () {
    $rt = normalizer()->fingerprintSource('RT @someone: shipping a new tracing sdk today');
    $original = normalizer()->fingerprintSource('shipping a new tracing sdk today');

    expect($rt)->toBe($original);
});

// ----------------------------------------------------------- fingerprints

it('refuses to fingerprint text too short to be evidence', function () {
    // "new tool" would otherwise collapse thousands of unrelated posts.
    expect(normalizer()->fingerprint('new tool'))->toBeNull();
});

it('refuses to fingerprint a post that is only a link and a mention', function () {
    expect(normalizer()->fingerprint('@someone https://t.co/abc123'))->toBeNull();
});

it('gives different text different fingerprints', function () {
    expect(normalizer()->fingerprint('launched a rust cli for postgres plans'))
        ->not->toBe(normalizer()->fingerprint('launched a vue component library for forms'));
});

it('gives casing and punctuation variants the same fingerprint', function () {
    expect(normalizer()->fingerprint('Just Launched: Our Postgres Plan Viewer!!!'))
        ->toBe(normalizer()->fingerprint('just launched our postgres plan viewer'));
});
