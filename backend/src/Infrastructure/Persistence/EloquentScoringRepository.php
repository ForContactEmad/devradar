<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DateTimeImmutable;
use DevRadar\Domain\Port\ScoringRepositoryInterface;
use DevRadar\Domain\Scoring\MetricSnapshot;
use DevRadar\Domain\Scoring\ScoreBreakdown;
use DevRadar\Domain\Scoring\ScoreInput;
use Illuminate\Support\Facades\DB;

/**
 * Persistence for ranking.
 *
 * Reads the inputs the engine needs and writes the score back. Contains no
 * scoring logic whatsoever -- the formula lives in the pure domain engine,
 * which is why it can be exercised entirely without a database.
 */
final readonly class EloquentScoringRepository implements ScoringRepositoryInterface
{
    /** @return array<int, ScoreInput> */
    public function claimForScoring(int $limit, int $staleAfterMinutes): array
    {
        $threshold = now()->subMinutes($staleAfterMinutes);

        $rows = DB::table('projects')
            ->join('tweets', 'projects.primary_tweet_id', '=', 'tweets.id')
            ->join('authors', 'tweets.author_id', '=', 'authors.id')
            ->leftJoin('repositories', 'projects.repository_id', '=', 'repositories.id')
            ->select(
                'projects.id', 'projects.discovered_at', 'projects.extraction_confidence',
                'tweets.like_count', 'tweets.repost_count', 'tweets.reply_count',
                'tweets.quote_count', 'tweets.metrics_updated_at',
                'authors.followers_count',
                'repositories.stars', 'repositories.forks', 'repositories.pushed_at',
            )
            ->where('projects.is_visible', true)
            ->whereNull('projects.aged_out_at')
            ->where(function ($query) use ($threshold) {
                $query->whereNull('projects.score_updated_at')
                    ->orWhere('projects.score_updated_at', '<', $threshold);
            })
            // Stalest first: newest-first would starve older projects of
            // rescoring, and a score three hours old has drifted furthest
            // from reality.
            ->orderByRaw('projects.score_updated_at NULLS FIRST')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $confidences = $this->classificationConfidences($rows->pluck('id')->all());
        $histories = $this->histories($rows->pluck('id')->all());
        $now = new DateTimeImmutable();
        $inputs = [];

        foreach ($rows as $row) {
            $projectId = (int) $row->id;

            $inputs[$projectId] = new ScoreInput(
                postedAt: new DateTimeImmutable((string) $row->discovered_at),
                now: $now,
                // Null when metrics were never fetched, which the engine
                // treats differently from zero.
                likeCount: $row->metrics_updated_at === null ? null : (int) $row->like_count,
                repostCount: $row->metrics_updated_at === null ? null : (int) $row->repost_count,
                replyCount: $row->metrics_updated_at === null ? null : (int) $row->reply_count,
                quoteCount: $row->metrics_updated_at === null ? null : (int) $row->quote_count,
                // Bookmarks are requested from the provider but not stored on
                // tweets yet; null is the honest value until they are.
                bookmarkCount: null,
                authorFollowers: $row->followers_count === null ? null : (int) $row->followers_count,
                classificationConfidence: $confidences[$projectId] ?? null,
                extractionConfidence: $row->extraction_confidence === null ? null : (float) $row->extraction_confidence,
                repositoryStars: $row->stars === null ? null : (int) $row->stars,
                repositoryForks: $row->forks === null ? null : (int) $row->forks,
                repositoryPushedAt: $row->pushed_at === null ? null : new DateTimeImmutable((string) $row->pushed_at),
                history: $histories[$projectId] ?? [],
            );
        }

        return $inputs;
    }

    public function saveScore(int $projectId, ScoreBreakdown $breakdown): void
    {
        DB::table('projects')->where('id', $projectId)->update([
            'score' => round($breakdown->score, 5),
            /*
             * Recomputed with the score, from the same source row.
             *
             * Denormalised so sorting and filtering by engagement can use an
             * index; kept in step here because the rescore sweep is the one
             * place that already knows the metrics have moved.
             */
            'engagement_count' => DB::raw('coalesce((
                select t.like_count + t.repost_count + t.reply_count + t.quote_count
                from tweets t where t.id = projects.primary_tweet_id
            ), 0)'),
            'score_breakdown' => json_encode($breakdown->toArray()),
            'score_updated_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function recordSnapshot(int $projectId, ScoreInput $input, float $score, int $everyMinutes): void
    {
        $recent = DB::table('project_metrics')
            ->where('project_id', $projectId)
            ->where('captured_at', '>=', now()->subMinutes($everyMinutes))
            ->exists();

        if ($recent) {
            // One row per rescore would fill an append-only table with
            // near-identical measurements and make growth noisier, not
            // better-resolved.
            return;
        }

        DB::table('project_metrics')->insert([
            'project_id' => $projectId,
            'captured_at' => now(),
            'like_count' => $input->likeCount ?? 0,
            'repost_count' => $input->repostCount ?? 0,
            'reply_count' => $input->replyCount ?? 0,
            'quote_count' => $input->quoteCount ?? 0,
            'repository_stars' => $input->repositoryStars,
            'score' => round($score, 5),
            'created_at' => now(),
        ]);
    }

    public function ageOutBeyondWindow(int $windowHours): int
    {
        return DB::table('projects')
            ->whereNull('aged_out_at')
            ->where('discovered_at', '<', now()->subHours($windowHours))
            ->update(['aged_out_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  list<int> $projectIds
     * @return array<int, float>
     */
    private function classificationConfidences(array $projectIds): array
    {
        $rows = DB::table('projects')
            ->join('ai_analyses', 'ai_analyses.tweet_id', '=', 'projects.primary_tweet_id')
            ->whereIn('projects.id', $projectIds)
            ->where('ai_analyses.is_current', true)
            ->select('projects.id', 'ai_analyses.confidence')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            if ($row->confidence !== null) {
                $map[(int) $row->id] = (float) $row->confidence;
            }
        }

        return $map;
    }

    /**
     * @param  list<int> $projectIds
     * @return array<int, list<MetricSnapshot>>
     */
    private function histories(array $projectIds): array
    {
        $rows = DB::table('project_metrics')
            ->whereIn('project_id', $projectIds)
            ->orderBy('project_id')
            ->orderBy('captured_at')
            ->get();

        $histories = [];

        foreach ($rows as $row) {
            $histories[(int) $row->project_id][] = new MetricSnapshot(
                capturedAt: new DateTimeImmutable((string) $row->captured_at),
                likeCount: (int) $row->like_count,
                repostCount: (int) $row->repost_count,
                replyCount: (int) $row->reply_count,
                quoteCount: (int) $row->quote_count,
                repositoryStars: $row->repository_stars === null ? null : (int) $row->repository_stars,
            );
        }

        return $histories;
    }
}
