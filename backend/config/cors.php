<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| THERE WAS NO POLICY. Without this file the behaviour was whatever the
| framework happened to default to -- which may well have been permissive,
| and either way was nobody's decision.
|
| The public API is deliberately open: it serves one read-only feed of public
| information, and a dashboard, a script and a feed reader should all be able
| to call it. That is a choice, and the two lines that make it safe are:
|
|   supports_credentials => false
|     With credentials enabled, a wildcard origin would let any site read
|     responses using a visitor's cookies. This API has no cookie auth and
|     must never acquire one without revisiting this file.
|
|   admin paths are NOT listed
|     Operational endpoints are excluded from CORS entirely, so a browser on
|     another origin cannot reach them even holding a token.
|
*/

return [

    // Public read API only. `api/v1/admin/*` is deliberately absent.
    'paths' => ['api/v1/projects', 'api/v1/projects/*', 'api/v1/categories',
        'api/v1/technologies', 'api/v1/stats', 'api/v1/trends'],

    'allowed_methods' => ['GET', 'OPTIONS'],

    /*
    | Public data, so any origin may read it. Narrow this to your own domains
    | if the API ever serves anything that is not already public.
    */
    'allowed_origins' => explode(',', (string) env('DEVRADAR_CORS_ORIGINS', '*')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Request-Id'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 3600,

    /*
    | MUST STAY FALSE while any origin is allowed. Enabling both is the
    | classic misconfiguration that turns a public API into a cross-origin
    | read of authenticated data.
    */
    'supports_credentials' => false,

];
