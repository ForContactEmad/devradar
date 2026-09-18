<?php

declare(strict_types=1);

namespace DevRadar\Domain\Filtering;

use InvalidArgumentException;

/**
 * One configured signal: a phrase, what it is worth, and what it means.
 *
 * Signals are DATA. They come from configuration, and eventually from the
 * database alongside the query set, because tuning them is continuous. No
 * phrase is written down anywhere in the domain classes.
 */
final readonly class SignalDefinition
{
    public function __construct(
        public string $phrase,
        public int $weight,
        public string $group,
        public string $language = 'en',
        /**
         * Whether the phrase must match on word boundaries.
         *
         * True for words -- "shipped" must not match "shipped-ness" and
         * "built" must not match "rebuilt". False for fragments like "v1.0"
         * where the surrounding punctuation is part of the signal.
         */
        public bool $wholeWord = true,
        /**
         * Match case exactly.
         *
         * Needed for names that collide with ordinary words: "Go" the
         * language versus "go" the verb, "Rust" versus "rust". Matching those
         * case-insensitively tags half the corpus.
         */
        public bool $caseSensitive = false,
    ) {
        if (trim($phrase) === '') {
            throw new InvalidArgumentException('A signal needs a phrase.');
        }

        if ($weight === 0) {
            throw new InvalidArgumentException(
                "Signal '{$phrase}' has weight 0, which means it can never affect a score. "
                . 'Remove it or give it a weight.'
            );
        }
    }

    public function isNegative(): bool
    {
        return $this->weight < 0;
    }
}
