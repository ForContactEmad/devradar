<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Logging;

use DevRadar\Domain\Support\LogRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redacts credentials from every record on the way out.
 *
 * WHY THIS IS A PROCESSOR AND NOT A CONVENTION. LogRedactor already existed
 * and was applied by hand: nine calls to ::text() on exception messages, and
 * ::context() never called at all. So every `logger->warning('event', [...])`
 * in the application wrote its context array verbatim, and the only thing
 * standing between a token and the log file was each author remembering.
 *
 * Verified before writing this: a context array containing
 * `?access_token=ghp_...` passed straight through.
 *
 * As a processor it runs on the whole record — message and context, every
 * channel, every call site — so redaction stops being something to remember
 * and becomes something that happens.
 *
 * DEFENCE IN DEPTH, not a licence to log secrets. The providers still avoid
 * putting credentials in messages; this is the net under them.
 */
final readonly class RedactingProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: LogRedactor::text($record->message),
            context: LogRedactor::context($record->context),
            extra: LogRedactor::context($record->extra),
        );
    }
}
