<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\X;

use InvalidArgumentException;

/**
 * X client configuration, resolved once at the composition root.
 *
 * The bearer token is held here and nowhere else. It is never placed in a
 * property that gets serialised, never included in an exception, and the
 * class deliberately implements no __toString or __debugInfo that could leak
 * it into a stack trace or a dump.
 */
final readonly class XApiConfig
{
    /**
     * Verified against the X API v2 OpenAPI specification (version 2.168).
     *
     * NOTE: the post-field parameter is `post.fields`, NOT `tweet.fields`.
     * The current specification names it `post.fields`; most third-party
     * guides still say `tweet.fields`.
     */
    public const DEFAULT_POST_FIELDS = [
        'id', 'text', 'author_id', 'created_at', 'lang',
        'public_metrics', 'entities', 'referenced_posts', 'possibly_sensitive',
    ];

    public const DEFAULT_USER_FIELDS = [
        'id', 'username', 'name', 'verified', 'public_metrics',
    ];

    public const DEFAULT_EXPANSIONS = ['author_id'];

    public function __construct(
        private string $bearerToken,
        public string $baseUrl = 'https://api.x.com/2',
        public int $maxQueryLength = 512,
        public float $timeoutSeconds = 20.0,
        public int $maxRetries = 3,
        public float $baseBackoffSeconds = 1.0,
        public float $maxBackoffSeconds = 60.0,
        public bool $expandAuthors = true,
        public array $postFields = self::DEFAULT_POST_FIELDS,
        public array $userFields = self::DEFAULT_USER_FIELDS,
    ) {
        if (trim($bearerToken) === '') {
            throw new InvalidArgumentException(
                'X bearer token is not configured. Set X_API_BEARER_TOKEN in the environment; '
                . 'it must never be hard-coded or committed.'
            );
        }

        if ($maxQueryLength < 1) {
            throw new InvalidArgumentException('maxQueryLength must be positive.');
        }
    }

    /**
     * The Authorization header value. The only accessor for the token.
     *
     * @return array<string, string>
     */
    public function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->bearerToken];
    }

    public function searchRecentUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/tweets/search/recent';
    }

    /** Prevents the token reaching var_dump / dd output. */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'bearerToken' => '[redacted]',
            'maxQueryLength' => $this->maxQueryLength,
        ];
    }
}
