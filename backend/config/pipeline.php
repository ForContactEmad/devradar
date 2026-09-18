<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pipeline scheduling and background processing
|--------------------------------------------------------------------------
|
| Cadence per stage. Every value is a trade between latency and load, and
| each one is explained.
|
| STAGES ARE INDEPENDENT, NOT CHAINED. Each drains its own input state on its
| own schedule. That is what makes the pipeline tolerate partial failure: if
| classification is broken, filtered posts wait while collection, processing
| and scoring all carry on. A chain would have propagated the failure
| backwards and stopped everything.
|
*/

return [

    /*
    | After a stage does real work, nudge the next one instead of waiting for
    | its tick. This turns a multi-hour end-to-end latency into minutes
    | WITHOUT creating a chain: the nudge is dispatched after the fact and its
    | failure is its own. Turn it off to fall back to pure scheduling.
    */
    'follow_on_dispatch' => env('DEVRADAR_FOLLOW_ON_DISPATCH', true),

    /*
    | Cron expressions per stage.
    |
    | collect is the only paid-by-volume stage and runs four times a day,
    | matching the cost model. Everything downstream runs often enough that
    | work does not pile up between collections, and cheaply enough that
    | running on an empty queue costs almost nothing -- each stage's claim is
    | an indexed query that returns no rows.
    |
    | classify and extract are spaced wider than the free stages because each
    | tick can spend money, and a tighter interval would mean more concurrent
    | opportunities to overshoot the budget between guard checks.
    */
    'schedule' => [
        'collect' => env('DEVRADAR_CRON_COLLECT', '0 */6 * * *'),
        'normalize' => env('DEVRADAR_CRON_NORMALIZE', '*/5 * * * *'),
        'deduplicate' => env('DEVRADAR_CRON_DEDUPLICATE', '*/5 * * * *'),
        'filter' => env('DEVRADAR_CRON_FILTER', '*/5 * * * *'),
        'classify' => env('DEVRADAR_CRON_CLASSIFY', '*/15 * * * *'),
        'extract' => env('DEVRADAR_CRON_EXTRACT', '*/15 * * * *'),
        'enrich' => env('DEVRADAR_CRON_ENRICH', '*/30 * * * *'),
        'score' => env('DEVRADAR_CRON_SCORE', '*/10 * * * *'),
    ],

    /*
    | A stage still marked 'running' after this long had its worker killed.
    | Comfortably above the longest stage timeout (900s) plus its lock margin,
    | so a slow-but-alive run is never reaped out from under itself.
    */
    'reap_stale_after_minutes' => env('DEVRADAR_REAP_AFTER_MINUTES', 60),

    /*
    | stage_runs is the highest-volume table in the system: roughly 700 rows a
    | day at these cadences. Pruning keeps it useful for trend queries without
    | letting it dominate the database.
    */
    'prune_stage_runs_after_days' => env('DEVRADAR_PRUNE_STAGE_RUNS_DAYS', 30),

    /*
    | Queue names. Ingestion is separate because it must run with exactly one
    | worker -- two concurrent collection runs mean duplicate paid fetches,
    | and the provider's deduplication is documented as a soft guarantee.
    */
    'queues' => [
        'ingestion' => env('DEVRADAR_INGESTION_QUEUE', 'ingestion'),
        'processing' => env('DEVRADAR_PROCESSING_QUEUE', 'processing'),
    ],

];
