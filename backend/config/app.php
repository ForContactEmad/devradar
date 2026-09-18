<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'DevRadar'),
    'env' => env('APP_ENV', 'production'),

    /*
    | Defaults to FALSE. A debug flag that defaults on is how a stack trace
    | ends up on a production error page, and the one deployment that forgets
    | to set it is the one that leaks.
    */
    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost:8000'),

    /*
    | UTC everywhere. The pipeline reasons about a rolling seven-day window and
    | X reports timestamps in UTC; a local timezone here would put the window
    | boundary in a different place than the provider's.
    */
    'timezone' => 'UTC',

    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',

    /*
    | Required. `php artisan key:generate` writes it. Without it, encryption
    | and signed URLs fail at runtime rather than at boot.
    */
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
    ],

    /*
    | لا يوجد مفتاح 'providers' ولا 'aliases' هنا، عن قصد.
    |
    | في Laravel 11/12 يأتي المزوّدون الأساسيون من
    | ServiceProvider::defaultProviders() عبر ApplicationBuilder، ومزوّدو
    | التطبيق من bootstrap/providers.php. وجود مصفوفة فارغة هنا يَجُبّ
    | الافتراضيات ويمسح DatabaseServiceProvider نفسه -- وهو ما أوقف أول
    | تشغيل حقيقي للمشروع بخطأ: Target class [db] does not exist.
    */
];
