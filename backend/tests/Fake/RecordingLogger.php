<?php

declare(strict_types=1);

namespace Tests\Fake;

use Psr\Log\LoggerInterface;

/**
 * Captures log records so tests can assert on structured output -- and, more
 * importantly, prove that no credential ever reaches a log.
 */
final class RecordingLogger implements LoggerInterface
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    public function emergency(\Stringable|string $m, array $c = []): void { $this->log('emergency', $m, $c); }
    public function alert(\Stringable|string $m, array $c = []): void { $this->log('alert', $m, $c); }
    public function critical(\Stringable|string $m, array $c = []): void { $this->log('critical', $m, $c); }
    public function error(\Stringable|string $m, array $c = []): void { $this->log('error', $m, $c); }
    public function warning(\Stringable|string $m, array $c = []): void { $this->log('warning', $m, $c); }
    public function notice(\Stringable|string $m, array $c = []): void { $this->log('notice', $m, $c); }
    public function info(\Stringable|string $m, array $c = []): void { $this->log('info', $m, $c); }
    public function debug(\Stringable|string $m, array $c = []): void { $this->log('debug', $m, $c); }

    /** Everything ever logged, flattened, for leak assertions. */
    public function dump(): string
    {
        return json_encode($this->records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /** @return list<array<string, mixed>> */
    public function withMessage(string $message): array
    {
        return array_values(array_filter($this->records, fn ($r) => $r['message'] === $message));
    }
}
