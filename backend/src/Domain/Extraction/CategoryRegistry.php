<?php

declare(strict_types=1);

namespace DevRadar\Domain\Extraction;

/**
 * The set of categories a project may belong to.
 *
 * EXTENSIBLE BY CONFIGURATION, not by editing a class. The list is injected,
 * aliases are supported so a model saying "devtools" or "developer tooling"
 * lands on the same category, and anything unrecognised falls back rather
 * than being rejected.
 *
 * Falling back matters: a model inventing "Web3 Infrastructure" should not
 * cost us the project. The category is a filter facet, not the substance.
 */
final readonly class CategoryRegistry
{
    /**
     * @param array<string, list<string>> $categories canonical slug => aliases
     */
    public function __construct(
        private array $categories,
        private string $fallback = 'other',
    ) {}

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->categories);
    }

    public function has(string $slug): bool
    {
        return array_key_exists($this->normaliseKey($slug), $this->categories);
    }

    /**
     * Resolve whatever the model said to a canonical slug.
     *
     * Never throws. An unknown category becomes the fallback, and the caller
     * is told it was substituted so the correction is visible rather than
     * silent.
     */
    public function resolve(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return $this->fallback;
        }

        $key = $this->normaliseKey($raw);

        if (array_key_exists($key, $this->categories)) {
            return $key;
        }

        foreach ($this->categories as $slug => $aliases) {
            foreach ($aliases as $alias) {
                if ($this->normaliseKey($alias) === $key) {
                    return $slug;
                }
            }
        }

        return $this->fallback;
    }

    /** Lowercase, and treat spaces, underscores and hyphens as equivalent. */
    private function normaliseKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return (string) preg_replace('/[\s_]+/u', '-', $value);
    }
}
