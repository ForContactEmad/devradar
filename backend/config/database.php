<?php

declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', 'postgres'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'devradar'),
            'username' => env('DB_USERNAME', 'devradar'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',

            /*
            | The schema relies on this. Deduplication fingerprints and text
            | normalisation are Unicode-aware (Arabic posts fingerprint
            | correctly only because of it), and pg_trgm indexes assume it.
            */
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],
    ],

    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],

    'redis' => [
        'client' => 'predis',

        'options' => [
            // Namespaced so a shared Redis cannot collide with another app's
            // queue keys, which would mean one app consuming another's jobs.
            'prefix' => env('REDIS_PREFIX', 'devradar:'),
        ],

        'default' => [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => env('REDIS_PORT', '6379'),
            'password' => env('REDIS_PASSWORD'),
            'database' => '0',
        ],

        'cache' => [
            'host' => env('REDIS_HOST', 'redis'),
            'port' => env('REDIS_PORT', '6379'),
            'password' => env('REDIS_PASSWORD'),
            // A separate database, so `cache:clear` cannot delete queued jobs.
            'database' => '1',
        ],
    ],
];
