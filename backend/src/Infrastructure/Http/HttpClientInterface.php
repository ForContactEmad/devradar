<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Http;

/**
 * Minimal HTTP transport seam.
 *
 * Two methods: an authenticated GET for data providers, and a JSON POST for
 * model providers. A fake implementation of this interface is what lets the
 * X client and the AI classifier both be tested without a network, a token,
 * or a cent of spend.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string|int> $query
     * @param array<string, string>     $headers
     *
     * @throws HttpTransportException on connection failure or timeout
     */
    public function get(string $url, array $query, array $headers, float $timeoutSeconds): HttpResponse;

    /**
     * JSON POST. Used by model providers.
     *
     * The body is passed as a decoded array and encoded by the adapter, so
     * nothing above this seam has to think about serialisation -- and no
     * caller can accidentally send a body that is not valid JSON.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     *
     * @throws HttpTransportException on connection failure or timeout
     */
    public function post(string $url, array $body, array $headers, float $timeoutSeconds): HttpResponse;

    /**
     * PUT with a raw body.
     *
     * Needed for compliance uploads, which take a plain-text file of one ID
     * per line rather than JSON. Kept separate from post() rather than
     * overloading it, because a method that sometimes encodes its body and
     * sometimes does not is a method whose callers guess.
     *
     * @param array<string, string> $headers
     *
     * @throws HttpTransportException on connection failure or timeout
     */
    public function put(string $url, string $body, array $headers, float $timeoutSeconds): HttpResponse;
}
