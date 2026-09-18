<?php

declare(strict_types=1);

namespace DevRadar\Domain\Filtering;

/**
 * Turns a post into a preliminary score. Pure, no I/O, no model call.
 *
 * TWO KINDS OF SIGNAL, and the second is the one keyword lists miss:
 *
 *   Keyword signals   configured phrases -- "just launched", "open sourced".
 *   Structural signals facts about the post that are not words at all: does
 *                      it link to a code host, is it a repost, does it link
 *                      anywhere. A link to github.com is stronger evidence of
 *                      a software launch than any phrase, and no amount of
 *                      keyword tuning can express it.
 *
 * WEIGHTS AND THRESHOLDS ARE INJECTED, never written down here. Tuning them
 * is continuous, and a class that has to be edited to change a threshold is a
 * class that stops the tuning happening.
 *
 * A phrase contributes its weight ONCE however often it occurs. A post
 * repeating "new tool" five times would otherwise outscore a genuine launch
 * that says it once.
 */
final readonly class ProjectSignalDetector
{
    /**
     * @param list<SignalDefinition>  $signals   positive and negative together
     * @param array<string, int>      $thresholds strength name => minimum total
     * @param array<string, int>      $structural structural signal => points
     */
    public function __construct(
        private KeywordMatcher $matcher,
        private array $signals,
        private array $thresholds = ['strong' => 5, 'medium' => 3, 'weak' => 1],
        private array $structural = [
            'repository_link' => 3,
            'any_link' => 1,
            'is_repost' => -4,
        ],
    ) {}

    public function detect(FilterableTweet $tweet): PreliminaryScore
    {
        $matches = $this->matcher->match($tweet->text(), $this->signals);

        $positives = [];
        $negatives = [];
        $total = 0;

        foreach ($matches as $match) {
            $total += $match->contribution();

            if ($match->weight < 0) {
                $negatives[] = $match;
            } else {
                $positives[] = $match;
            }
        }

        $structuralPoints = $this->structuralPoints($tweet);
        $total += $structuralPoints;

        return new PreliminaryScore(
            total: $total,
            strength: $this->strengthFor($total),
            positives: $positives,
            negatives: $negatives,
            structuralPoints: $structuralPoints,
        );
    }

    private function structuralPoints(FilterableTweet $tweet): int
    {
        $points = 0;

        if ($tweet->hasRepositoryLink) {
            $points += $this->structural['repository_link'] ?? 0;
        } elseif ($tweet->hasLink) {
            // Not cumulative: a repository link is already the stronger
            // version of "has a link", and counting both double-rewards it.
            $points += $this->structural['any_link'] ?? 0;
        }

        if ($tweet->isRepost) {
            $points += $this->structural['is_repost'] ?? 0;
        }

        return $points;
    }

    private function strengthFor(int $total): SignalStrength
    {
        return match (true) {
            $total >= ($this->thresholds['strong'] ?? 5) => SignalStrength::Strong,
            $total >= ($this->thresholds['medium'] ?? 3) => SignalStrength::Medium,
            $total >= ($this->thresholds['weak'] ?? 1) => SignalStrength::Weak,
            default => SignalStrength::None,
        };
    }
}
