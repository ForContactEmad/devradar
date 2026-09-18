<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Processing configuration
|--------------------------------------------------------------------------
|
| Declarative only. Normalization and deduplication rules live in the domain
| classes, which are pure; this file holds the few policy choices that are
| genuinely arguable.
|
*/

return [

    /*
    | Reject posts with no resolvable link.
    |
    | A launch announcement without one cannot be acted on and cannot be
    | deduplicated by URL. This is a policy choice, not a fact, so it is
    | configurable -- turn it off if a labelling pass shows the query set is
    | surfacing valuable link-free announcements.
    */
    'require_link' => env('DEVRADAR_REQUIRE_LINK', true),

    /*
    | How many posts each stage claims per invocation. Small enough that a
    | crash loses little work, large enough that the per-batch overhead is
    | not the dominant cost.
    */
    'batch_size' => [
        'normalize' => env('DEVRADAR_NORMALIZE_BATCH', 200),
        'deduplicate' => env('DEVRADAR_DEDUPLICATE_BATCH', 200),
    ],

];
