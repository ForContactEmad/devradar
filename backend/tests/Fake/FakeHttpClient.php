<?php

declare(strict_types=1);

namespace Tests\Fake;

use DevRadar\Infrastructure\Http\HttpClientInterface;
use DevRadar\Infrastructure\Http\HttpResponse;
use DevRadar\Infrastructure\Http\HttpTransportException;

/**
 * Scripted HTTP transport for unit tests.
 *
 * No test in DevRadar may touch the real X API: a test suite that costs money
 * is a test suite you stop running. This double is how the entire client --
 * pagination, retry, rate-limit pacing, mapping -- is exercised for free.
 *
 * Queue responses in the order they should be returned. Queue an
 * HttpTransportException instance to simulate a connection failure.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<HttpResponse|HttpTransportException> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, query: array<string, mixed>, body: array<string, mixed>, headers: array<string, string>}> */
    public array $requests = [];

    /** Raw PUT bodies, so an upload's exact payload can be asserted. */
    public array $rawBodies = [];

    public function queue(HttpResponse|HttpTransportException ...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }

        return $this;
    }

    public function get(string $url, array $query, array $headers, float $timeoutSeconds): HttpResponse
    {
        return $this->record('GET', $url, $query, [], $headers);
    }

    public function post(string $url, array $body, array $headers, float $timeoutSeconds): HttpResponse
    {
        return $this->record('POST', $url, [], $body, $headers);
    }

    /**
     * Raw-body PUT, for pre-signed upload URLs.
     *
     * The interface gained this method with the compliance client and the
     * fake never did, so the fake silently stopped satisfying the contract --
     * which nothing noticed until a test tried to exercise an upload.
     */
    public function put(string $url, string $body, array $headers, float $timeoutSeconds): HttpResponse
    {
        $this->rawBodies[] = $body;

        return $this->record('PUT', $url, [], [], $headers);
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function record(string $method, string $url, array $query, array $body, array $headers): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'query', 'body', 'headers');

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \RuntimeException(
                'FakeHttpClient ran out of queued responses after ' . count($this->requests) . ' request(s). '
                . 'The code under test made more requests than the test expected.'
            );
        }

        if ($next instanceof HttpTransportException) {
            throw $next;
        }

        return $next;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    /** @return array<string, mixed> */
    public function lastQuery(): array
    {
        $last = end($this->requests);

        return $last === false ? [] : $last['query'];
    }

    /** @return array<string, mixed> */
    public function lastBody(): array
    {
        $last = end($this->requests);

        return $last === false ? [] : $last['body'];
    }
}
