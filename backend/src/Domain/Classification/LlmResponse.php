<?php

declare(strict_types=1);

namespace DevRadar\Domain\Classification;

/**
 * A provider-agnostic model response.
 *
 * Token counts are carried because AI spend and post-retrieval spend belong
 * in one ledger. Cost per published project is the metric that decides
 * whether DevRadar works, and it cannot be computed from two half-ledgers.
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $text,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public bool $fromCache = false,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}

    public function withCacheFlag(bool $fromCache): self
    {
        return new self($this->text, $this->inputTokens, $this->outputTokens, $fromCache, $this->raw);
    }
}
