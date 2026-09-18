<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * The only class in DevRadar that performs real network I/O.
 *
 * It contains no retry logic, no rate-limit handling and no mapping. Those
 * live in framework-free classes that this adapter feeds, which is why they
 * can be tested without a container.
 */
final readonly class LaravelHttpClient implements HttpClientInterface
{
    public function __construct(private Factory $http) {}

    public function post(string $url, array $body, array $headers, float $timeoutSeconds): HttpResponse
    {
        return $this->send(
            fn () => $this->http->withHeaders($headers)->timeout($timeoutSeconds)->asJson()->post($url, $body),
        );
    }

    public function put(string $url, string $body, array $headers, float $timeoutSeconds): HttpResponse
    {
        return $this->send(
            fn () => $this->http->withHeaders($headers)->timeout($timeoutSeconds)->withBody($body, $headers['Content-Type'] ?? 'text/plain')->put($url),
        );
    }


    public function get(string $url, array $query, array $headers, float $timeoutSeconds): HttpResponse
    {
        return $this->send(
            fn () => $this->http->withHeaders($headers)->timeout($timeoutSeconds)->get($url, $query),
        );
    }

    private function send(\Closure $request): HttpResponse
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            // Message only. The exception must never carry the request
            // headers, which hold the bearer token.
            throw new HttpTransportException('Connection to provider failed: ' . $e->getMessage());
        } catch (Throwable $e) {
            throw new HttpTransportException('Transport failure: ' . $e->getMessage());
        }

        $normalised = [];
        foreach ($response->headers() as $name => $values) {
            $normalised[strtolower((string) $name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return new HttpResponse($response->status(), $response->body(), $normalised);
    }
}
