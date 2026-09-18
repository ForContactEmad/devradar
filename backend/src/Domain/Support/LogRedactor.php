<?php

declare(strict_types=1);

namespace DevRadar\Domain\Support;

/**
 * Scrubs credentials from anything heading for a log.
 *
 * Lives in the domain because it is pure and shared: the X client and every
 * model provider redact through this one implementation. A second copy would
 * be a second chance to miss a credential pattern.
 *
 * Structured logging makes leaks easy: an exception message, a request
 * context array or a serialised header bag can all carry a bearer token
 * straight into a log aggregator that a wider audience can read.
 *
 * This is a last line of defence, not the first. The first is never putting
 * a token into a log context at all.
 */
final class LogRedactor
{
    private const REDACTED = '[redacted]';

    /** Context keys whose values are always replaced, whatever they contain. */
    private const SENSITIVE_KEYS = [
        'authorization', 'auth', 'bearer', 'bearer_token', 'token',
        'access_token', 'api_key', 'apikey', 'key', 'secret',
        'client_secret', 'consumer_secret', 'password', 'x_api_bearer_token',
    ];

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function context(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = is_array($value)
                ? self::context($value)
                : (is_string($value) ? self::text($value) : $value);
        }

        return $clean;
    }

    /**
     * Redacts credential-shaped substrings from free text.
     *
     * Covers the two ways a token realistically reaches a message: an
     * Authorization header echoed into an error, and a token pasted into a
     * URL query string.
     */
    public static function text(string $message): string
    {
        $patterns = [
            '/(Bearer\s+)[A-Za-z0-9\-._~+\/=%]{8,}/i' => '$1' . self::REDACTED,
            '/([?&](?:access_token|bearer_token|token|api_key|key|secret)=)[^&\s]+/i' => '$1' . self::REDACTED,
            '/(AAAAAAAAAAAAAAAAAAAAA)[A-Za-z0-9%._-]{10,}/' => self::REDACTED,
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $message);
    }
}
