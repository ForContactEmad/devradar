<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\X;

use DevRadar\Domain\Support\LogRedactor;

use DevRadar\Infrastructure\Http\HttpResponse;

/**
 * Turns an HTTP status into a decision about what to do next.
 *
 * The classification that matters most is 4xx-other -> permanent. Retrying a
 * malformed query cannot succeed, and each attempt is a round trip that may
 * still return billable resources. Blind retry is the single most expensive
 * bug this layer can have.
 */
final class XErrorClassifier
{
    public function classify(HttpResponse $response): XApiException
    {
        $status = $response->status;
        $detail = $this->extractDetail($response);

        return match (true) {
            $status === 401 => new XApiException(
                "X rejected the credential (401). Check X_API_BEARER_TOKEN. {$detail}",
                'auth',
                $status,
            ),

            $status === 403 => new XApiException(
                "X refused the request (403) -- the app may lack access to this endpoint. {$detail}",
                'auth',
                $status,
            ),

            $status === 429 => new XApiException(
                "Rate limit exceeded (429). {$detail}",
                'rate_limit',
                $status,
                $this->retryAfter($response),
            ),

            $status === 400 || $status === 404 || $status === 422 => new XApiException(
                "X rejected the request ({$status}) -- not retryable. {$detail}",
                'permanent',
                $status,
            ),

            $status >= 500 => new XApiException(
                "X server error ({$status}).",
                'transient',
                $status,
            ),

            default => new XApiException(
                "Unexpected response from X ({$status}). {$detail}",
                'permanent',
                $status,
            ),
        };
    }

    /**
     * Seconds to wait before retrying a 429.
     *
     * Prefers `x-rate-limit-reset`, which X documents as a Unix timestamp of
     * the window reset, and falls back to a standard Retry-After.
     */
    private function retryAfter(HttpResponse $response): ?int
    {
        $reset = $response->header('x-rate-limit-reset');

        if ($reset !== null && ctype_digit($reset)) {
            $wait = (int) $reset - time();

            return $wait > 0 ? $wait : 1;
        }

        $retryAfter = $response->header('retry-after');

        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        return null;
    }

    /**
     * Pulls a human-readable reason out of X's Problem payload without
     * echoing the whole body, which can be large and may include the query.
     */
    private function extractDetail(HttpResponse $response): string
    {
        $body = $response->json();

        foreach (['detail', 'title', 'message'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                return LogRedactor::text($body[$key]);
            }
        }

        if (isset($body['errors'][0]['message']) && is_string($body['errors'][0]['message'])) {
            return LogRedactor::text($body['errors'][0]['message']);
        }

        return '';
    }
}
