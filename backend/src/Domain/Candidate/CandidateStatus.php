<?php

declare(strict_types=1);

namespace DevRadar\Domain\Candidate;

/**
 * The states a candidate moves through in the staged pipeline.
 *
 * Each stage is a pure function of the previous state plus stored config,
 * which is what makes stages independently replayable. Because ingestion is
 * the only stage that costs money, everything from NORMALIZED onward can be
 * re-run for free after a failure.
 *
 * Rejected candidates are recorded, never deleted: rejection data is how
 * pre-filter and classifier precision get measured.
 */
enum CandidateStatus: string
{
    case Raw = 'raw';
    case Normalized = 'normalized';
    case Deduplicated = 'deduplicated';
    case Filtered = 'filtered';
    case PendingClassification = 'pending_classification';
    case Classified = 'classified';
    case Rejected = 'rejected';
    case Published = 'published';
    case AgedOut = 'aged_out';
    case Purged = 'purged';
}
