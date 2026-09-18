<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\X;

use DevRadar\Infrastructure\Http\HttpResponse;

/**
 * The rate-limit picture X reports on every response.
 *
 * Verified limits for /2/tweets/search/recent: 450 requests per 15 minutes
 * per app, 300 per user. Rate limits are separate from billing -- you can sit
 * comfortably inside them and still spend a great deal, and you can exhaust
 * them without spending anything extra.
 *
 * Reading these headers lets the client slow down BEFORE a 429 rather than
 * discovering the wall by hitting it.
 */
final readonly class RateLimitState
{
    public function __construct(
        public ?int $limit,
        public ?int $remaining,
        public ?int $resetAt,
    ) {}

    public static function fromResponse(HttpResponse $response): self
    {
        return new self(
            self::intHeader($response, 'x-rate-limit-limit'),
            self::intHeader($response, 'x-rate-limit-remaining'),
            self::intHeader($response, 'x-rate-limit-reset'),
        );
    }

    public function isExhausted(): bool
    {
        return $this->remaining !== null && $this->remaining <= 0;
    }

    /** True when few requests remain and pacing is wise. */
    public function isNearlyExhausted(int $threshold = 5): bool
    {
        return $this->remaining !== null && $this->remaining <= $threshold;
    }

    public function secondsUntilReset(?int $now = null): ?int
    {
        if ($this->resetAt === null) {
            return null;
        }

        $wait = $this->resetAt - ($now ?? time());

        return $wait > 0 ? $wait : 0;
    }

    /** @return array<string, mixed> safe to log: contains no credential */
    public function toLogContext(): array
    {
        return [
            'rate_limit' => $this->limit,
            'rate_remaining' => $this->remaining,
            'rate_reset_in' => $this->secondsUntilReset(),
        ];
    }

    private static function intHeader(HttpResponse $response, string $name): ?int
    {
        $value = $response->header($name);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }
}
