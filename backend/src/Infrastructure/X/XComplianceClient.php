<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\X;

use DateTimeImmutable;
use DevRadar\Domain\Compliance\ComplianceEvent;
use DevRadar\Domain\Compliance\ComplianceFinding;
use DevRadar\Domain\Compliance\ComplianceJob;
use DevRadar\Domain\Compliance\ComplianceJobState;
use DevRadar\Domain\Port\ComplianceProviderInterface;
use DevRadar\Domain\Support\LogRedactor;
use DevRadar\Infrastructure\Http\HttpClientInterface;
use DevRadar\Infrastructure\Http\HttpTransportException;
use RuntimeException;

/**
 * X batch compliance transport.
 *
 * Verified against X's current documentation:
 *
 *   POST /2/compliance/jobs        {"type":"tweets","name":"..."}
 *   PUT  <upload_url>              text/plain, one ID per line
 *   GET  /2/compliance/jobs/{id}   poll: created|in_progress|complete|failed|expired
 *   GET  <download_url>            JSON Lines, one object per affected post
 *
 * The upload and download URLs are pre-signed and issued by the job creation
 * call. They are not on api.x.com and carry their own credentials in the
 * query string, which is precisely why they are never logged.
 *
 * THIS CLIENT THROWS, unlike the others. Everywhere else in DevRadar a
 * provider failure becomes a recorded outcome and the pipeline moves on. Here
 * a silent failure means content we are required to remove stays up, so the
 * caller is made to handle it.
 */
final readonly class XComplianceClient implements ComplianceProviderInterface
{
    public function __construct(
        private HttpClientInterface $http,
        private string $bearerToken,
        private string $baseUrl = 'https://api.x.com/2',
        private float $timeoutSeconds = 30.0,
    ) {}

    public function name(): string
    {
        return 'x';
    }

    public function createJob(string $name): ComplianceJob
    {
        $response = $this->post(
            rtrim($this->baseUrl, '/') . '/compliance/jobs',
            ['type' => 'tweets', 'name' => $name],
        );

        $data = $response['data'] ?? null;

        if (! is_array($data) || ! isset($data['id'])) {
            throw new RuntimeException('Compliance job creation returned no job id.');
        }

        return $this->mapJob($data);
    }

    /** @param list<string> $postIds */
    public function uploadIds(ComplianceJob $job, array $postIds): bool
    {
        if ($job->uploadUrl === null) {
            throw new RuntimeException('Compliance job has no upload URL.');
        }

        if ($postIds === []) {
            return true;
        }

        try {
            // One ID per line, as documented. Sent as a raw body rather than
            // JSON: the pre-signed URL expects text/plain.
            $response = $this->http->put(
                $job->uploadUrl,
                implode("\n", $postIds) . "\n",
                ['Content-Type' => 'text/plain'],
                $this->timeoutSeconds,
            );
        } catch (HttpTransportException $e) {
            throw new RuntimeException('Compliance upload failed: ' . LogRedactor::text($e->getMessage()));
        }

        return $response->isSuccess();
    }

    public function jobStatus(string $providerJobId): ComplianceJob
    {
        $response = $this->get(rtrim($this->baseUrl, '/') . '/compliance/jobs/' . urlencode($providerJobId));

        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException("Compliance job {$providerJobId} returned no data.");
        }

        return $this->mapJob($data);
    }

    /** @return list<ComplianceFinding> */
    public function downloadResults(ComplianceJob $job): array
    {
        if ($job->downloadUrl === null) {
            throw new RuntimeException('Compliance job has no download URL.');
        }

        try {
            $response = $this->http->get($job->downloadUrl, [], [], $this->timeoutSeconds);
        } catch (HttpTransportException $e) {
            throw new RuntimeException('Compliance download failed: ' . LogRedactor::text($e->getMessage()));
        }

        if (! $response->isSuccess()) {
            throw new RuntimeException(sprintf('Compliance download returned status %d.', $response->status));
        }

        return $this->parseJsonLines($response->body);
    }

    /**
     * Parse JSON Lines.
     *
     * An empty body is the ORDINARY case: it means none of the uploaded posts
     * had a compliance event. A malformed line is skipped rather than
     * abandoning the file, because the remaining lines name posts we are
     * required to stop displaying.
     *
     * @return list<ComplianceFinding>
     */
    private function parseJsonLines(string $body): array
    {
        $findings = [];

        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                continue;
            }

            $finding = $this->toFinding($decoded);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * One JSON Lines record to a finding.
     *
     * An unrecognised event label yields null rather than a default of
     * "deleted": removing content on an event nobody defined would be worse
     * than leaving it and re-checking next cycle.
     *
     * @param array<string, mixed> $line
     */
    private function toFinding(array $line): ?ComplianceFinding
    {
        $id = $line['id'] ?? null;

        if ((! is_string($id) && ! is_int($id)) || preg_match('/^[0-9]{1,19}$/', (string) $id) !== 1) {
            return null;
        }

        $event = ComplianceEvent::tryFromLabel(is_string($line['reason'] ?? null) ? $line['reason'] : null);

        if ($event === null) {
            return null;
        }

        return new ComplianceFinding(
            postId: (string) $id,
            event: $event,
            reason: is_string($line['reason'] ?? null) ? $line['reason'] : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function mapJob(array $data): ComplianceJob
    {
        return new ComplianceJob(
            id: null,
            providerJobId: (string) $data['id'],
            state: ComplianceJobState::tryFrom((string) ($data['status'] ?? 'created')) ?? ComplianceJobState::Created,
            uploadUrl: isset($data['upload_url']) ? (string) $data['upload_url'] : null,
            downloadUrl: isset($data['download_url']) ? (string) $data['download_url'] : null,
            uploadExpiresAt: $this->date($data['upload_expires_at'] ?? null),
            downloadExpiresAt: $this->date($data['download_expires_at'] ?? null),
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        try {
            $response = $this->http->get($url, [], $this->headers(), $this->timeoutSeconds);
        } catch (HttpTransportException $e) {
            throw new RuntimeException('Compliance request failed: ' . LogRedactor::text($e->getMessage()));
        }

        if (! $response->isSuccess()) {
            throw new RuntimeException(sprintf('Compliance request returned status %d.', $response->status));
        }

        return $response->json();
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $url, array $body): array
    {
        try {
            $response = $this->http->post($url, $body, $this->headers(), $this->timeoutSeconds);
        } catch (HttpTransportException $e) {
            throw new RuntimeException('Compliance request failed: ' . LogRedactor::text($e->getMessage()));
        }

        if (! $response->isSuccess()) {
            throw new RuntimeException(sprintf('Compliance request returned status %d.', $response->status));
        }

        return $response->json();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->bearerToken,
            'Content-Type' => 'application/json',
        ];
    }
}
