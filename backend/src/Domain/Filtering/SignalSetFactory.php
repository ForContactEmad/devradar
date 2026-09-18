<?php

declare(strict_types=1);

namespace DevRadar\Domain\Filtering;

/**
 * Builds SignalDefinition objects from configuration arrays.
 *
 * Pure and framework-free, so the same factory serves the composition root
 * and the tests. Its existence is what keeps every phrase out of the detector
 * and the filter.
 */
final class SignalSetFactory
{
    /**
     * @param  array<string, array{weight: int, phrases: list<string>, whole_word?: bool}> $groups
     * @return list<SignalDefinition>
     */
    public static function fromGroups(array $groups, string $language = 'en'): array
    {
        $signals = [];

        foreach ($groups as $group => $spec) {
            $weight = (int) ($spec['weight'] ?? 0);
            $wholeWord = (bool) ($spec['whole_word'] ?? true);

            foreach ($spec['phrases'] ?? [] as $phrase) {
                $signals[] = new SignalDefinition(
                    phrase: $phrase,
                    weight: $weight,
                    group: (string) $group,
                    language: $language,
                    wholeWord: $wholeWord,
                );
            }
        }

        return $signals;
    }

    /**
     * Positive and negative sets combined into the one list the detector
     * scans, so a post is scored in a single pass over its text.
     *
     * @param  array<string, mixed> $config
     * @return list<SignalDefinition>
     */
    public static function fromConfig(array $config): array
    {
        return array_merge(
            self::fromGroups($config['signals'] ?? []),
            self::fromGroups($config['negative_signals'] ?? []),
            self::fromGroups($config['signals_ar'] ?? [], 'ar'),
        );
    }
}
