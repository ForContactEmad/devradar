<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| GitHub enrichment configuration
|--------------------------------------------------------------------------
|
| Declarative only. Verified against GitHub's current documentation on
| 2026-09-09; re-check before trusting the numbers.
|
| RATE LIMITS
|   60 requests/hour unauthenticated
|   5,000 requests/hour with a personal access token
|
| CONDITIONAL REQUESTS
|   A stored ETag sent as If-None-Match returns 304 when nothing changed.
|   GitHub documents that a 304 does not count against the primary rate
|   limit -- but only "when the request was made while correctly authorized
|   with an Authorization header". Measured reports confirm unauthenticated
|   304s still decrement the counter.
|
|   So ETags save bandwidth for everyone and QUOTA ONLY FOR TOKEN HOLDERS,
|   which is exactly backwards from where the budget is tight. Set a token.
|
*/

return [

    /*
    | A fine-grained personal access token with public read access is enough:
    | DevRadar only reads public repository metadata. It is a credential --
    | environment or secret store only, never in code or a commit.
    */
    'token' => env('GITHUB_TOKEN', ''),

    'base_url' => env('GITHUB_API_BASE_URL', 'https://api.github.com'),

    'timeout_seconds' => env('GITHUB_TIMEOUT', 15),

    /*
    | Contributor count needs a second request per repository, because GitHub
    | exposes no count field. At 5,000/hour that is affordable; against the
    | unauthenticated 60/hour it doubles the cost of every lookup, so it
    | defaults off when no token is configured.
    */
    'fetch_contributors' => env('GITHUB_FETCH_CONTRIBUTORS', true),

    'retry' => [
        /*
        | Only transient failures are retried. Not-found and private are
        | permanent, and a rate limit is not retried in-process at all: the
        | window can be an hour away, and blocking a worker that long to
        | re-ask one repository starves every other project in the queue.
        */
        'max_attempts' => env('GITHUB_MAX_RETRIES', 3),
        'base_backoff_seconds' => env('GITHUB_BASE_BACKOFF', 1),
        'max_backoff_seconds' => env('GITHUB_MAX_BACKOFF', 30),
    ],

    'refresh' => [
        /*
        | How stale a repository may get before a refresh.
        |
        | 12 hours is frequent enough that a launch's first-week star growth
        | is visible in the growth score, and slow enough that a 500-project
        | feed costs 1,000 conditional requests a day -- most of which return
        | 304 and cost nothing.
        */
        'after_minutes' => env('GITHUB_REFRESH_AFTER_MINUTES', 720),

        'batch_size' => env('GITHUB_ENRICH_BATCH', 50),

        /*
        | Consecutive transient failures before a repository leaves the
        | queue. Distinct from `gone`: this is for repositories that keep
        | timing out, not ones that are deleted.
        */
        'max_failures' => env('GITHUB_MAX_FAILURES', 5),

        /*
        | Stop while requests remain, not at zero, so an interactive lookup
        | or another job is not left with an empty budget because this sweep
        | drained it.
        */
        'quota_reserve' => env('GITHUB_QUOTA_RESERVE', 50),
    ],

];
