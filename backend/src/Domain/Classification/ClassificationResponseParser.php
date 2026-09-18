<?php

declare(strict_types=1);

namespace DevRadar\Domain\Classification;

use JsonException;

/**
 * Turns whatever a model returned into a verdict, or refuses. Pure, no I/O.
 *
 * TOLERANT IN PARSING, STRICT IN VALIDATION. Those are different jobs and
 * confusing them is how this layer goes wrong:
 *
 *   Tolerant  - models wrap JSON in markdown fences, add a sentence before
 *               it, use "true"/"yes"/1 for booleans, or return 96 where 0.96
 *               was asked for. All recoverable, none worth a failed
 *               classification the post has already been paid for.
 *
 *   Strict    - a confidence outside 0..1, a missing is_project, a reason
 *               that is not a string, or an invented URL are NOT recoverable.
 *               Guessing what the model meant is how a hallucination becomes
 *               a published project.
 *
 * The distinction matters because a rejected parse costs one wasted call,
 * while a wrongly-accepted parse puts fabricated content on the front page.
 */
final class ClassificationResponseParser
{
    /**
     * Extract a verdict from raw model output.
     *
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public function parse(string $raw): array
    {
        $json = $this->extractJson($raw);

        if ($json === null) {
            return ['ok' => false, 'error' => 'No JSON object found in model output.'];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return ['ok' => false, 'error' => 'Malformed JSON: ' . $e->getMessage()];
        }

        if (! is_array($decoded)) {
            return ['ok' => false, 'error' => 'Model output decoded to a scalar, not an object.'];
        }

        return $this->validate($decoded);
    }

    /**
     * Pull the first balanced JSON object out of the text.
     *
     * Models add prose, markdown fences and trailing commentary however
     * firmly the prompt asks them not to. Scanning for a balanced object
     * survives all three, where a naive strip-the-fences approach does not.
     */
    private function extractJson(string $raw): ?string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // Strip a fenced block if present; the fence language tag varies.
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $matches) === 1) {
            $raw = trim($matches[1]);
        }

        $start = strpos($raw, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($raw);

        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;

                continue;
            }

            if ($inString) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        // Unbalanced: usually a response truncated by the token limit.
        return null;
    }

    /**
     * @param  array<string, mixed> $decoded
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    private function validate(array $decoded): array
    {
        if (! array_key_exists('is_project', $decoded)) {
            return ['ok' => false, 'error' => 'Required field is_project is missing.'];
        }

        $isProject = $this->toBool($decoded['is_project']);

        if ($isProject === null) {
            return ['ok' => false, 'error' => 'Field is_project is not interpretable as a boolean.'];
        }

        // A post that is not a project is neither new nor old. Requiring the
        // rest of the schema here would fail perfectly correct negative
        // answers, which are the majority of what the model returns.
        if ($isProject === false) {
            return ['ok' => true, 'data' => [
                'is_project' => false,
                'is_new' => null,
                'confidence' => $this->toConfidence($decoded['confidence'] ?? null),
                'reason' => $this->toReason($decoded['reason'] ?? null),
            ]];
        }

        $confidence = $this->toConfidence($decoded['confidence'] ?? null);

        if ($confidence === null) {
            return ['ok' => false, 'error' => 'A positive verdict must carry a usable confidence.'];
        }

        $isNew = $this->toBool($decoded['is_new'] ?? null);

        if ($isNew === null) {
            return ['ok' => false, 'error' => 'Field is_new is required when is_project is true.'];
        }

        return ['ok' => true, 'data' => [
            'is_project' => true,
            'is_new' => $isNew,
            'confidence' => $confidence,
            'reason' => $this->toReason($decoded['reason'] ?? null),
        ]];
    }

    /** Accepts the several shapes models use for a boolean. */
    private function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalised = strtolower(trim($value));

            if (in_array($normalised, ['true', 'yes', 'y', '1'], true)) {
                return true;
            }

            if (in_array($normalised, ['false', 'no', 'n', '0'], true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * Confidence must land in 0..1.
     *
     * A whole number in 1..100 is read as a percentage, because "96" for 0.96
     * is a common slip and refusing it wastes a call that has already been
     * paid for.
     *
     * The conversion is safe because it FAILS CLOSED. Every way it can be
     * wrong biases toward lower confidence, never higher: a model meaning
     * "8 out of 10" that writes 8 becomes 0.08 and is held back as low
     * confidence, which is the same outcome as refusing it. There is no input
     * that this rule turns into an unearned high confidence.
     *
     * Anything outside 0..100, or not numeric at all, is refused rather than
     * clamped. Clamping 150 to 1.0 would manufacture certainty from evidence
     * that the model did not understand the schema.
     */
    private function toConfidence(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        if ($number >= 0.0 && $number <= 1.0) {
            return round($number, 4);
        }

        if ($number > 1.0 && $number <= 100.0 && $number == floor($number)) {
            return round($number / 100, 4);
        }

        return null;
    }

    private function toReason(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $reason = trim($value);

        if ($reason === '') {
            return null;
        }

        // Bounded: the reason is for a human reading the admin panel, and an
        // unbounded string from a model is an unbounded column write.
        return mb_substr($reason, 0, 500);
    }
}
