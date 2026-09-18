<?php

declare(strict_types=1);

namespace DevRadar\Domain\Filtering;

/**
 * A signal that fired, kept for explainability.
 *
 * The breakdown is stored on the post rather than just the total, because a
 * score nobody can explain is a score nobody can tune -- and tuning the
 * signal set is the whole point of this layer.
 */
final readonly class SignalMatch
{
    public function __construct(
        public string $phrase,
        public int $weight,
        public string $group,
        public int $occurrences = 1,
    ) {}

    /**
     * A phrase contributes its weight ONCE however often it appears.
     *
     * Otherwise a post repeating "new tool" five times outscores a genuine
     * launch announcement that says it once, which is exactly backwards.
     */
    public function contribution(): int
    {
        return $this->weight;
    }
}
