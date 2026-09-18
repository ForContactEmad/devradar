<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Http;

/**
 * A transport-agnostic HTTP response.
 *
 * Deliberately tiny. Its purpose is to keep Guzzle, Laravel's HTTP client and
 * curl out of everything that parses, retries or maps -- which is what makes
 * those components unit-testable with no network and no framework.
 */
final readonly class HttpResponse
{
    /**
     * @param array<string, string> $headers lower-cased header names
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
