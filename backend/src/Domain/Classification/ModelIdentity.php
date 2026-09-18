<?php

declare(strict_types=1);

namespace DevRadar\Domain\Classification;

/**
 * Exactly what produced a decision.
 *
 * Stamped on every stored analysis. Without it, a provider silently updating
 * a model behind the same name degrades precision with no way to notice, and
 * no way to tell afterwards which decisions came from which model.
 */
final readonly class ModelIdentity
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $modelVersion = '',
        public string $promptVersion = '',
    ) {}

    public function withPromptVersion(string $version): self
    {
        return new self($this->provider, $this->model, $this->modelVersion, $version);
    }

    public function label(): string
    {
        return sprintf('%s/%s@%s', $this->provider, $this->model, $this->promptVersion ?: 'unversioned');
    }
}
