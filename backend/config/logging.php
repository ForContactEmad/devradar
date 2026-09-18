<?php

declare(strict_types=1);

use DevRadar\Infrastructure\Logging\ContextProcessor;
use DevRadar\Infrastructure\Logging\RedactingProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

/*
|--------------------------------------------------------------------------
| Logging
|--------------------------------------------------------------------------
|
| THIS FILE DID NOT EXIST. The application emitted fifty-four distinct
| structured events and then handed them to whatever Laravel defaulted to --
| a single line-formatted file. Structured logging whose output is not
| structured is half a feature: the context array ends up as a blob of JSON
| glued to the end of a human-readable line, which neither a person nor a log
| aggregator can query properly.
|
| JSON TO STDOUT, AND NOTHING ELSE. Every service runs in a container, and the
| container runtime already collects stdout, rotates it and ships it wherever
| it is configured to go. Writing to a file inside a container means the logs
| die with the container and two systems now rotate them.
|
| NO MONITORING INFRASTRUCTURE. No Prometheus, no OpenTelemetry collector, no
| ELK. For an MVP with one operator, the questions that matter -- when did a
| run start, how many posts were collected, how many reached the model, what
| did it cost, why did it fail -- are answered by two database tables
| (search_runs, stage_runs) and a `devradar:status` command that queries them.
| Those are already built. Adding a metrics pipeline would be more moving
| parts to operate than the thing being observed.
|
| Revisit that when there is more than one operator, or when someone needs an
| alert rather than an answer.
|
*/

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'json')),
            // Without this, a handler that fails silently swallows the record.
            'ignore_exceptions' => false,
        ],

        /*
        | The channel everything uses in a container.
        |
        | One JSON object per line, which is what every aggregator expects and
        | what `docker compose logs | jq` can read directly.
        */
        'json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stdout'],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                // Exception stack traces are included: "why did a failure
                // occur" is unanswerable without one, and these logs are not
                // user-facing.
                'includeStacktraces' => true,
            ],
            'processors' => [
                // Order matters. Context is stamped first so the redactor
                // sees the correlation fields too -- they are generated
                // locally and safe, but a processor that ran after redaction
                // could reintroduce an unredacted value.
                ContextProcessor::class,
                RedactingProcessor::class,
                PsrLogMessageProcessor::class,
            ],
        ],

        /*
        | Human-readable, for someone running artisan in a terminal. Still
        | redacted: the whole point of doing it in a processor is that no
        | channel can forget.
        */
        'cli' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
            'processors' => [ContextProcessor::class, RedactingProcessor::class],
        ],

        /*
        | Local file, for development without a container. Not the default:
        | see the note above about logs dying with the container.
        */
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/devradar.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 7,
            'formatter' => JsonFormatter::class,
            'processors' => [ContextProcessor::class, RedactingProcessor::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],

        /*
        | Used by the test suite so a test run does not emit anything.
        */
        'emergency' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow query threshold, milliseconds
    |--------------------------------------------------------------------------
    |
    | Individual queries are NOT logged. At the pipeline's volumes that would
    | be thousands of lines an hour saying nothing, and the useful signal --
    | which query got slow -- would be buried in it.
    |
    | Only queries over this threshold are recorded, with the SQL and the
    | bindings count but NOT the bindings themselves: a binding can be a post's
    | text, and the point of a slow-query log is the shape of the query.
    */
    'slow_query_ms' => env('LOG_SLOW_QUERY_MS', 500),

];
