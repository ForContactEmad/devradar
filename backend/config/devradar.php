<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DevRadar operational configuration
|--------------------------------------------------------------------------
|
| Declarative values only. No conditionals, no computation, no business
| rules. Every value is read at the composition root and injected into the
| services that need it; business logic never reads configuration directly.
|
| Business DATA that changes often -- the query set, curated account list,
| blocklist, ranking weights and prompt templates -- deliberately does NOT
| live here. It lives in the database, versioned, editable from the admin
| panel without a deploy, and stamped onto every pipeline run.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Budget guard
    |----------------------------------------------------------------------
    |
    | The first of two independent brakes on spend. The second is the
    | spending limit configured in the X developer console. Both must be
    | set. Neither is a substitute for the other.
    |
    */
    'budget' => [
        'monthly_ceiling_usd' => env('DEVRADAR_MONTHLY_CEILING_USD', 40.0),
        'max_posts_per_run' => env('DEVRADAR_MAX_POSTS_PER_RUN', 300),
        'alert_threshold_percent' => env('DEVRADAR_BUDGET_ALERT_PERCENT', 70),
        'halt_on_ceiling' => env('DEVRADAR_HALT_ON_CEILING', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Post source pricing
    |----------------------------------------------------------------------
    |
    | Used by the cost calculator to convert resources returned into spend.
    | These mirror published X rates and are configurable because X has
    | repriced its API more than once. Verify against the developer console
    | before trusting them.
    |
    */
    'pricing' => [
        'post_read_usd' => env('DEVRADAR_PRICE_POST_READ_USD', 0.005),
        'user_read_usd' => env('DEVRADAR_PRICE_USER_READ_USD', 0.010),
    ],

    /*
    |----------------------------------------------------------------------
    | Discovery window
    |----------------------------------------------------------------------
    |
    | DevRadar is a rolling seven-day product. Recent search cannot reach
    | further back, so a missed ingestion window is permanent data loss.
    |
    */
    'window' => [
        'days' => env('DEVRADAR_WINDOW_DAYS', 7),
    ],

    /*
    |----------------------------------------------------------------------
    | Pipeline
    |----------------------------------------------------------------------
    */
    'pipeline' => [
        'ingestion_queue' => env('DEVRADAR_INGESTION_QUEUE', 'ingestion'),
        'processing_queue' => env('DEVRADAR_PROCESSING_QUEUE', 'processing'),
        'classification_batch_size' => env('DEVRADAR_CLASSIFY_BATCH', 20),
        'enrichment_enabled' => env('DEVRADAR_ENRICHMENT_ENABLED', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Classification
    |----------------------------------------------------------------------
    |
    | Precision is preferred over recall: a missed project is invisible, a
    | junk project on the front page is what users judge us on.
    |
    */
    'classification' => [
        /*
        | DEAD KEY. Nothing reads devradar.ai.provider; the application reads
        | ai.provider. Two sources of truth for one setting disagreed on the
        | default ('anthropic' here, 'mock' there), and the one that was never
        | read held the sane value. Kept as a comment rather than deleted so
        | the next reader does not re-add it.
        */
        'model' => env('DEVRADAR_AI_MODEL'),
        'min_confidence' => env('DEVRADAR_MIN_CONFIDENCE', 0.75),
        'cache_responses' => env('DEVRADAR_AI_CACHE', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Compliance
    |----------------------------------------------------------------------
    |
    | Stored post data must reflect the current state of content on the
    | source platform. Deletions propagate within 24 hours. A failure here
    | is escalated immediately, unlike any other error class.
    |
    */
    'compliance' => [
        'purge_within_hours' => env('DEVRADAR_PURGE_WITHIN_HOURS', 24),
        'reconcile_batch_size' => env('DEVRADAR_RECONCILE_BATCH', 100),
    ],

    /*
    |----------------------------------------------------------------------
    | Administrative access
    |----------------------------------------------------------------------
    |
    | Shared token for the operational endpoints. FAIL-CLOSED: when unset or
    | shorter than 32 characters, every admin request is denied. A guard that
    | switches off when unconfigured is not a guard.
    |
    | Generate with: php -r "echo bin2hex(random_bytes(32));"
    */
    'admin_token' => env('DEVRADAR_ADMIN_TOKEN', ''),

    /*
    |----------------------------------------------------------------------
    | Rate limits, requests per minute per IP
    |----------------------------------------------------------------------
    |
    | Search is tighter than general reads because each search is a real
    | query the cache cannot always absorb. Admin is low because it serves
    | one operator.
    */
    'rate_limits' => [
        'public' => env('DEVRADAR_RATE_PUBLIC', 120),
        'search' => env('DEVRADAR_RATE_SEARCH', 30),
        'admin' => env('DEVRADAR_RATE_ADMIN', 20),
    ],

];
