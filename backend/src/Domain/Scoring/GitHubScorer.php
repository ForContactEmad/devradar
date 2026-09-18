<?php

declare(strict_types=1);

namespace DevRadar\Domain\Scoring;

/**
 * Scores repository signals when a repository exists. Pure, no I/O.
 *
 * ABSENCE IS NOT A ZERO. Most launches on a social platform link to a product
 * page, not a repository, and a hosted SaaS with no public code is a
 * perfectly good project. Scoring it zero here would mean "this project has a
 * bad repository", which is a claim nobody made. The component reports itself
 * unavailable and the engine redistributes its weight.
 *
 * This class reads whatever repository data has already been stored. It makes
 * no network calls -- GitHub integration is a later phase, and until then
 * this component is simply unavailable for every project, which is exactly
 * the behaviour it should have.
 *
 * STARS ARE LOG-COMPRESSED for the same reason engagement is: the
 * distribution is heavy-tailed, and a 40,000-star repository should outrank a
 * 400-star one without erasing it.
 *
 * PUSH RECENCY MATTERS AS MUCH AS STARS. A repository with 2,000 stars and no
 * commit in three years is an artefact, not a launch. Weighting recent
 * activity alongside popularity is what separates the two.
 */
final readonly class GitHubScorer
{
    public const NAME = 'github';

    public function __construct(
        private float $starCap,
        private float $forkCap,
        private float $starShare,
        private float $forkShare,
        private float $freshnessShare,
        /** Days since the last push at which freshness reaches zero. */
        private float $staleAfterDays,
    ) {}

    public function score(ScoreInput $input): ScoreComponent
    {
        if (! $input->hasRepository()) {
            return ScoreComponent::unavailable(
                self::NAME,
                0.0,
                'No repository data (project has no repository link, or enrichment has not run).',
            );
        }

        $stars = $this->compress((float) ($input->repositoryStars ?? 0), $this->starCap);
        $forks = $this->compress((float) ($input->repositoryForks ?? 0), $this->forkCap);
        $freshness = $this->freshness($input);

        $shares = ['star' => $this->starShare, 'fork' => $this->forkShare, 'fresh' => $this->freshnessShare];

        // A repository with stars but no recorded push date should not be
        // penalised for a field enrichment did not fill.
        if ($freshness === null) {
            $shares['fresh'] = 0.0;
        }

        $total = array_sum($shares);

        $value = $total > 0
            ? (($stars * $shares['star']) + ($forks * $shares['fork']) + (($freshness ?? 0.0) * $shares['fresh'])) / $total
            : 0.0;

        return new ScoreComponent(
            name: self::NAME,
            value: max(0.0, min(1.0, $value)),
            weight: 0.0,
            explanation: sprintf(
                '%d stars (%.2f), %d forks (%.2f), freshness %s.',
                $input->repositoryStars ?? 0,
                $stars,
                $input->repositoryForks ?? 0,
                $forks,
                $freshness === null ? 'unknown' : sprintf('%.2f', $freshness),
            ),
            inputs: [
                'stars' => $input->repositoryStars,
                'forks' => $input->repositoryForks,
                'pushed_at' => $input->repositoryPushedAt?->format(DATE_ATOM),
            ],
        );
    }

    private function freshness(ScoreInput $input): ?float
    {
        if ($input->repositoryPushedAt === null) {
            return null;
        }

        $days = ($input->now->getTimestamp() - $input->repositoryPushedAt->getTimestamp()) / 86400;

        if ($days <= 0) {
            return 1.0;
        }

        // Linear decay here rather than exponential: unlike a post, a
        // repository does not become irrelevant quickly, and the question is
        // "is this maintained" rather than "is this news".
        return max(0.0, 1.0 - ($days / $this->staleAfterDays));
    }

    private function compress(float $value, float $cap): float
    {
        if ($value <= 0.0 || $cap <= 0.0) {
            return 0.0;
        }

        return max(0.0, min(1.0, log1p($value) / log1p($cap)));
    }
}
