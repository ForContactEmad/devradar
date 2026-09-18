<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\X;

use DevRadar\Domain\Support\LogRedactor;

use RuntimeException;

/**
 * A provider failure the caller must decide about.
 *
 * errorClass maps onto the error taxonomy from the architecture phase and is
 * what determines retry behaviour:
 *
 *   transient  - back off and retry, bounded
 *   permanent  - never retry; retrying costs money and cannot succeed
 *   auth       - never retry; a human must fix the credential
 *   rate_limit - retry after the reset instant, not immediately
 *
 * Messages are redacted before construction. An exception must never carry a
 * credential into a log or a stack trace.
 */
final class XApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorClass,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(LogRedactor::text($message));
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorClass, ['transient', 'rate_limit'], true);
    }
}
