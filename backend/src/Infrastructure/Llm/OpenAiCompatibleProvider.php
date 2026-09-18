<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Llm;

use DevRadar\Domain\Classification\LlmRequest;
use DevRadar\Domain\Classification\LlmResponse;
use DevRadar\Domain\Classification\ModelIdentity;
use DevRadar\Domain\Classification\LlmException;
use DevRadar\Domain\Port\LlmProviderInterface;
use DevRadar\Infrastructure\Http\HttpClientInterface;
use DevRadar\Infrastructure\Http\HttpTransportException;

/**
 * Chat-completions transport for any OpenAI-compatible endpoint.
 *
 * One adapter covers OpenAI, OpenRouter, Together, Groq and a local Ollama or
 * vLLM server, because they all speak the same wire format. Switching between
 * them is a base URL and a model name.
 *
 * Its existence is the proof that the abstraction is real: a second provider
 * that required changes above this layer would mean the first one had leaked.
 */
final readonly class OpenAiCompatibleProvider implements LlmProviderInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.openai.com/v1',
        private string $providerName = 'openai',
        private float $timeoutSeconds = 30.0,
    ) {}

    public function identity(): ModelIdentity
    {
        return new ModelIdentity($this->providerName, $this->model);
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $headers = ['content-type' => 'application/json'];

        // A local server usually needs no key.
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        try {
            $response = $this->http->post(
                rtrim($this->baseUrl, '/') . '/chat/completions',
                [
                    'model' => $this->model,
                    'max_tokens' => $request->maxTokens,
                    'temperature' => $request->temperature,
                    'messages' => [
                        ['role' => 'system', 'content' => $request->systemPrompt],
                        ['role' => 'user', 'content' => $request->userContent],
                    ],
                ],
                $headers,
                $this->timeoutSeconds,
            );
        } catch (HttpTransportException $e) {
            throw new LlmException($e->getMessage(), 'timeout');
        }

        if (! $response->isSuccess()) {
            $body = $response->json();
            $detail = is_string($body['error']['message'] ?? null) ? $body['error']['message'] : '';

            throw match (true) {
                $response->status === 401 || $response->status === 403
                    => new LlmException("Credential rejected ({$response->status}). {$detail}", 'auth', $response->status),
                $response->status === 429
                    => new LlmException("Rate limited ({$response->status}). {$detail}", 'rate_limit', $response->status),
                $response->status >= 500
                    => new LlmException("Provider unavailable ({$response->status}). {$detail}", 'transient', $response->status),
                default
                    => new LlmException("Request rejected ({$response->status}). {$detail}", 'permanent', $response->status),
            };
        }

        $payload = $response->json();

        return new LlmResponse(
            text: (string) ($payload['choices'][0]['message']['content'] ?? ''),
            inputTokens: (int) ($payload['usage']['prompt_tokens'] ?? 0),
            outputTokens: (int) ($payload['usage']['completion_tokens'] ?? 0),
            raw: $payload,
        );
    }
}
