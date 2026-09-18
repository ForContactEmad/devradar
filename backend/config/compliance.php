<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Compliance configuration
|--------------------------------------------------------------------------
|
| X's developer policy requires that stored X content reflect the current
| state of that content: when someone deletes a post, protects their account
| or is suspended, it must stop being displayed. This is a legal obligation,
| and non-compliance risks losing API access -- which for DevRadar is fatal.
|
| Verified against X's documentation:
|   - one concurrent batch job per type
|   - upload URL expires after 15 minutes
|   - download URL expires after one week
|   - results list only posts whose status CHANGED
|
*/

return [

    /*
    | How often a stored post is re-checked.
    |
    | 24 hours matches the deletion-propagation expectation. Shorter would
    | re-upload the same IDs for no gain; longer risks displaying content
    | past the window in which it should have gone.
    */
    'recheck_after_hours' => env('DEVRADAR_COMPLIANCE_RECHECK_HOURS', 24),

    /*
    | IDs per batch. The endpoint accepts millions, so this is bounded by our
    | own upload time rather than by the provider -- a seven-day window holds
    | only thousands of posts, so one batch covers everything.
    */
    'batch_size' => env('DEVRADAR_COMPLIANCE_BATCH', 10000),

    'timeout_seconds' => env('DEVRADAR_COMPLIANCE_TIMEOUT', 60),

    /*
    | How often the cycle advances one step.
    |
    | Frequent, because each run does one step of four: at 15 minutes a full
    | cycle completes within the hour, comfortably inside the 24-hour
    | obligation even if a step has to be retried.
    */
    'cron' => env('DEVRADAR_CRON_COMPLY', '*/15 * * * *'),

    /*
    | A cycle that has not completed in this long is a compliance INCIDENT,
    | not a slow job: content that should have gone is still being displayed.
    | Alert on it.
    */
    'stale_cycle_alert_hours' => env('DEVRADAR_COMPLIANCE_ALERT_HOURS', 26),

];
