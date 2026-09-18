<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pre-filter configuration
|--------------------------------------------------------------------------
|
| Every phrase, weight and threshold in the filtering layer lives here.
| The domain classes contain no phrases at all.
|
| WHAT THIS LAYER IS FOR
| Reducing how many posts reach a paid model. It is NOT a quality filter --
| the AI stage does that. Their error costs are not symmetric:
|
|   A false positive here costs one classification, a fraction of a cent, and
|   the AI rejects it anyway.
|
|   A false negative here costs the project entirely. The post was already
|   paid for at collection, the model never sees it, and nothing downstream
|   can recover it.
|
| So the defaults are deliberately permissive. Tighten them only with
| measurements from the labelled evaluation set, never on intuition.
|
| WEIGHTS
|   3  strong  language that only appears in an actual launch
|   2  medium  language common in launches but also elsewhere
|   1  weak    plausible but weak on its own
|  -n  negative evidence the post is something else
|
*/

return [

    /*
    | Score at or above which a post reaches each strength level.
    | With the weights below, one strong phrase plus a repo link is Strong.
    */
    'thresholds' => [
        'strong' => env('DEVRADAR_SIGNAL_STRONG', 5),
        'medium' => env('DEVRADAR_SIGNAL_MEDIUM', 3),
        'weak' => env('DEVRADAR_SIGNAL_WEAK', 1),
    ],

    /*
    | Minimum strength to proceed to AI classification.
    | 'weak' by design -- see the note above about asymmetric error costs.
    */
    'minimum_strength' => env('DEVRADAR_FILTER_MINIMUM', 'weak'),

    /*
    | Strength at or above which negative signals are ignored.
    |
    | "We're hiring engineers to work on our newly open-sourced compiler" is a
    | real launch wearing a hiring phrase. Only overwhelming positive evidence
    | should override an explicit negative.
    */
    'negative_override_strength' => env('DEVRADAR_NEGATIVE_OVERRIDE', 'strong'),

    /*
    | Require a matched phrase or a repository link before a post can pass.
    |
    | Without this, any post carrying a link scores 1 from the structural
    | bonus alone and clears the Weak threshold with no launch language at
    | all -- which sends ordinary chatter to a paid model.
    */
    'require_evidence' => env('DEVRADAR_REQUIRE_EVIDENCE', true),

    /*
    | Facts about a post that are not words.
    |
    | A link to a code host is stronger evidence of a software launch than any
    | phrase, and no amount of keyword tuning can express it. A repost is
    | someone else's announcement at full price.
    */
    'structural' => [
        'repository_link' => 3,
        'any_link' => 1,
        'is_repost' => -4,
    ],

    'repository_hosts' => ['github.com', 'gitlab.com', 'bitbucket.org', 'codeberg.org', 'sr.ht'],

    /*
    | Positive signals, grouped by what they indicate.
    */
    'signals' => [

        'launch' => [
            'weight' => 3,
            'phrases' => [
                'just launched', 'just shipped', 'just released', 'launched today',
                'shipping today', 'open sourced', 'open-sourced', 'now open source',
                'introducing', 'launching', 'we launched', 'i launched',
            ],
        ],

        'build' => [
            'weight' => 2,
            'phrases' => [
                'I built', 'we built', 'built this', 'built with', 'I made',
                'my first', 'side project', 'weekend project', 'new project',
                'hacked together', 'spent the weekend building',
            ],
        ],

        'product' => [
            'weight' => 2,
            'phrases' => [
                'new tool', 'new app', 'new SaaS', 'developer tool', 'AI tool',
                'open source alternative', 'CLI tool', 'open source',
                'self-hosted', 'no-code tool',
            ],
        ],

        'release' => [
            'weight' => 2,
            'phrases' => [
                'now available', 'general availability', 'out of beta',
                'first release', 'initial release', 'now live', 'public beta',
            ],
        ],

        // Version fragments. wholeWord is off because the surrounding
        // punctuation is part of the signal.
        'version' => [
            'weight' => 2,
            'whole_word' => false,
            'phrases' => ['v1.0', 'v0.1', '1.0.0', 'release candidate'],
        ],

        'install' => [
            'weight' => 2,
            'phrases' => [
                'npm install', 'pip install', 'cargo install', 'go get',
                'brew install', 'docker run', 'composer require',
            ],
        ],

        'invite' => [
            'weight' => 1,
            'phrases' => [
                'try it', 'give it a try', 'feedback welcome', 'would love feedback',
                'check it out', 'live demo', 'MIT licensed', 'apache licensed',
                'free and open',
            ],
        ],
    ],

    /*
    | Negative signals. Evidence the post is something other than a launch.
    |
    | The group name doubles as the reject reason, so adding a group here adds
    | a reason to the histogram without touching any class.
    */
    'negative_signals' => [

        'hiring' => [
            'weight' => -5,
            'phrases' => [
                'we are hiring', "we're hiring", 'now hiring', 'join our team',
                'apply now', 'job opening', 'open role', 'open position',
                'send your CV', 'send your resume', 'looking to hire',
            ],
        ],

        'tutorial' => [
            'weight' => -3,
            'phrases' => [
                'how to build', 'step by step guide', 'in this thread',
                'a thread', 'tutorial series', 'learn how to', 'beginners guide',
                'complete guide', 'here is how',
            ],
        ],

        'course' => [
            'weight' => -4,
            'phrases' => [
                'enroll now', 'my course', 'free course', 'bootcamp',
                'early bird price', 'discount code', 'use code',
            ],
        ],

        'giveaway' => [
            'weight' => -5,
            'phrases' => [
                'retweet to win', 'RT to win', 'giveaway', 'tag 3 friends',
                'like and retweet', 'follow and retweet',
            ],
        ],

        'marketing' => [
            'weight' => -2,
            'phrases' => [
                'limited time offer', 'sign up for our newsletter',
                'book a demo', 'schedule a call', 'lifetime deal',
            ],
        ],
    ],

    /*
    | Arabic signals.
    |
    | Kept separate because the phrases are not translations -- Arabic launch
    | announcements use different conventions, and a machine-translated list
    | would mostly miss. This set is a starting point and unmeasured; treat
    | its yield as unknown until a labelling pass says otherwise.
    */
    'signals_ar' => [
        'launch' => [
            'weight' => 3,
            'phrases' => ['أطلقت', 'أطلقنا', 'تم إطلاق', 'مفتوح المصدر', 'أعلن عن'],
        ],
        'product' => [
            'weight' => 2,
            'phrases' => ['أداة جديدة', 'مشروع جديد', 'تطبيق جديد', 'مكتبة برمجية'],
        ],
    ],

    'batch_size' => env('DEVRADAR_FILTER_BATCH', 200),

];
