<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ranking configuration
|--------------------------------------------------------------------------
|
| Every number the scoring engine uses lives here, and every one has a reason
| written next to it. There are no tuned constants hidden in the classes.
|
| ALL OF THESE ARE STARTING POSITIONS, NOT MEASUREMENTS. They encode
| reasonable priors about how attention behaves; none has been validated
| against real DevRadar data because none exists yet. Tune them against the
| feed once it has content, and expect the caps in particular to move.
|
*/

return [

    /*
    |----------------------------------------------------------------------
    | Component weights
    |----------------------------------------------------------------------
    |
    | Rescaled to sum to 1 on construction, so changing one weight means
    | "this matters more" rather than "every score inflated".
    |
    | Weights of UNAVAILABLE components are redistributed across the rest, so
    | these are the proportions for a project where everything is known. Most
    | projects will not have github or growth on their first pass.
    |
    | engagement is highest because it is the only signal that comes from
    | people rather than from our own pipeline. recency is second because the
    | product promise is "this week". confidence is third and deliberately
    | not higher: it measures how sure WE are, and letting our own certainty
    | dominate the ranking would make the feed self-congratulatory rather
    | than useful.
    */
    'weights' => [
        'engagement' => env('DEVRADAR_WEIGHT_ENGAGEMENT', 0.35),
        'recency' => env('DEVRADAR_WEIGHT_RECENCY', 0.25),
        'confidence' => env('DEVRADAR_WEIGHT_CONFIDENCE', 0.15),
        'github' => env('DEVRADAR_WEIGHT_GITHUB', 0.15),
        'growth' => env('DEVRADAR_WEIGHT_GROWTH', 0.10),
    ],

    /* Final scores run 0..100 purely because it reads better than 0..1. */
    'scale' => 100.0,

    /*
    | A project scored on a single component is not comparable with one
    | scored on five. Without this it could reach the same maximum, putting
    | the least-understood projects at the top of the feed.
    */
    'sparse_data' => [
        'threshold' => 2,
        'dampener' => 0.85,
    ],

    /*
    |----------------------------------------------------------------------
    | Engagement
    |----------------------------------------------------------------------
    */
    'engagement' => [

        /*
        | Interaction weights, by the effort each one costs.
        |
        | A like is a tap. A repost puts the poster's own name behind it. A
        | reply costs a sentence. A bookmark means "I intend to use this",
        | which is the closest thing on the platform to what DevRadar
        | actually wants to measure, so it is weighted highest.
        */
        'interactions' => [
            'like' => 1.0,
            'repost' => 3.0,
            'reply' => 2.0,
            'quote' => 3.0,
            'bookmark' => 4.0,
        ],

        /*
        | Weighted engagement that counts as a full absolute score.
        |
        | Roughly a post with ~500 likes and a few dozen reposts: clearly a
        | well-received launch, not a viral phenomenon. Log compression means
        | anything above still scores near 1 without flattening everything
        | else, so this is a "very good" mark rather than a ceiling.
        */
        'absolute_cap' => env('DEVRADAR_ENGAGEMENT_CAP', 1200),

        /*
        | Engagement rate that counts as a full score, as weighted
        | engagement per follower. 0.10 means a post earning attention worth
        | a tenth of the author's audience -- strong for any account size.
        */
        'rate_cap' => env('DEVRADAR_ENGAGEMENT_RATE_CAP', 0.10),

        /*
        | How much of the engagement score is the rate rather than the raw
        | total. Slightly over half, because a small account's strong post
        | says more about the PROJECT than a large account's does -- but not
        | so much that raw reach stops counting at all.
        */
        'rate_share' => env('DEVRADAR_ENGAGEMENT_RATE_SHARE', 0.55),

        /*
        | Minimum reach used in the rate calculation.
        |
        | Without it, a 3-follower account with 30 likes scores a rate of 10
        | and pins the term at maximum. Below roughly this audience size the
        | rate is not measuring anything real.
        */
        'reach_floor' => env('DEVRADAR_REACH_FLOOR', 250),

        /*
        | Above this rate, engagement is treated as anomalous rather than
        | exceptional. Weighted engagement exceeding half the follower count
        | is more often bought, botted, or inherited from a quote of
        | something else than it is a genuine signal about a launch.
        */
        'anomaly_rate_threshold' => env('DEVRADAR_ANOMALY_RATE', 0.5),

        /* Anomalous rate terms are halved, not discarded: it could be real. */
        'anomaly_dampener' => env('DEVRADAR_ANOMALY_DAMPENER', 0.5),
    ],

    /*
    |----------------------------------------------------------------------
    | Recency
    |----------------------------------------------------------------------
    */
    'recency' => [

        /*
        | Hours after which a project is worth half what it was.
        |
        | 36 hours over a 7-day window means yesterday's launches still
        | compete, while Monday's have clearly faded by Friday. Shorter makes
        | the feed churn; longer makes it stale.
        */
        'half_life_hours' => env('DEVRADAR_RECENCY_HALF_LIFE', 36),

        /* Matches the collection window: outside it, projects age out. */
        'window_hours' => env('DEVRADAR_WINDOW_DAYS', 7) * 24,

        /*
        | Full recency for the first few hours.
        |
        | A two-hour-old post has almost no engagement yet and would lose on
        | that component through no fault of its own. The grace window lets it
        | compete while its metrics mature; the rescore sweep then re-ranks it
        | on real numbers.
        */
        'grace_hours' => env('DEVRADAR_RECENCY_GRACE', 4),
    ],

    /*
    |----------------------------------------------------------------------
    | Confidence
    |----------------------------------------------------------------------
    |
    | Classification carries more weight than extraction: a wrong launch call
    | puts something on the front page that does not belong there, while a
    | wrong tech tag is a smaller and more visible error.
    */
    'confidence' => [
        'classification_share' => 0.7,
        'extraction_share' => 0.3,
    ],

    /*
    |----------------------------------------------------------------------
    | GitHub
    |----------------------------------------------------------------------
    |
    | Unavailable for every project until the enrichment phase exists, which
    | is the correct behaviour: its weight redistributes and nothing breaks.
    */
    'github' => [

        /*
        | Stars that count as a full score. 5,000 is "widely known" for a
        | new project; log compression keeps a 40,000-star repository ahead
        | without erasing a 400-star one.
        */
        'star_cap' => env('DEVRADAR_GITHUB_STAR_CAP', 5000),

        /* Forks run roughly an order of magnitude below stars. */
        'fork_cap' => env('DEVRADAR_GITHUB_FORK_CAP', 500),

        /*
        | Popularity versus maintenance. Freshness is weighted heavily
        | because a 2,000-star repository with no commit in three years is an
        | artefact, not a launch.
        */
        'star_share' => 0.45,
        'fork_share' => 0.20,
        'freshness_share' => 0.35,

        /*
        | Days since the last push at which freshness reaches zero. 180 days
        | without a commit is a fair line for "not actively maintained".
        */
        'stale_after_days' => env('DEVRADAR_GITHUB_STALE_DAYS', 180),
    ],

    /*
    |----------------------------------------------------------------------
    | Growth
    |----------------------------------------------------------------------
    */
    'growth' => [

        /*
        | Engagement gained per hour that counts as a full score. 40/hour is
        | a launch that is clearly accelerating rather than merely being seen.
        */
        'rate_per_hour_cap' => env('DEVRADAR_GROWTH_RATE_CAP', 40),

        /*
        | Snapshots closer together than this measure noise, not trajectory.
        */
        'minimum_interval_hours' => env('DEVRADAR_GROWTH_MIN_INTERVAL', 1.0),
    ],

    /*
    |----------------------------------------------------------------------
    | Rescore sweep
    |----------------------------------------------------------------------
    |
    | Engagement matures over the window, so scores are recomputed on their
    | own schedule rather than only at publication. Without this, a post
    | scored two hours after launch keeps that score forever and is
    | permanently outranked by older projects that had time to accumulate.
    */
    'rescore' => [
        'batch_size' => env('DEVRADAR_RESCORE_BATCH', 200),
        'stale_after_minutes' => env('DEVRADAR_RESCORE_STALE_MINUTES', 180),
        'snapshot_every_minutes' => env('DEVRADAR_SNAPSHOT_INTERVAL', 180),
    ],

];
