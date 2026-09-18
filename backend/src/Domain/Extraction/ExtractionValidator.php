<?php

declare(strict_types=1);

namespace DevRadar\Domain\Extraction;

use DateTimeImmutable;

/**
 * Validates and sanitises an extraction. Pure, no I/O.
 *
 * SEPARATE FROM EXTRACTION BY DESIGN. Extraction asks a model what it sees;
 * validation decides what the model is allowed to assert. Merging them would
 * mean the component that trusts the model is also the component that checks
 * it, and there is no check in that.
 *
 * Two failure modes, treated differently:
 *
 *   CORRECTION - the field is wrong but the project is fine. An unknown
 *                category becomes 'other', an over-long description is
 *                truncated, an unsupported technology is dropped. Recorded
 *                so the correction is visible rather than silent.
 *
 *   REJECTION  - the project cannot be published. No usable name, no source
 *                post, or a confidence below the floor. Recorded with a
 *                reason, never silently discarded.
 *
 * URL VALIDATION IS THE IMPORTANT ONE. Any URL the model returns must appear
 * among the links actually present in the post. A fabricated repository link
 * is the worst output this pipeline can produce: it looks correct, it passes
 * review, it reaches the front page, and it goes nowhere.
 */
final readonly class ExtractionValidator
{
    public function __construct(
        private CategoryRegistry $categories,
        private UrlClassifier $urls,
        private TechnologyDetector $technologies,
        /** @var list<string> */
        private array $projectTypes = [],
        private float $minimumConfidence = 0.6,
        private int $maxNameLength = 120,
        private int $maxDescriptionLength = 400,
    ) {}

    /**
     * @param array<string, mixed> $raw    parsed model output
     * @param list<string>         $postUrls canonical URLs found in the post
     */
    public function validate(
        int $tweetId,
        array $raw,
        string $postText,
        array $postUrls,
        ?string $authorHandle,
        string $sourcePostUrl,
        DateTimeImmutable $publishedAt,
    ): ValidationResult {
        $corrections = [];

        $name = $this->cleanName($raw['name'] ?? null);

        if ($name === null) {
            // Without a name there is nothing to show in a feed. Falling back
            // to the post's first line would publish a sentence as a project
            // title, which reads as broken rather than as missing data.
            return ValidationResult::rejected('no-project-name', $corrections);
        }

        $confidence = $this->cleanConfidence($raw['confidence'] ?? null);

        if ($confidence === null) {
            return ValidationResult::rejected('no-confidence', $corrections);
        }

        if ($confidence < $this->minimumConfidence) {
            return ValidationResult::rejected('low-extraction-confidence', $corrections);
        }

        $category = $this->categories->resolve(is_string($raw['category'] ?? null) ? $raw['category'] : null);

        if (isset($raw['category']) && is_string($raw['category']) && $this->categories->resolve($raw['category']) !== $this->normalise($raw['category'])) {
            if ($category === 'other') {
                $corrections[] = sprintf('category "%s" is not registered; recorded as other', $raw['category']);
            }
        }

        $projectType = $this->cleanProjectType($raw['project_type'] ?? null, $corrections);

        // Only links that genuinely appear in the post survive.
        $verifiedUrls = $this->verifyUrls($raw, $postUrls, $corrections);
        $classified = $this->urls->classify($verifiedUrls);

        $suggestions = $this->cleanTechnologyList($raw['technologies'] ?? null);
        $detected = $this->technologies->detect($postText, $verifiedUrls, $suggestions);
        $dropped = $this->technologies->unsupportedSuggestions($postText, $verifiedUrls, $suggestions);

        foreach ($dropped as $item) {
            $corrections[] = sprintf('technology "%s" dropped: nothing in the post supports it', $item);
        }

        $description = $this->cleanDescription($raw['description'] ?? null, $corrections);

        return ValidationResult::accepted(
            new ExtractedProject(
                tweetId: $tweetId,
                name: $name,
                description: $description,
                category: $category,
                projectType: $projectType,
                technologies: $detected,
                repositoryUrl: $classified['repository'],
                websiteUrl: $classified['website'],
                demoUrl: $classified['demo'],
                authorHandle: $authorHandle,
                sourcePostUrl: $sourcePostUrl,
                publishedAt: $publishedAt,
                confidence: $confidence,
            ),
            $corrections,
        );
    }

    /**
     * @param  array<string, mixed> $raw
     * @param  list<string>         $postUrls
     * @param  list<string>         $corrections
     * @return list<string>
     */
    private function verifyUrls(array $raw, array $postUrls, array &$corrections): array
    {
        $verified = [];

        foreach (['repository_url', 'website_url', 'demo_url'] as $field) {
            $candidate = $raw[$field] ?? null;

            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $candidate = trim($candidate);

            if (! $this->isWellFormed($candidate)) {
                $corrections[] = sprintf('%s "%s" is not a usable http(s) URL; dropped', $field, mb_substr($candidate, 0, 80));

                continue;
            }

            if (! $this->appearsInPost($candidate, $postUrls)) {
                // The single most important check in this class.
                $corrections[] = sprintf('%s "%s" does not appear in the post; dropped as unverified', $field, mb_substr($candidate, 0, 80));

                continue;
            }

            $verified[] = $candidate;
        }

        // Links present in the post that the model did not mention are still
        // the project's links. Dropping them would lose a repository the
        // model simply failed to report.
        //
        // THE SCHEME IS CHECKED HERE TOO. These previously bypassed
        // isWellFormed(), so the only thing keeping a `javascript:` URI out of
        // a rendered href was the provider never emitting one -- a guarantee
        // we neither control nor verify. Model-supplied and post-supplied
        // URLs now pass the same gate.
        foreach ($postUrls as $url) {
            if (! is_string($url) || ! $this->isWellFormed($url)) {
                if (is_string($url) && trim($url) !== '') {
                    $corrections[] = sprintf('post link "%s" is not a usable http(s) URL; dropped', mb_substr($url, 0, 80));
                }

                continue;
            }

            if (! in_array($url, $verified, true)) {
                $verified[] = $url;
            }
        }

        return array_values(array_unique($verified));
    }

    /** @param list<string> $postUrls */
    private function appearsInPost(string $candidate, array $postUrls): bool
    {
        $needle = $this->comparableUrl($candidate);

        foreach ($postUrls as $url) {
            $known = $this->comparableUrl($url);

            // Exact match, or the candidate is a path under a known link --
            // a model quoting github.com/acme/tool/releases when the post
            // linked github.com/acme/tool is reporting the same project.
            if ($needle === $known || str_starts_with($needle, $known . '/')) {
                return true;
            }
        }

        return false;
    }

    private function comparableUrl(string $url): string
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = rtrim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');

        return $host . mb_strtolower($path);
    }

    private function isWellFormed(string $url): bool
    {
        $scheme = mb_strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));
        $host = parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true)
            && is_string($host)
            && str_contains($host, '.');
    }

    private function cleanName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($name === '') {
            return null;
        }

        // A model that could not find a name sometimes says so in the name
        // field. Publishing "Unknown" as a project title is worse than
        // rejecting the extraction.
        $placeholders = ['unknown', 'n/a', 'na', 'none', 'untitled', 'not specified', 'null'];

        if (in_array(mb_strtolower($name), $placeholders, true)) {
            return null;
        }

        // A "name" the length of a sentence is the model returning the post
        // text, not a project name.
        if (mb_strlen($name) > $this->maxNameLength) {
            return null;
        }

        return $name;
    }

    /** @param list<string> $corrections */
    private function cleanDescription(mixed $value, array &$corrections): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $description = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($description === '') {
            return null;
        }

        if (mb_strlen($description) > $this->maxDescriptionLength) {
            $corrections[] = 'description truncated';
            $description = mb_substr($description, 0, $this->maxDescriptionLength);
        }

        return $description;
    }

    /** @param list<string> $corrections */
    private function cleanProjectType(mixed $value, array &$corrections): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $type = $this->normalise($value);

        if ($this->projectTypes !== [] && ! in_array($type, $this->projectTypes, true)) {
            $corrections[] = sprintf('project_type "%s" is not registered; dropped', $value);

            return null;
        }

        return $type;
    }

    private function cleanConfidence(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        return $number >= 0.0 && $number <= 1.0 ? round($number, 4) : null;
    }

    /** @return list<string> */
    private function cleanTechnologyList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return array_values(array_unique($items));
    }

    private function normalise(string $value): string
    {
        return (string) preg_replace('/[\s_]+/u', '-', mb_strtolower(trim($value), 'UTF-8'));
    }
}
