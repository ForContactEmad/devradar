<?php

declare(strict_types=1);

namespace DevRadar\Domain\Processing;

use DateTimeImmutable;

/**
 * A stored post as the processing stages see it.
 *
 * Deliberately narrow: the pipeline reads what it needs and nothing more.
 * The full provider payload stays in the database, available when a rule
 * changes and a stage has to be re-run.
 */
final readonly class ProcessableTweet
{
    public function __construct(
        public int $id,
        public string $xTweetId,
        public string $text,
        public ?string $primaryUrl,
        public DateTimeImmutable $postedAt,
        public ?string $lang = null,
        public ?int $authorId = null,
        public ?string $urlHash = null,
        public ?string $textFingerprint = null,
    ) {}

    public function withDerived(NormalizationResult $result): self
    {
        return new self(
            $this->id, $this->xTweetId, $this->text, $this->primaryUrl,
            $this->postedAt, $this->lang, $this->authorId,
            $result->urlHash, $result->textFingerprint,
        );
    }
}
