<?php

declare(strict_types=1);

namespace App\Console\Commands;

use DevRadar\Application\Filtering\FilterRunner;
use DevRadar\Domain\Filtering\FilterableTweet;
use DevRadar\Domain\Filtering\TweetFilter;
use Illuminate\Console\Command;

/**
 * Entry point for the pre-filter, plus a way to try a phrase by hand.
 *
 * The --explain option exists because tuning a signal set without being able
 * to ask "why did this score 4" is guesswork, and guesswork here directly
 * moves AI spend.
 */
final class FilterTweetsCommand extends Command
{
    protected $signature = 'devradar:filter
                            {--limit= : Override the configured batch size}
                            {--explain= : Score one piece of text and show the breakdown, without touching the database}
                            {--repo : Treat the explained text as carrying a repository link}';

    protected $description = 'Score collected posts and decide which reach AI classification.';

    public function handle(FilterRunner $runner, TweetFilter $filter): int
    {
        $explain = $this->option('explain');

        if (is_string($explain) && $explain !== '') {
            $decision = $filter->decide(new FilterableTweet(
                id: 0,
                normalizedText: $explain,
                hasLink: true,
                hasRepositoryLink: (bool) $this->option('repo'),
            ));

            $score = $decision->score;

            $this->line('');
            $this->line('  ' . ($decision->passes ? '<info>PASS</info>' : '<comment>REJECT</comment>')
                . sprintf('   score %d   strength %s', $score->total, $score->strength->value));

            if ($decision->explanation !== null) {
                $this->line('  ' . $decision->explanation);
            }

            $rows = [];

            foreach ($score->positives as $match) {
                $rows[] = ['+', $match->phrase, $match->weight, $match->group, $match->occurrences];
            }

            foreach ($score->negatives as $match) {
                $rows[] = ['-', $match->phrase, $match->weight, $match->group, $match->occurrences];
            }

            $rows[] = ['', 'structural', $score->structuralPoints, '', ''];

            $this->table(['', 'Phrase', 'Weight', 'Group', 'Times'], $rows);

            return self::SUCCESS;
        }

        $limit = $this->option('limit');
        $stats = $runner->run($limit !== null ? (int) $limit : (int) config('filtering.batch_size'));

        $this->info(sprintf(
            'Filter: %d claimed, %d passed, %d rejected, %d failed. Pass rate %.1f%%.',
            $stats['claimed'], $stats['passed'], $stats['rejected'], $stats['failed'], $stats['pass_rate'] * 100,
        ));

        return self::SUCCESS;
    }
}
