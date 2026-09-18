<?php

declare(strict_types=1);

namespace DevRadar\Domain\Extraction;

/**
 * Whether an extraction may be published, and what was changed to get there.
 *
 * Corrections are carried even on success. A project published with three
 * silent corrections looks identical to one published with none, and the
 * difference is exactly what tells you the prompt needs work.
 */
final readonly class ValidationResult
{
    /** @param list<string> $corrections */
    private function __construct(
        public bool $isValid,
        public ?ExtractedProject $project,
        public ?string $rejectReason,
        public array $corrections,
    ) {}

    /** @param list<string> $corrections */
    public static function accepted(ExtractedProject $project, array $corrections = []): self
    {
        return new self(true, $project, null, $corrections);
    }

    /** @param list<string> $corrections */
    public static function rejected(string $reason, array $corrections = []): self
    {
        return new self(false, null, $reason, $corrections);
    }

    public function hasCorrections(): bool
    {
        return $this->corrections !== [];
    }
}
