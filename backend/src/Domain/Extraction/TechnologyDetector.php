<?php

declare(strict_types=1);

namespace DevRadar\Domain\Extraction;

use DevRadar\Domain\Filtering\KeywordMatcher;
use DevRadar\Domain\Filtering\SignalDefinition;

/**
 * Detects technologies from evidence. Pure, no I/O.
 *
 * THE RULE IS: NO EVIDENCE, NO TECHNOLOGY.
 *
 * A model asked what a project is built with will happily answer even when
 * the post says nothing about it -- "Laravel" is a plausible guess for almost
 * any web tool, and a plausible guess published as fact is worse than an
 * empty tech stack. Users filter on this field; a wrong tag sends them to a
 * project that does not use what they searched for.
 *
 * So model suggestions are treated as HYPOTHESES, never as findings. Each one
 * is checked against the post text and the project's URLs, and kept only if
 * corroborated. Detection from the text itself needs no corroboration because
 * the text IS the evidence.
 *
 * AMBIGUOUS NAMES ARE MATCHED CASE-SENSITIVELY. "Go" the language and "go"
 * the verb are the same string; matching case-insensitively would tag a large
 * share of every corpus as Go projects. Same for "Rust" and "rust".
 */
final readonly class TechnologyDetector
{
    /**
     * @param array<string, array{name: string, kind?: string, aliases: list<string>, case_sensitive?: bool}> $catalog
     */
    public function __construct(
        private KeywordMatcher $matcher,
        private array $catalog,
    ) {}

    /**
     * @param  list<string> $urls
     * @param  list<string> $modelSuggestions technologies the model proposed
     * @return list<DetectedTechnology>
     */
    public function detect(string $text, array $urls = [], array $modelSuggestions = []): array
    {
        $found = [];

        foreach ($this->catalog as $slug => $spec) {
            $evidence = $this->evidenceFor($slug, $spec, $text, $urls);

            if ($evidence === null) {
                continue;
            }

            $found[$slug] = new DetectedTechnology(
                slug: $slug,
                name: $spec['name'],
                kind: $spec['kind'] ?? null,
                source: $evidence['source'],
                evidence: $evidence['match'],
            );
        }

        // Model suggestions are hypotheses. Each is resolved to a known
        // technology and then required to corroborate, exactly as if the
        // detector had proposed it itself.
        foreach ($modelSuggestions as $suggestion) {
            $slug = $this->resolveSlug($suggestion);

            if ($slug === null || isset($found[$slug])) {
                continue;
            }

            $evidence = $this->evidenceFor($slug, $this->catalog[$slug], $text, $urls);

            if ($evidence === null) {
                // The model proposed it and nothing in the post supports it.
                // Dropped, deliberately.
                continue;
            }

            $found[$slug] = new DetectedTechnology(
                slug: $slug,
                name: $this->catalog[$slug]['name'],
                kind: $this->catalog[$slug]['kind'] ?? null,
                source: DetectedTechnology::SOURCE_MODEL_CONFIRMED,
                evidence: $evidence['match'],
            );
        }

        return array_values($found);
    }

    /** Model suggestions that could not be corroborated, for logging. */
    /**
     * @param  list<string> $urls
     * @param  list<string> $modelSuggestions
     * @return list<string>
     */
    public function unsupportedSuggestions(string $text, array $urls, array $modelSuggestions): array
    {
        $kept = array_map(fn (DetectedTechnology $t) => $t->slug, $this->detect($text, $urls, $modelSuggestions));
        $dropped = [];

        foreach ($modelSuggestions as $suggestion) {
            $slug = $this->resolveSlug($suggestion);

            if ($slug === null || ! in_array($slug, $kept, true)) {
                $dropped[] = $suggestion;
            }
        }

        return array_values(array_unique($dropped));
    }

    /**
     * @param  array{name: string, kind?: string, aliases: list<string>, case_sensitive?: bool} $spec
     * @param  list<string> $urls
     * @return array{source: string, match: string}|null
     */
    private function evidenceFor(string $slug, array $spec, string $text, array $urls): ?array
    {
        $caseSensitive = $spec['case_sensitive'] ?? false;

        foreach ($spec['aliases'] as $alias) {
            $signal = new SignalDefinition(
                phrase: $alias,
                weight: 1,
                group: $slug,
                wholeWord: true,
                caseSensitive: $caseSensitive,
            );

            if ($this->matcher->matches($text, $signal)) {
                return ['source' => DetectedTechnology::SOURCE_TEXT, 'match' => $alias];
            }
        }

        // A URL is weaker evidence than prose but still evidence: a link to
        // pypi.org or a .rs domain says something the post text may not.
        foreach ($urls as $url) {
            foreach ($spec['aliases'] as $alias) {
                // URLs are matched case-insensitively regardless: hostnames
                // and paths are conventionally lowercase, so the ambiguity
                // that motivates case sensitivity in prose does not arise.
                $signal = new SignalDefinition($alias, 1, $slug, wholeWord: true, caseSensitive: false);

                if ($this->matcher->matches($url, $signal)) {
                    return ['source' => DetectedTechnology::SOURCE_URL, 'match' => $alias];
                }
            }
        }

        return null;
    }

    private function resolveSlug(string $raw): ?string
    {
        $needle = $this->normalise($raw);

        if ($needle === '') {
            return null;
        }

        foreach ($this->catalog as $slug => $spec) {
            if ($this->normalise($slug) === $needle || $this->normalise($spec['name']) === $needle) {
                return $slug;
            }

            foreach ($spec['aliases'] as $alias) {
                if ($this->normalise($alias) === $needle) {
                    return $slug;
                }
            }
        }

        return null;
    }

    private function normalise(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value), 'UTF-8'));
    }
}
