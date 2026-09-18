<?php

declare(strict_types=1);

namespace DevRadar\Domain\Processing;

/**
 * Decides whether a post is already known. Pure, no I/O.
 *
 * THREE LEVELS, CHEAPEST FIRST. The order is not arbitrary -- each level is
 * both more expensive and less certain than the one above it, so stopping
 * early is both faster and safer:
 *
 *   1. X Tweet ID       exact identity. The primary key, per the requirement.
 *                       The same post surfacing in several search queries is
 *                       the expected case, not an anomaly, and it must be
 *                       processed exactly once.
 *
 *   2. Canonical URL    different posts, one destination. Five accounts
 *                       announcing one repository. This is the level that
 *                       makes the feed show projects rather than posts.
 *
 *   3. Text fingerprint different posts, one wording. Catches a project
 *                       announced twice with different shortened links, where
 *                       the URL level cannot help.
 *
 * DELIBERATELY NOT INCLUDED: fuzzy similarity. Near-duplicate matching needs
 * a trigram index and belongs in the database, not in a pure comparison. A
 * naive in-memory version would be O(n^2) over the window and would silently
 * merge distinct projects with similar announcements, which is a worse
 * failure than missing a duplicate: a missed duplicate shows one project
 * twice, a wrong merge hides one entirely.
 */
final readonly class TweetDeduplicator
{
    public function decide(ProcessableTweet $tweet, SeenIndex $index): DuplicateDecision
    {
        $sameId = $index->tweetId($tweet->xTweetId);

        // A post can never be its own duplicate. Without this guard, a
        // re-run over already-indexed rows would mark every post a duplicate
        // of itself and empty the pipeline.
        if ($sameId !== null && $sameId !== $tweet->id) {
            return DuplicateDecision::duplicateOf($sameId, DuplicateDecision::LEVEL_TWEET_ID);
        }

        if ($tweet->urlHash !== null) {
            $byUrl = $index->urlHash($tweet->urlHash);

            if ($byUrl !== null && $byUrl !== $tweet->id) {
                return DuplicateDecision::duplicateOf($byUrl, DuplicateDecision::LEVEL_URL);
            }
        }

        if ($tweet->textFingerprint !== null) {
            $byText = $index->fingerprint($tweet->textFingerprint);

            if ($byText !== null && $byText !== $tweet->id) {
                return DuplicateDecision::duplicateOf($byText, DuplicateDecision::LEVEL_TEXT);
            }
        }

        return DuplicateDecision::unique();
    }

    /**
     * Deduplicates a batch in one pass, in the order given.
     *
     * Order matters: the first post seen for a project is the survivor, so
     * callers should present posts oldest-first if they want the original
     * announcement to win rather than whichever amplification arrived last.
     *
     * @param list<ProcessableTweet> $tweets
     *
     * @return array<int, DuplicateDecision> keyed by local tweet id
     */
    public function decideBatch(array $tweets, SeenIndex $index): array
    {
        $decisions = [];

        foreach ($tweets as $tweet) {
            $decision = $this->decide($tweet, $index);
            $decisions[$tweet->id] = $decision;

            // Only survivors enter the index. Indexing a duplicate would make
            // it the survivor for the next post with the same key, producing
            // chains that point at rows already marked as duplicates.
            if (! $decision->isDuplicate) {
                $index->remember($tweet);
            }
        }

        return $decisions;
    }
}
