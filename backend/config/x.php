<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| X API client configuration
|--------------------------------------------------------------------------
|
| Declarative values only. The bearer token is read from the environment and
| never appears in this file, in version control, or in a built image.
|
| All parameter names and limits below were verified against the X API v2
| OpenAPI specification (version 2.168) and the current developer docs.
| Re-verify before trusting them: X has changed both pricing and field names.
|
*/

return [

    'base_url' => env('X_API_BASE_URL', 'https://api.x.com/2'),

    'bearer_token' => env('X_API_BEARER_TOKEN'),

    /*
    | The OpenAPI schema declares maxLength 4096, but the product docs state
    | 512 for recent search (4,096 for Enterprise). The stricter limit is
    | enforced, because a request rejected for length still costs a round trip
    | and the discrepancy is unresolved in X's own documentation.
    */
    'max_query_length' => env('X_MAX_QUERY_LENGTH', 512),

    'timeout_seconds' => env('X_TIMEOUT_SECONDS', 20),

    'retry' => [
        'max_attempts' => env('X_MAX_RETRIES', 3),
        'base_backoff_seconds' => env('X_BASE_BACKOFF_SECONDS', 1),
        'max_backoff_seconds' => env('X_MAX_BACKOFF_SECONDS', 60),
    ],

    /*
    | Author expansion returns a user object per distinct author, billed
    | separately and at twice the per-resource rate of a post. Turning it off
    | is a real cost lever; leaving it on is what populates the author cache.
    */
    'expand_authors' => env('X_EXPAND_AUTHORS', true),

    /*
    | Paging ceilings. These are DevRadar's brakes, not the provider's, and
    | they are the in-code half of the two-brake spend design.
    */
    'paging' => [
        'page_size' => env('X_PAGE_SIZE', 100),
        'max_pages_per_run' => env('X_MAX_PAGES_PER_RUN', 5),
    ],

];
