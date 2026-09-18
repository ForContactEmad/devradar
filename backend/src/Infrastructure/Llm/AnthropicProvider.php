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
 * Anthropic Messages API transport.
 *
 * Carries no prompt text and no domain knowledge: it maps one request shape
 * to another and back. Everything interesting about classification lives
 * above this class.
 *
 * The API key never leaves this object except as a request header, and never
 * appears in an exception -- LlmException redacts on construction.
 */
final readonly class AnthropicProvider implements LlmProviderInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private string $apiKey,
        private string $model,
        private string $baseUrl = 'https://api.anthropic.com/v1',
        private string $apiVersion = '2023-06-01',
        private float $timeoutSeconds = 30.0,
    ) {}

    public function identity(): ModelIdentity
    {
        return new ModelIdentity('anthropic', $this->model);
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        try {
            $response = $this->http->post(
                rtrim($this->baseUrl, '/') . '/messages',
                [
                    'model' => $this->model,
                    'max_tokens' => $request->maxTokens,
                    'temperature' => $request->temperature,
                    // System instructions are a separate field, not merged
                    // into the message. That separation is the structural
                    // half of the prompt-injection defence.
                    'system' => $request->systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $request->userContent],
                    ],
                ],
                [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => $this->apiVersion,
                    'content-type' => 'application/json',
                ],
                $this->timeoutSeconds,
            );
        } catch (HttpTransportException $e) {
            // No response means the call may not have been billed and is safe
            // to retry.
            throw new LlmException($e->getMessage(), 'timeout');
        }

        if (! $response->isSuccess()) {
            throw $this->classify($response->status, $response->json(), $response->header('retry-after'));
        }

        return $this->mapResponse($response->json());
    }

    /** @param array<string, mixed> $payload */
    private function mapResponse(array $payload): LlmResponse
    {
        $text = '';

        // Content is a list of blocks; only text blocks are of interest, and
        // there may be more than one.
        foreach ($payload['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return new LlmResponse(
            text: $text,
            inputTokens: (int) ($payload['usage']['input_tokens'] ?? 0),
            outputTokens: (int) ($payload['usage']['output_tokens'] ?? 0),
            raw: $payload,
        );
    }

    /** @param array<string, mixed> $body */
    private function classify(int $status, array $body, ?string $retryAfter): LlmException
    {
        $detail = is_string($body['error']['message'] ?? null) ? $body['error']['message'] : '';

        return match (true) {
            $status === 401 || $status === 403 => new LlmException(
                "Model provider rejected the credential ({$status}). {$detail}",
                'auth',
                $status,
            ),
            $status === 429 => new LlmException(
                "Model provider rate limit ({$status}). {$detail}",
                'rate_limit',
                $status,
                $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null,
            ),
            // 400 is a malformed request: retrying sends the same malformed
            // request and pays for it again.
            $status === 400 || $status === 404 || $status === 422 => new LlmException(
                "Model provider rejected the request ({$status}). {$detail}",
                'permanent',
                $status,
            ),
            $status === 529 || $status >= 500 => new LlmException(
                "Model provider unavailable ({$status}). {$detail}",
                'transient',
                $status,
            ),
            default => new LlmException("Unexpected model provider response ({$status}). {$detail}", 'permanent', $status),
        };
    }
}
