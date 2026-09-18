<?php

declare(strict_types=1);

return [
    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],

        'redis' => [
            'driver' => 'redis',
            // The `cache` connection points at a separate Redis database, so
            // clearing the cache cannot delete queued jobs.
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'devradar_cache'),
];
