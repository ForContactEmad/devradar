<?php

declare(strict_types=1);

use DevRadar\Domain\Classification\ClassificationResponseParser;

function parser(): ClassificationResponseParser
{
    return new ClassificationResponseParser();
}

// ------------------------------------------------------------ valid output

it('parses the documented positive shape', function () {
    $result = parser()->parse('{"is_project": true, "is_new": true, "confidence": 0.96, "reason": "launch announcement"}');

    expect($result['ok'])->toBeTrue()
        ->and($result['data']['is_project'])->toBeTrue()
        ->and($result['data']['is_new'])->toBeTrue()
        ->and($result['data']['confidence'])->toBe(0.96)
        ->and($result['data']['reason'])->toBe('launch announcement');
});

it('parses a bare negative verdict', function () {
    // "I worked on a Laravel project three years ago" -> {"is_project": false}
    // A post that is not a project is neither new nor old, so requiring the
    // rest of the schema would fail a perfectly correct answer.
    $result = parser()->parse('{"is_project": false}');

    expect($result['ok'])->toBeTrue()
        ->and($result['data']['is_project'])->toBeFalse()
        ->and($result['data']['is_new'])->toBeNull();
});

// --------------------------------------------------------- tolerant parsing

it('recovers JSON from a markdown fence', function () {
    $raw = "Here is my answer:\n```json\n{\"is_project\": true, \"is_new\": true, \"confidence\": 0.8}\n```";

    expect(parser()->parse($raw)['ok'])->toBeTrue();
});

it('recovers JSON surrounded by prose', function () {
    $raw = 'Sure! {"is_project": false, "reason": "a retrospective"} Hope that helps.';

    expect(parser()->parse($raw)['ok'])->toBeTrue();
});

it('handles nested objects and braces inside strings', function () {
    $raw = '{"is_project": true, "is_new": true, "confidence": 0.9, "reason": "mentions {curly} braces"}';
    $result = parser()->parse($raw);

    expect($result['ok'])->toBeTrue()
        ->and($result['data']['reason'])->toContain('{curly}');
});

it('accepts the several shapes models use for booleans', function () {
    foreach (['true', '"yes"', '1', '"Y"'] as $variant) {
        $result = parser()->parse('{"is_project": ' . $variant . ', "is_new": true, "confidence": 0.9}');

        expect($result['ok'])->toBeTrue()
            ->and($result['data']['is_project'])->toBeTrue();
    }
});

it('converts a percentage confidence', function () {
    // "96" for 0.96 is a common and unambiguous slip.
    $result = parser()->parse('{"is_project": true, "is_new": true, "confidence": 96}');

    expect($result['data']['confidence'])->toBe(0.96);
});

// ------------------------------------------------------- strict validation

it('rejects malformed JSON', function () {
    $result = parser()->parse('{"is_project": true, "is_new": true,}');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('Malformed JSON');
});

it('rejects output containing no JSON at all', function () {
    $result = parser()->parse('I think this is probably a launch, yes.');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('No JSON object');
});

it('rejects a response truncated by the token limit', function () {
    $result = parser()->parse('{"is_project": true, "is_new": true, "confi');

    expect($result['ok'])->toBeFalse();
});

it('rejects a missing is_project', function () {
    $result = parser()->parse('{"is_new": true, "confidence": 0.9}');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('is_project');
});

it('rejects a positive verdict with no is_new', function () {
    $result = parser()->parse('{"is_project": true, "confidence": 0.9}');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('is_new');
});

it('rejects a positive verdict with no usable confidence', function () {
    $result = parser()->parse('{"is_project": true, "is_new": true}');

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('confidence');
});

it('refuses a confidence outside the range rather than clamping it', function () {
    // Clamping 150 to 1.0 would manufacture certainty from evidence that the
    // model did not understand the schema.
    foreach (['-0.5', '150', '"high"'] as $bad) {
        $result = parser()->parse('{"is_project": true, "is_new": true, "confidence": ' . $bad . '}');

        expect($result['ok'])->toBeFalse();
    }
});

it('fails closed when a percentage is ambiguous', function () {
    // 7 could be "7%" or a confused "7 out of 10". Both readings are handled
    // the same way, because the conversion can only ever bias confidence
    // DOWNWARD -- 0.07 is held back as low confidence, exactly as refusing it
    // would be. No input becomes an unearned high confidence.
    $result = parser()->parse('{"is_project": true, "is_new": true, "confidence": 7}');

    expect($result['ok'])->toBeTrue()
        ->and($result['data']['confidence'])->toBe(0.07);
});

it('rejects an uninterpretable is_project', function () {
    $result = parser()->parse('{"is_project": "maybe", "is_new": true, "confidence": 0.9}');

    expect($result['ok'])->toBeFalse();
});

it('rejects a scalar response', function () {
    expect(parser()->parse('"true"')['ok'])->toBeFalse();
});

it('rejects empty output', function () {
    expect(parser()->parse('')['ok'])->toBeFalse()
        ->and(parser()->parse('   ')['ok'])->toBeFalse();
});

// -------------------------------------------------------------- edge cases

it('drops a non-string reason instead of failing the verdict', function () {
    $result = parser()->parse('{"is_project": true, "is_new": true, "confidence": 0.9, "reason": {"a": 1}}');

    // The reason is for a human reading the admin panel; a bad one is not
    // worth discarding a valid verdict over.
    expect($result['ok'])->toBeTrue()
        ->and($result['data']['reason'])->toBeNull();
});

it('bounds an unbounded reason', function () {
    $long = str_repeat('x', 2000);
    $result = parser()->parse('{"is_project": true, "is_new": true, "confidence": 0.9, "reason": "' . $long . '"}');

    expect(mb_strlen($result['data']['reason']))->toBe(500);
});

it('ignores extra fields the model invents', function () {
    $result = parser()->parse(
        '{"is_project": true, "is_new": true, "confidence": 0.9, "project_name": "Invented", "url": "https://fake.example"}',
    );

    // Extraction is a later phase. Accepting invented fields now is how a
    // hallucinated URL reaches the feed.
    expect($result['data'])->not->toHaveKey('project_name')
        ->and($result['data'])->not->toHaveKey('url');
});
