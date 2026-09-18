<?php

declare(strict_types=1);

namespace DevRadar\Domain\Extraction;

use DevRadar\Domain\Classification\ClassificationResponseParser;

/**
 * Extracts the extraction payload from model output. Pure, no I/O.
 *
 * Reuses the classification parser's JSON recovery -- markdown fences, prose
 * around the object, braces inside strings -- because models mangle output
 * the same way whatever they are asked. Duplicating that logic would mean two
 * implementations drifting apart, and the second one would be the less tested.
 *
 * What differs is the schema, and this class is deliberately PERMISSIVE about
 * it: every field is optional here, because the validator is what decides
 * which absences are fatal. A parser that rejected a missing description
 * would be making a policy decision it has no business making.
 */
final readonly class ExtractionResponseParser
{
    public function __construct(private ClassificationResponseParser $json = new ClassificationResponseParser()) {}

    /**
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    public function parse(string $raw): array
    {
        $object = $this->extractObject($raw);

        if ($object === null) {
            return ['ok' => false, 'error' => 'No JSON object found in model output.'];
        }

        return ['ok' => true, 'data' => [
            'name' => $object['name'] ?? $object['project_name'] ?? null,
            'description' => $object['description'] ?? $object['summary'] ?? null,
            'category' => $object['category'] ?? null,
            'project_type' => $object['project_type'] ?? $object['type'] ?? null,
            'technologies' => $object['technologies'] ?? $object['tech_stack'] ?? null,
            'repository_url' => $object['repository_url'] ?? $object['github_url'] ?? null,
            'website_url' => $object['website_url'] ?? $object['url'] ?? null,
            'demo_url' => $object['demo_url'] ?? null,
            'confidence' => $object['confidence'] ?? null,
        ]];
    }

    /** @return array<string, mixed>|null */
    private function extractObject(string $raw): ?array
    {
        // The classification parser owns JSON recovery; borrowing it through
        // a throwaway is_project field keeps that logic in one place.
        $probe = $this->json->parse($raw);

        if ($probe['ok'] === false && ! str_contains($probe['error'], 'is_project')) {
            return null;
        }

        // Decode again for the full object: the classification parser
        // deliberately returns only its own fields.
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', trim($raw), $m) === 1) {
            $raw = trim($m[1]);
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
