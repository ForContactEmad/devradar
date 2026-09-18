<?php

declare(strict_types=1);

namespace DevRadar\Domain\Pipeline;

/**
 * The stages of the discovery pipeline, and how each one relates to the next.
 *
 * STAGES ARE NOT CHAINED. Each drains its own input state on its own
 * schedule, and that decoupling is what delivers the fault tolerance the
 * pipeline needs:
 *
 *   If AI classification fails, filtered posts simply wait in their input
 *   state. Collection keeps collecting, normalization keeps normalizing, and
 *   scoring keeps re-ranking what is already published. A chain would have
 *   propagated the failure backwards and stopped all of it.
 *
 *   If GitHub is unreachable, enrichment records the failure and every other
 *   stage carries on. Nothing downstream of it blocks, because scoring treats
 *   a missing repository as an absent signal rather than a bad one.
 *
 * The cost of decoupling is latency: a post waits for the next tick of each
 * stage rather than flowing straight through. That is bought back with an
 * optional follow-on dispatch, which nudges the next stage after a successful
 * run WITHOUT making it a chain -- the follow-on's failure is its own.
 */
enum PipelineStage: string
{
    case Collect = 'collect';
    case Normalize = 'normalize';
    case Deduplicate = 'deduplicate';
    case Filter = 'filter';
    case Classify = 'classify';
    case Extract = 'extract';
    case Enrich = 'enrich';
    case Score = 'score';

    /**
     * Not a discovery step but a legal obligation: stored posts must reflect
     * what is still public on X. Placed in the pipeline so it gets the same
     * recording, locking and health reporting as every other stage -- a
     * compliance sweep that silently stopped is exactly the kind of failure
     * nobody notices until it matters.
     */
    case Comply = 'comply';

    /**
     * Does this stage spend money?
     *
     * The paid stages get different retry rules, a dedicated serialised
     * worker and a budget guard. Retrying a paid stage is a repeat purchase.
     */
    public function isPaid(): bool
    {
        return match ($this) {
            self::Collect, self::Classify, self::Extract, self::Enrich => true,
            // Compliance calls the API but is NOT billed per resource. It is
            // an obligation, not a purchase, and must never be skipped or
            // deferred to save money.
            default => false,
        };
    }

    /**
     * Which queue the stage runs on.
     *
     * Collection is alone on its own queue with a single worker, because two
     * concurrent ingestion runs mean duplicate paid fetches and the provider's
     * deduplication is documented as a soft guarantee.
     */
    public function queue(): string
    {
        return $this === self::Collect ? 'ingestion' : 'processing';
    }

    /**
     * The stage that naturally follows, for optional follow-on dispatch.
     *
     * Enrichment and scoring both follow extraction, but only one can be
     * named here; scoring runs frequently on its own schedule and does not
     * need the nudge.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Collect => self::Normalize,
            self::Normalize => self::Deduplicate,
            self::Deduplicate => self::Filter,
            self::Filter => self::Classify,
            self::Classify => self::Extract,
            self::Extract => self::Enrich,
            self::Enrich, self::Score, self::Comply => null,
        };
    }

    /**
     * How many times the queue may retry the stage.
     *
     * DELIBERATELY NOT DERIVED FROM isPaid(). The two happened to coincide
     * until compliance arrived, and collapsing them hid a real distinction:
     *
     *   Paid stages attempt once because a retry buys the same data twice.
     *
     *   Compliance attempts once for a different reason entirely -- it is not
     *   billed per resource, but its provider allows only one concurrent job,
     *   so an automatic retry races the job it just started. A stuck cycle
     *   needs a human, not another attempt.
     *
     *   Free stages retry freely: re-running normalization costs only CPU.
     */
    public function maxAttempts(): int
    {
        return match (true) {
            $this === self::Comply => 1,
            $this->isPaid() => 1,
            default => 3,
        };
    }

    public function timeoutSeconds(): int
    {
        return match ($this) {
            // Paginated fetches with backoff.
            self::Collect => 600,
            // One model call per post across a batch.
            self::Classify, self::Extract => 900,
            // Two HTTP calls per repository.
            self::Enrich => 600,
            // Uploading a large ID file, then downloading results.
            self::Comply => 600,
            default => 300,
        };
    }
}
