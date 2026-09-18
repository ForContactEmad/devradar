<?php

declare(strict_types=1);

namespace DevRadar\Domain\Compliance;

/**
 * Why the provider says a post must stop being displayed.
 *
 * Verified against X's batch compliance documentation. The distinction is
 * kept rather than flattened into "gone", because the events are not
 * equivalent and two of them behave quite differently:
 *
 *   scrub_geo removes only the location data. The post itself is still live
 *   and must stay in the feed; treating it as a deletion would pull a
 *   perfectly good project for no reason.
 *
 *   suspended, protected and deactivated are reversible. The content must
 *   come down now, but the account may return, so the removal is recorded as
 *   reversible rather than permanent.
 */
enum ComplianceEvent: string
{
    case Deleted = 'deleted';
    case Bounced = 'bounced';
    case Suspended = 'suspended';
    case Protected_ = 'protected';
    case Deactivated = 'deactivated';
    case ScrubGeo = 'scrub_geo';

    /**
     * Map a provider label, tolerantly.
     *
     * Returns null for an unrecognised label rather than guessing. An unknown
     * event is not automatically a deletion, and inventing one would remove
     * content on evidence nobody supplied.
     */
    public static function tryFromLabel(?string $label): ?self
    {
        if ($label === null) {
            return null;
        }

        return self::tryFrom(strtolower(trim($label)));
    }

    /** Must the stored content stop being displayed? */
    public function requiresRemoval(): bool
    {
        // Everything but a geo scrub: that names a post which is still live,
        // and DevRadar never stored any location data to remove.
        return $this !== self::ScrubGeo;
    }

    /**
     * Is the removal final?
     *
     * A deleted or bounced post is gone for good, so its stored text can be
     * overwritten. A suspension may be lifted, so the content comes down but
     * the row is not scrubbed -- re-collecting it later would otherwise cost
     * money to recover something we already had.
     */
    public function isPermanent(): bool
    {
        return $this === self::Deleted || $this === self::Bounced;
    }
}
