<?php

declare(strict_types=1);

use DevRadar\Domain\Support\LogRedactor;

it('redacts a bearer token from free text', function () {
    $message = 'Request failed with Authorization: Bearer AAAAAAAAAAAAAAAAAAAAAFnz2wAAAAAAxTmQbp';
    $clean = LogRedactor::text($message);

    expect($clean)->not->toContain('AAAAAAAAAAAAAAAAAAAAAFnz2wAAAAAAxTmQbp')
        ->and($clean)->toContain('[redacted]');
});

it('redacts a token pasted into a query string', function () {
    $clean = LogRedactor::text('GET /2/tweets/search/recent?query=rust&access_token=secret-value-123');

    expect($clean)->not->toContain('secret-value-123')
        ->and($clean)->toContain('query=rust');
});

it('redacts sensitive context keys whatever they hold', function () {
    $clean = LogRedactor::context([
        'authorization' => 'Bearer abc123',
        'api_key' => 'k-live-999',
        'page' => 3,
    ]);

    expect($clean['authorization'])->toBe('[redacted]')
        ->and($clean['api_key'])->toBe('[redacted]')
        ->and($clean['page'])->toBe(3);
});

it('redacts nested context', function () {
    $clean = LogRedactor::context(['request' => ['headers' => ['authorization' => 'Bearer xyz']]]);

    expect($clean['request']['headers']['authorization'])->toBe('[redacted]');
});

it('leaves ordinary diagnostic values alone', function () {
    $clean = LogRedactor::context(['provider' => 'x', 'result_count' => 42, 'stop_reason' => 'exhausted']);

    expect($clean['provider'])->toBe('x')
        ->and($clean['result_count'])->toBe(42);
});
