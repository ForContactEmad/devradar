<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Collection configuration
|--------------------------------------------------------------------------
|
| Signal phrases and query groups. Declarative only -- no conditionals, no
| composition logic. QueryComposer turns these into expressions and splits
| any group that exceeds the provider's query-length limit.
|
| THIS FILE IS THE SEED, NOT THE SOURCE OF TRUTH. It is used to populate the
| search_queries table on first run. After that the database is authoritative,
| because tuning the query set is the core iterative activity of this product
| and must not require a deploy. Editing this file changes what a fresh
| install starts with; it does not change a running system.
|
| Expected yield per family is recorded in docs/query-set-v1.md. Family C is
| expected to have the worst signal ratio and is the one most likely to be
| cut once phase 4 labelling produces real numbers.
|
*/

return [

    /*
    | Applied to every generated query.
    |
    | -is:retweet   a reposted launch is the same project at full price
    | has:links     a launch with no link cannot be acted on or deduplicated
    | -is:reply     replies are conversation, not announcement
    */
    'modifiers' => [
        'default' => ['-is:retweet', '-is:reply', 'has:links'],
        'curated' => ['-is:retweet', '-is:reply'],
    ],

    'language' => env('DEVRADAR_QUERY_LANG', 'en'),

    /*
    | Signal groups. Each becomes one or more versioned queries.
    |
    | Multi-word phrases are quoted automatically. Anything containing ':' is
    | passed through untouched as an operator.
    */
    'groups' => [

        // Highest expected precision: a code host plus launch language.
        'repo-launch' => [
            'family' => 'A',
            'extra_modifiers' => ['(url:"github.com" OR url:"gitlab.com")'],
            'signals' => [
                'just launched',
                'just shipped',
                'just released',
                'open sourced',
                'open-sourced',
                'open source',
                'introducing',
                'now open source',
            ],
        ],

        // Version and availability language: concrete, medium precision.
        'release' => [
            'family' => 'B',
            'signals' => [
                'v1.0',
                'now available',
                'general availability',
                'out of beta',
                'first release',
                'initial release',
                'now live',
            ],
        ],

        // Build-in-public. Worst expected signal ratio, and the family where
        // the low-effort generated-project flood lives. Measure before trusting.
        'build-in-public' => [
            'family' => 'C',
            'signals' => [
                'I built',
                'built this',
                'my first',
                'side project',
                'weekend project',
                'new project',
                'built with',
                'shipped it',
            ],
        ],

        // Developer-tool vocabulary: best category fit for the target user.
        'devtools' => [
            'family' => 'D',
            'signals' => [
                'developer tool',
                'CLI tool',
                'open source alternative',
                'new tool',
                'new app',
                'new SaaS',
                'AI tool',
                'npm install',
                'pip install',
                'SDK',
            ],
        ],
    ],

    /*
    | Paging and cost ceilings for a collection cycle. DevRadar's brakes, not
    | the provider's.
    */
    'limits' => [
        'page_size' => env('DEVRADAR_COLLECT_PAGE_SIZE', 100),
        'max_posts_per_query' => env('DEVRADAR_COLLECT_MAX_POSTS', 100),
        'max_pages_per_query' => env('DEVRADAR_COLLECT_MAX_PAGES', 2),
    ],

    /*
    | Rolling window. Seven days is the provider's recent-search horizon; the
    | margins keep requests just inside it and away from the index's
    | eventual-consistency tail.
    */
    'window' => [
        'days' => env('DEVRADAR_WINDOW_DAYS', 7),
        'start_margin_seconds' => 300,
        'end_margin_seconds' => 30,
    ],

];
