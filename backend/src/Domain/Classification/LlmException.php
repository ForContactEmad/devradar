<?php

declare(strict_types=1);

namespace DevRadar\Domain\Classification;

use DevRadar\Domain\Support\LogRedactor;
use RuntimeException;

/**
 * A model-provider failure, classified by what the caller should do.
 *
 *   transient  - retry with backoff
 *   rate_limit - retry after the reset, not immediately
 *   permanent  - never retry; the request is wrong and will stay wrong
 *   auth       - never retry; a human must fix the credential
 *   timeout    - retry; no response means the work may not have completed
 *
 * Messages are redacted on construction. The redactor is shared with the X
 * client rather than duplicated, because a second implementation is a second
 * chance to miss a credential pattern.
 */
final class LlmException extends RuntimeException
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
        return in_array($this->errorClass, ['transient', 'rate_limit', 'timeout'], true);
    }
}
