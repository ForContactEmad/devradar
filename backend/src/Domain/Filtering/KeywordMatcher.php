<?php

declare(strict_types=1);

namespace DevRadar\Domain\Filtering;

/**
 * Matches configured phrases against post text. Pure, no I/O.
 *
 * WORD BOUNDARIES ARE THE WHOLE JOB. A substring search is what turns this
 * layer from a filter into a random number generator:
 *
 *   "built"        would match "rebuilt", "prebuilt", "built-in"
 *   "launching"    would match "relaunching"
 *   "new app"      would match "new appointment", "new approach"
 *   "SDK"          would match "SDKs" -- which is fine -- and "aSDKb", which
 *                  is not
 *
 * PCRE's \b works correctly with Unicode when the /u flag is set, verified
 * against Arabic, so one implementation covers every language in the signal
 * set rather than needing a Latin-only path and an exception for everything
 * else.
 *
 * Matching is case-insensitive and accent-preserving. It is deliberately NOT
 * stemmed: "ship" should not match "shipping" or "shipment", because the
 * tense carries the meaning here. "Shipped" is an announcement; "shipping"
 * is often a status update about a physical parcel.
 */
final class KeywordMatcher
{
    /** @var array<string, string> phrase => compiled pattern */
    private array $patternCache = [];

    /**
     * Every distinct phrase that appears in the text.
     *
     * @param  list<SignalDefinition> $signals
     * @return list<SignalMatch>
     */
    public function match(string $text, array $signals): array
    {
        if (trim($text) === '') {
            return [];
        }

        $matches = [];

        foreach ($signals as $signal) {
            $count = $this->countOccurrences($text, $signal);

            if ($count > 0) {
                $matches[] = new SignalMatch(
                    phrase: $signal->phrase,
                    weight: $signal->weight,
                    group: $signal->group,
                    occurrences: $count,
                );
            }
        }

        return $matches;
    }

    public function matches(string $text, SignalDefinition $signal): bool
    {
        return $this->countOccurrences($text, $signal) > 0;
    }

    private function countOccurrences(string $text, SignalDefinition $signal): int
    {
        $pattern = $this->patternFor($signal);
        $count = preg_match_all($pattern, $text);

        return $count === false ? 0 : $count;
    }

    private function patternFor(SignalDefinition $signal): string
    {
        $key = $signal->phrase . '|' . ($signal->wholeWord ? '1' : '0') . '|' . ($signal->caseSensitive ? '1' : '0');

        if (isset($this->patternCache[$key])) {
            return $this->patternCache[$key];
        }

        $quoted = preg_quote($signal->phrase, '/');

        // Internal whitespace matches any run of whitespace, so a phrase
        // split across a line break still fires. Normalization already
        // collapses whitespace, but the matcher must not depend on having
        // been handed normalized text.
        $quoted = (string) preg_replace('/\\\\?\s+/', '\\s+', $quoted);

        if ($signal->wholeWord) {
            // \b is only meaningful next to a word character. Anchoring a
            // phrase that starts or ends with punctuation -- "v1.0" -- with
            // \b would never match, so the boundary is applied per end.
            $prefix = $this->startsWithWordChar($signal->phrase) ? '\b' : '';
            $suffix = $this->endsWithWordChar($signal->phrase) ? '\b' : '';
            $quoted = $prefix . $quoted . $suffix;
        }

        $flags = $signal->caseSensitive ? 'u' : 'iu';

        return $this->patternCache[$key] = '/' . $quoted . '/' . $flags;
    }

    private function startsWithWordChar(string $phrase): bool
    {
        return preg_match('/^[\p{L}\p{N}_]/u', $phrase) === 1;
    }

    private function endsWithWordChar(string $phrase): bool
    {
        return preg_match('/[\p{L}\p{N}_]$/u', $phrase) === 1;
    }
}
