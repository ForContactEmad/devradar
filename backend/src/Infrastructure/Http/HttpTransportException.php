<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Http;

use RuntimeException;

/**
 * A request that never produced a response: DNS failure, connection refused,
 * timeout. Always transient, always safe to retry -- no charge is incurred
 * when no response is returned.
 */
final class HttpTransportException extends RuntimeException {}
