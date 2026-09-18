<?php

declare(strict_types=1);

namespace DevRadar\Domain\Compliance;

/**
 * One line of a compliance result: a post, and what happened to it.
 *
 * Posts NOT named in the results are compliant. X reports only what changed,
 * so an empty result set is the normal, healthy answer -- and treating it as
 * a failure would raise an alert every quiet day.
 */
final readonly class ComplianceFinding
{
    public function __construct(
        public string $postId,
        public ComplianceEvent $event,
        public ?string $reason = null,
    ) {}
}
