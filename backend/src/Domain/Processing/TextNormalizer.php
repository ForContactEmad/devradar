<?php

declare(strict_types=1);

namespace DevRadar\Domain\Processing;

use Normalizer;

/**
 * Text normalization. Pure, no I/O.
 *
 * TWO OUTPUTS, TWO PURPOSES. This is the central design decision here:
 *
 *   readable()    - cleaned but COMPLETE. Entities decoded, Unicode
 *                   normalised, invisible characters removed, whitespace
 *                   collapsed. URLs, mentions and hashtags are all still
 *                   there. This is what a classifier reads, and destroying
 *                   content it needs to judge a post would be a bad trade for
 *                   tidiness.
 *
 *   fingerprint() - aggressive and unreadable. Lowercased, URLs and mentions
 *                   stripped, hashtag markers removed, punctuation flattened.
 *                   Only ever hashed, never shown. This is what makes "Just
 *                   launched Foo! https://t.co/aaa" and "just launched foo
 *                   https://t.co/bbb" collapse to one project.
 *
 * The original text is never modified. It stays in `tweets.text` and in
 * `raw_payload` exactly as the provider returned it, because the provider's
 * copy is the only one that can be re-derived from.
 *
 * ARABIC AND RTL MATTER HERE. Directional marks and zero-width joiners are
 * invisible, semantically meaningless for matching, and differ between
 * otherwise identical posts. Left in, they defeat fingerprint matching for
 * exactly the content this project is most likely to under-serve.
 */
final class TextNormalizer
{
    /**
     * Invisible characters that change a hash without changing meaning:
     * zero-width space/non-joiner/joiner, BOM, LTR/RTL marks and embeddings,
     * word joiner, and the non-breaking space.
     */
    private const INVISIBLE = [
        "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}", "\u{2060}",
        "\u{200E}", "\u{200F}", "\u{202A}", "\u{202B}", "\u{202C}",
        "\u{202D}", "\u{202E}", "\u{00AD}",
    ];

    /**
     * Cleaned text that still says everything the original said.
     *
     * Returns an empty string only when there was nothing but whitespace,
     * markup or invisible characters to begin with.
     */
    public function readable(string $text): string
    {
        // Provider payloads carry HTML entities: &amp; &lt; &gt; are common
        // in code-heavy launch posts and would otherwise reach a classifier
        // as literal noise.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // NFC composition, so "é" as one codepoint and as e + combining
        // accent hash identically.
        if (Normalizer::isNormalized($text, Normalizer::FORM_C) === false) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        $text = str_replace(self::INVISIBLE, '', $text);
        $text = str_replace("\u{00A0}", ' ', $text);

        // Collapse runs of whitespace, including newlines, to single spaces.
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * The string that gets hashed. Never displayed.
     */
    public function fingerprintSource(string $text): string
    {
        $text = $this->readable($text);

        // Reposts announce the same project with a prefix that is not part
        // of the announcement.
        $text = (string) preg_replace('/^RT @[A-Za-z0-9_]{1,15}:\s*/u', '', $text);

        // URLs carry no matching value: the same project is announced with
        // different shortened links every time. URL matching is a separate,
        // stronger signal handled by the canonicaliser.
        $text = (string) preg_replace('#https?://\S+#iu', ' ', $text);

        // Mentions vary between accounts announcing the same thing.
        $text = (string) preg_replace('/@[A-Za-z0-9_]{1,15}/u', ' ', $text);

        // Keep the hashtag WORD, drop the marker, so "#OpenSource" and
        // "open source" are not treated as unrelated.
        $text = (string) preg_replace('/#(\w+)/u', '$1', $text);

        $text = mb_strtolower($text, 'UTF-8');

        // Flatten punctuation and symbols. \p{L} covers Arabic, CJK and
        // everything else; a naive [a-z0-9] filter would reduce an Arabic
        // post to an empty string and make every Arabic post a duplicate of
        // every other.
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * sha256 of the fingerprint source, or null when nothing meaningful
     * remains.
     *
     * Null matters: a post that is only a link and a mention has no text to
     * match on, and hashing the empty string would make every such post a
     * duplicate of every other.
     */
    public function fingerprint(string $text): ?string
    {
        $source = $this->fingerprintSource($text);

        // Below this, matches are coincidence rather than evidence. "new
        // tool" would collapse thousands of unrelated posts into one.
        if (mb_strlen($source) < 12) {
            return null;
        }

        return hash('sha256', $source);
    }

    /** True when a post carries no usable text at all. */
    public function isEffectivelyEmpty(string $text): bool
    {
        return $this->readable($text) === '';
    }

    /** @return list<string> hashtags without the marker, lowercased, unique */
    public function hashtags(string $text): array
    {
        preg_match_all('/#(\w+)/u', $this->readable($text), $matches);

        $tags = array_map(fn (string $t) => mb_strtolower($t, 'UTF-8'), $matches[1]);

        return array_values(array_unique($tags));
    }

    /** @return list<string> mentioned handles without the marker, lowercased, unique */
    public function mentions(string $text): array
    {
        preg_match_all('/@([A-Za-z0-9_]{1,15})/u', $this->readable($text), $matches);

        $handles = array_map(fn (string $h) => mb_strtolower($h, 'UTF-8'), $matches[1]);

        return array_values(array_unique($handles));
    }
}
