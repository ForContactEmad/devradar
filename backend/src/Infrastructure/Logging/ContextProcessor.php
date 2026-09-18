<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Logging;

use DevRadar\Application\Observability\LogContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps the current correlation id and stage onto every record.
 *
 * Done here rather than passed by each caller, because a field that has to be
 * remembered at fifty-four call sites will be missing from some of them --
 * and the ones it is missing from are the failure paths written in a hurry,
 * which are exactly the lines worth correlating.
 */
final readonly class ContextProcessor implements ProcessorInterface
{
    public function __construct(private LogContext $context) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $fields = $this->context->fields();

        if ($fields === []) {
            return $record;
        }

        // Merged into extra rather than context, so a caller's own keys are
        // never silently overwritten by a correlation field.
        return $record->with(extra: [...$record->extra, ...$fields]);
    }
}
