<?php

declare(strict_types=1);

namespace DevRadar\Domain\Ingestion;

/**
 * Why a paginated fetch stopped.
 *
 * Recorded on every batch so the run ledger can distinguish "we got
 * everything" from "we ran out of budget" -- which look identical if you only
 * count the posts returned.
 */
enum StopReason: string
{
    case Exhausted = 'exhausted';          // provider returned no next page
    case PostCapReached = 'post_cap';      // per-run post ceiling hit
    case PageCapReached = 'page_cap';      // per-run request ceiling hit
    case BudgetRefused = 'budget_refused'; // budget guard declined
}
