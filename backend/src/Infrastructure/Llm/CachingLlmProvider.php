<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Llm;

use DevRadar\Domain\Classification\LlmRequest;
use DevRadar\Domain\Classification\LlmResponse;
use DevRadar\Domain\Classification\ModelIdentity;
use DevRadar\Domain\Port\LlmProviderInterface;

/**
 * Skips a paid call when the identical request has already been answered.
 *
 * The key covers the model AND both halves of the prompt, so a prompt change
 * or a model change invalidates every entry automatically -- which is what
 * stops a cache from silently serving decisions made under a prompt nobody
 * is using any more.
 *
 * Cached responses are flagged, so the classifier records zero cost for them
 * and the ledger reflects money actually spent rather than calls made.
 */
final readonly class CachingLlmProvider implements LlmProviderInterface
{
    /** @param \Closure(string):(string|null) $get */
    public function __construct(
        private LlmProviderInterface $inner,
        private \Closure $get,
        private \Closure $put,
        private int $ttlSeconds = 604800,
    ) {}

    public function identity(): ModelIdentity
    {
        return $this->inner->identity();
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $key = 'llm:' . $request->fingerprint($this->inner->identity()->model);
        $cached = ($this->get)($key);

        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);

            if (is_array($decoded) && isset($decoded['text'])) {
                return new LlmResponse(
                    text: (string) $decoded['text'],
                    inputTokens: (int) ($decoded['input_tokens'] ?? 0),
                    outputTokens: (int) ($decoded['output_tokens'] ?? 0),
                    fromCache: true,
                );
            }
        }

        $response = $this->inner->complete($request);

        ($this->put)($key, json_encode([
            'text' => $response->text,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
        ]), $this->ttlSeconds);

        return $response;
    }
}
