<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

/**
 * Scores how sure the pipeline is about this project. Pure, no I/O.
 *
 * TWO DIFFERENT CONFIDENCES, blended:
 *
 *   classification - "is this a launch at all"
 *   extraction     - "did we read the name, stack and links correctly"
 *
 * A project can be unmistakably a launch while its details stay ambiguous, so
 * they are not interchangeable. Classification carries more weight because a
 * wrong launch call puts something on the front page that does not belong
 * there, while a wrong tech tag is a smaller and more visible error.
 *
 * Both are optional. A project with neither reports the component as
 * unavailable rather than scoring zero -- zero would mean "we are certain
 * this is wrong", which is the opposite of not knowing.
 */
final readonly class ConfidenceScorer
{
    public const NAME = 'confidence';

    public function __construct(
        private float $classificationShare,
        private float $extractionShare,
    ) {}

    public function score(ScoreInput $input): ScoreComponent
    {
        $classification = $this->clamp($input->classificationConfidence);
        $extraction = $this->clamp($input->extractionConfidence);

        if ($classification === null && $extraction === null) {
            return ScoreComponent::forfeited(
                self::NAME,
                0.0,
                'No classification or extraction confidence recorded.',
            );
        }

        if ($extraction === null) {
            return $this->component($classification, 'classification confidence only', $input);
        }

        if ($classification === null) {
            return $this->component($extraction, 'extraction confidence only', $input);
        }

        $total = $this->classificationShare + $this->extractionShare;
        $blended = $total > 0
            ? (($classification * $this->classificationShare) + ($extraction * $this->extractionShare)) / $total
            : 0.0;

        return $this->component($blended, 'blended classification and extraction confidence', $input);
    }

    private function component(float $value, string $basis, ScoreInput $input): ScoreComponent
    {
        return new ScoreComponent(
            name: self::NAME,
            value: max(0.0, min(1.0, $value)),
            weight: 0.0,
            explanation: sprintf('%.3f from %s.', $value, $basis),
            inputs: [
                'classification' => $input->classificationConfidence,
                'extraction' => $input->extractionConfidence,
            ],
        );
    }

    private function clamp(?float $value): ?float
    {
        if ($value === null) {
            return null;
        }

        return max(0.0, min(1.0, $value));
    }
}
