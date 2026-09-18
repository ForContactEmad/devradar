<?php

declare(strict_types=1);

namespace DevRadar\Domain\Classification;

/**
 * A provider-agnostic model request.
 *
 * System and user content are separate fields, not one concatenated string,
 * because the separation is what makes the prompt-injection defence real: the
 * instructions live in the system role, the attacker-controlled post text
 * lives in the user role, and no provider adapter can accidentally merge them.
 */
final readonly class LlmRequest
{
    public function __construct(
        public string $systemPrompt,
        public string $userContent,
        public int $maxTokens = 512,
        public float $temperature = 0.0,
        /** Stable hash of the request, used for response caching. */
        public ?string $cacheKey = null,
    ) {}

    public function fingerprint(string $model): string
    {
        return hash('sha256', $model . "\0" . $this->systemPrompt . "\0" . $this->userContent);
    }
}
