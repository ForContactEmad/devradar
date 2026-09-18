<?php

declare(strict_types=1);

namespace DevRadar\Domain\Collection;

use InvalidArgumentException;

/**
 * Builds query expressions from signal phrases supplied by configuration.
 *
 * No phrase is written down in this class. It receives a group of signals and
 * a set of modifiers and returns one or more expressions -- and that
 * separation is the point: tuning the signal list is the core iterative
 * activity of this product, and it must never require a code change.
 *
 * SPLITTING IS THE INTERESTING PART. A long OR list will exceed the provider's
 * query-length limit. There are three things you can do about that, and only
 * one of them is correct:
 *
 *   - Truncate the list. Silently changes what is matched, and the shortened
 *     query still costs money to run.
 *   - Send it anyway. Rejected, and a rejected request is still a round trip.
 *   - Split it into several queries that together cover the same signals.
 *
 * This class does the third. The caller gets back a list of expressions, each
 * guaranteed to fit, and each billed separately -- which is why the number of
 * chunks produced is a cost signal worth logging.
 */
final readonly class QueryComposer
{
    public function __construct(private int $maxLength = 512) {}

    /**
     * @param list<string> $signals   phrases; multi-word ones are quoted
     * @param list<string> $modifiers operators appended to every chunk
     *
     * @return list<string>
     */
    public function compose(array $signals, array $modifiers = []): array
    {
        $signals = array_values(array_filter(array_map('trim', $signals), fn ($s) => $s !== ''));

        if ($signals === []) {
            throw new InvalidArgumentException('Cannot compose a query from an empty signal list.');
        }

        $suffix = $modifiers === [] ? '' : ' ' . implode(' ', $modifiers);
        // "(" + ")" wrapping the OR group.
        $overhead = mb_strlen($suffix) + 2;

        $expressions = [];
        $current = [];

        foreach ($signals as $signal) {
            $term = $this->term($signal);
            $candidate = $current === [] ? $term : implode(' OR ', [...$current, $term]);

            if (mb_strlen($candidate) + $overhead > $this->maxLength) {
                if ($current === []) {
                    // A single term that cannot fit even alone. Splitting
                    // cannot help, so say so rather than emitting a query
                    // that will be rejected on arrival.
                    throw new InvalidArgumentException(sprintf(
                        'Signal %s cannot fit within the %d character limit even on its own.',
                        $term,
                        $this->maxLength,
                    ));
                }

                $expressions[] = $this->wrap($current, $suffix);
                $current = [$term];

                continue;
            }

            $current[] = $term;
        }

        if ($current !== []) {
            $expressions[] = $this->wrap($current, $suffix);
        }

        return $expressions;
    }

    /**
     * Turns one configured group into named, versioned definitions.
     *
     * When a group splits, each chunk gets its own suffixed name so the run
     * ledger can attribute cost and yield to the exact chunk rather than to
     * the group as a whole. Without that, a group where one chunk carries all
     * the signal and three carry none looks like a mediocre group.
     *
     * @param list<string> $signals
     * @param list<string> $modifiers
     *
     * @return list<SearchQueryDefinition>
     */
    public function defineGroup(
        string $name,
        string $family,
        array $signals,
        array $modifiers = [],
        int $maxResults = 100,
    ): array {
        $expressions = $this->compose($signals, $modifiers);
        $definitions = [];
        $multiple = count($expressions) > 1;

        foreach ($expressions as $index => $expression) {
            $definitions[] = new SearchQueryDefinition(
                id: null,
                name: $multiple ? sprintf('%s-%d', $name, $index + 1) : $name,
                family: $family,
                expression: $expression,
                maxResults: $maxResults,
            );
        }

        return $definitions;
    }

    private function term(string $signal): string
    {
        // Already an operator or an explicitly quoted phrase: pass through.
        if (str_contains($signal, ':') || str_starts_with($signal, '"') || str_starts_with($signal, '#')) {
            return $signal;
        }

        return str_contains($signal, ' ') ? '"' . $signal . '"' : $signal;
    }

    /** @param list<string> $terms */
    private function wrap(array $terms, string $suffix): string
    {
        return '(' . implode(' OR ', $terms) . ')' . $suffix;
    }
}
