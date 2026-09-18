<?php

declare(strict_types=1);

namespace DevRadar\Infrastructure\Persistence;

use DevRadar\Domain\Filtering\FilterableTweet;
use DevRadar\Domain\Filtering\FilterDecision;
use DevRadar\Domain\Port\FilteringRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Persistence for the pre-filter stage.
 */
final readonly class EloquentFilteringRepository implements FilteringRepositoryInterface
{
    /** @param list<string> $repositoryHosts */
    public function __construct(private array $repositoryHosts = []) {}

    /** @return list<FilterableTweet> */
    public function claimForFiltering(int $limit): array
    {
        $rows = DB::table('tweets')
            ->select('id', 'text', 'normalized_text', 'canonical_url', 'primary_url', 'lang', 'raw_payload')
            ->where('status', 'deduplicated')
            ->whereNull('purged_at')
            ->orderBy('posted_at')
            ->limit($limit)
            ->get();

        $tweets = [];

        foreach ($rows as $row) {
            // Fall back to the original text if normalization has not run.
            // Filtering un-normalized text is worse than not filtering, but
            // failing the batch outright is worse still.
            $text = $row->normalized_text ?? $row->text;
            $url = $row->canonical_url ?? $row->primary_url;

            $tweets[] = new FilterableTweet(
                id: (int) $row->id,
                normalizedText: (string) $text,
                hasLink: $url !== null && $url !== '',
                hasRepositoryLink: $this->isRepositoryLink($url),
                isRepost: $this->isRepost($row->raw_payload),
                lang: $row->lang === null ? null : (string) $row->lang,
            );
        }

        return $tweets;
    }

    public function saveDecision(int $tweetId, FilterDecision $decision): void
    {
        DB::table('tweets')->where('id', $tweetId)->update([
            'signal_score' => $decision->score->total,
            'signal_strength' => $decision->score->strength->value,
            'signal_breakdown' => json_encode($decision->score->breakdown()),
            'status' => $decision->passes ? 'filtered' : 'rejected',
            'reject_reason' => $decision->rejectReason,
            'processed_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function isRepositoryLink(?string $url): bool
    {
        if ($url === null || $this->repositoryHosts === []) {
            return false;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return in_array($host, $this->repositoryHosts, true);
    }

    private function isRepost(mixed $rawPayload): bool
    {
        if (! is_string($rawPayload)) {
            return false;
        }

        $payload = json_decode($rawPayload, true);

        if (! is_array($payload)) {
            return false;
        }

        // referenced_posts is the current field name; referenced_tweets is
        // the legacy one. Both are read, as in the provider mapper.
        foreach ($payload['referenced_posts'] ?? $payload['referenced_tweets'] ?? [] as $reference) {
            if (is_array($reference) && ($reference['type'] ?? null) === 'retweeted') {
                return true;
            }
        }

        return false;
    }
}
