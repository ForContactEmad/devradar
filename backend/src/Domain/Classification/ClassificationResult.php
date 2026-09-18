<?php

declare(strict_types=1);

namespace DevRadar\Domain\Classification;

/**
 * The verdict on one post.
 *
 * isNew is only meaningful when isProject is true. A post that is not about
 * a software project is neither new nor old, and forcing a boolean there
 * would invite downstream code to read a value that means nothing.
 */
final readonly class ClassificationResult
{
    public function __construct(
        public int $tweetId,
        public ClassificationOutcome $outcome,
        public bool $isProject,
        public ?bool $isNew,
        public ?float $confidence,
        public ?string $reason,
        public ModelIdentity $identity,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $costUsd = 0.0,
        /** @var array<string, mixed>|null */
        public ?array $rawResponse = null,
        public ?string $errorMessage = null,
    ) {}

    public function isAccepted(): bool
    {
        return $this->outcome === ClassificationOutcome::Accepted;
    }

    public static function failed(
        int $tweetId,
        ModelIdentity $identity,
        string $message,
        ClassificationOutcome $outcome = ClassificationOutcome::Failed,
    ): self {
        return new self(
            tweetId: $tweetId,
            outcome: $outcome,
            isProject: false,
            isNew: null,
            confidence: null,
            reason: null,
            identity: $identity,
            errorMessage: $message,
        );
    }
}
