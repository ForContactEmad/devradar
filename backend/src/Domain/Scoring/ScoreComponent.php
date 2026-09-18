<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

use InvalidArgumentException;

/**
 * One dimension of a project's score.
 *
 * Every component reports a NORMALISED value in 0..1 plus the weight it was
 * given. Keeping normalisation and weighting separate is what makes the
 * formula inspectable: you can see that a project scored 0.9 on engagement
 * and that engagement was worth 35%, rather than seeing a single number
 * nobody can take apart.
 *
 * `available` is not the same as a value of 0. A project with no repository
 * has no GitHub signal; scoring it zero would say "this project has a bad
 * repository", which is a different and false claim.
 *
 * `redistributable` decides what happens to an unavailable component's
 * weight, and the distinction matters more than it looks:
 *
 *   REDISTRIBUTABLE (github, growth) - structurally absent. Most launches
 *   link to a product page, not a repository, and a brand-new project has no
 *   trajectory. These projects are complete as they are, so the weight moves
 *   to the components that DO apply and the project competes fairly.
 *
 *   FORFEITED (engagement, confidence) - temporarily missing. The metrics
 *   exist, we just have not fetched them yet. Redistributing here would mean
 *   a project we know NOTHING about outranks one with proven engagement,
 *   because ignorance would inherit the weight of evidence. The weight is
 *   lost instead, so the score is out of less than the full scale until the
 *   data arrives and the rescore sweep fills it in.
 */
final readonly class ScoreComponent
{
    public function __construct(
        public string $name,
        public float $value,
        public float $weight,
        public bool $available = true,
        /** Human-readable justification, stored with the score. */
        public string $explanation = '',
        /** @var array<string, mixed> the inputs that produced the value */
        public array $inputs = [],
        public bool $redistributable = true,
    ) {
        if ($available && ($value < 0.0 || $value > 1.0)) {
            throw new InvalidArgumentException(
                "Component '{$name}' produced {$value}; components must normalise to 0..1 so weights mean what they say."
            );
        }

        if ($weight < 0.0) {
            throw new InvalidArgumentException("Component '{$name}' has a negative weight.");
        }
    }

    public static function unavailable(string $name, float $weight, string $reason): self
    {
        return new self($name, 0.0, $weight, false, $reason);
    }

    /**
     * Unavailable, and its weight is lost rather than moved.
     *
     * For signals that are temporarily missing rather than structurally
     * absent: we cannot claim a project earned attention we have not measured.
     */
    public static function forfeited(string $name, float $weight, string $reason): self
    {
        return new self($name, 0.0, $weight, false, $reason, [], false);
    }

    /** Contribution under an effective weight, after redistribution. */
    public function contribution(float $effectiveWeight): float
    {
        return $this->available ? $this->value * $effectiveWeight : 0.0;
    }
}
