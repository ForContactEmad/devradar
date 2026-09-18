<?php

declare(strict_types=1);

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        /*
        | Synchronous, for tests and for `--dry-run` style local work. Never in
        | a container: a paid collection running inline in a web request is the
        | exact coupling the architecture separates.
        */
        'sync' => ['driver' => 'sync'],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'processing'),

            /*
            | How long a job may run before the queue assumes it died and makes
            | it visible again. MUST exceed the longest stage timeout (900s for
            | classify and extract) or a slow-but-alive job is handed to a
            | second worker while the first is still running -- which for a
            | paid stage means paying twice.
            */
            'retry_after' => 1200,

            'block_for' => 5,
            'after_commit' => true,
        ],
    ],

    /*
    | Failed jobs go to the database, not to nothing.
    |
    | Without this table a job that exhausts its retries is silently discarded,
    | so a failed PAID collection run cannot be replayed and the money is spent
    | with nothing to show for it.
    */
    'failed' => [
        'driver' => 'database-uuids',
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],
];
