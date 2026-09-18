<?php

declare(strict_types=1);

namespace DevRadar\Domain\Port;

use DevRadar\Domain\Classification\LlmRequest;
use DevRadar\Domain\Classification\LlmResponse;
use DevRadar\Domain\Classification\ModelIdentity;

/**
 * Transport-only boundary for a large language model.
 *
 * Implementations carry NO prompt text and NO domain knowledge. They move a
 * request to a model and return the response. Adding a provider means
 * implementing two methods and changing one configuration value.
 *
 * Cross-cutting concerns are decorators around a concrete provider, never
 * baked into one:
 *
 *   AiClassifier -> Caching -> Retrying -> CostTracking -> <concrete provider>
 *
 * Note: this port took typed DTOs in place of the associative arrays it was
 * first declared with. Arrays gave no compile-time safety on the one boundary
 * where a silently wrong key means a silently wrong classification.
 */
interface LlmProviderInterface
{
    /**
     * @throws \DevRadar\Domain\Classification\LlmException on any provider failure
     */
    public function complete(LlmRequest $request): LlmResponse;

    /**
     * Provider, model and model version currently in use.
     *
     * Stamped onto every stored decision so model drift is detectable.
     */
    public function identity(): ModelIdentity;
}
