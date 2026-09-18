<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Ingestion\PostBatch;
use DevRadar\Domain\Ingestion\SearchCriteria;

/**
 * The boundary between DevRadar and any source of candidate posts.
 *
 * Everything upstream of this interface speaks DevRadar's own types. No caller
 * sees an HTTP request, a status code, a bearer token, a pagination cursor or
 * a provider field name. Swapping X for another source -- or for a third-party
 * data reseller, or for a GitHub Releases feed -- means writing one adapter
 * and changing one configuration value.
 *
 * IMPORTANT: the production implementation is the ONLY component in DevRadar
 * permitted to spend money. Implementations MUST:
 *
 *   - consult the budget guard before every request;
 *   - refuse outright rather than partially execute when a limit is reached;
 *   - report the exact number of billable resources returned;
 *   - never log credentials.
 *
 * The architecture guard fails the build if any file outside
 * src/Infrastructure/X names a paid host.
 */
interface PostProviderInterface
{
    /**
     * Fetch posts matching the criteria, following pagination up to the
     * ceilings the criteria declares.
     *
     * Implementations throw only for failures the caller must decide about
     * (see the error classes in Infrastructure). A budget refusal is NOT an
     * exception: it returns a batch with StopReason::BudgetRefused, because
     * running out of budget is an expected operating condition, not a fault.
     */
    public function search(SearchCriteria $criteria): PostBatch;

    /** Stable identifier for the provider, recorded on every run. */
    public function name(): string;
}
