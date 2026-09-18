<?php

declare(strict_types=1);

namespace DevRadar\Domain\Extraction;

/**
 * A technology attached to a project, with the evidence that justified it.
 *
 * The evidence is stored, not just the tag. "Do not invent technologies that
 * are not supported by evidence" is only enforceable if the evidence is
 * recorded -- otherwise nobody can tell a detected technology from a
 * hallucinated one after the fact.
 */
final readonly class DetectedTechnology
{
    public const SOURCE_TEXT = 'post_text';
    public const SOURCE_URL = 'url';
    public const SOURCE_MODEL_CONFIRMED = 'model_confirmed';

    public function __construct(
        public string $slug,
        public string $name,
        public ?string $kind,
        public string $source,
        /** The literal substring that matched. */
        public string $evidence,
    ) {}
}
