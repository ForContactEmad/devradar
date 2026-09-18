<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Statistics and trends
|--------------------------------------------------------------------------
*/

return [

    /*
    | Below this many projects in a period, no direction is claimed.
    |
    | DevRadar publishes on the order of ten projects a week. A jump from two
    | to six is a 200% rise by arithmetic and noise by any honest reading, and
    | a dashboard that reports it as a trend teaches its reader to ignore the
    | dashboard. Eight is a judgement, not a measurement -- revisit it once
    | there is a month of real volume to look at.
    */
    'minimum_sample' => env('DEVRADAR_TREND_MIN_SAMPLE', 8),

    /*
    | Fractional change treated as drift rather than movement.
    |
    | Without a dead band the arrow flips between rising and falling on every
    | rescore, which looks like activity and carries no information.
    */
    'dead_band' => env('DEVRADAR_TREND_DEAD_BAND', 0.05),

    /* How many categories, technologies and projects the report returns. */
    'top_limit' => env('DEVRADAR_TREND_TOP_LIMIT', 10),

];
