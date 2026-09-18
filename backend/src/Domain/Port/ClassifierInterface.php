<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

/**
 * Task-level boundary for deciding whether a candidate is a software launch.
 *
 * This port describes WHAT is asked, never HOW a model is reached. Prompt
 * construction, response parsing and validation belong to the implementation
 * in src/Application/Classification; transport belongs to LlmProviderInterface.
 *
 * Keeping the two apart is deliberate: classification decides "is this a
 * launch and what is it", scoring decides "where does it rank". Merging them
 * produces an untestable, untunable service.
 */
interface ClassifierInterface
{
    /**
     * Classify a batch of candidates.
     *
     * Implementations MUST stamp every returned decision with the model and
     * prompt version that produced it, so model drift is detectable and the
     * labelled evaluation set can be replayed against a new model.
     *
     * Implementations MUST treat candidate text as untrusted data, never as
     * instruction, and MUST reject any extracted URL that does not appear
     * verbatim in the source post.
     *
     * @param  iterable<int, object> $candidates
     * @return iterable<int, object> one decision per candidate, order preserved
     */
    public function classify(iterable $candidates): iterable;
}
