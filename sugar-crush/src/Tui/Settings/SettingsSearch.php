<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * The settings view's search: one query across every category.
 *
 * Two passes, in this order:
 *
 *  1. FUZZY over each key's label and over its key — one field at a time, so a
 *     query's letters cannot be collected across the two — with the matcher the
 *     "/" popup and the Ctrl+P palette use, in full-query mode: `cmpct` finds
 *     "compaction", and the best hits rank first;
 *  2. then a plain case-insensitive SUBSTRING pass, for the keys whose name
 *     does not say what they do: over the label, the key, the help sentence,
 *     the enum values, the category, and the environment variable and flag
 *     that override the key (N-P5) — so `SUGARCRUSH_MODEL` or `--model` finds
 *     the setting it locks. A query of several words matches a key holding
 *     EVERY word, in any order and in any of those fields: `tool timeout`
 *     finds the timeouts of the tools, not every key with either word.
 *
 * The help text is not fuzzy-matched on purpose: a full-query alignment over a
 * sentence matches almost any short query somewhere in it, and a search that
 * returns every row has stopped being a search.
 */
final class SettingsSearch
{
    private static ?SmithWatermanMatcher $matcher = null;

    private function __construct()
    {
    }

    /**
     * The definitions matching `$query`, best first. An empty query matches
     * nothing — the caller shows the category instead.
     *
     * @param list<SettingDefinition> $definitions
     * @return list<SettingDefinition>
     */
    public static function matches(string $query, array $definitions): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        /** @var array<string, list<SettingDefinition>> $byField */
        $byField = [];
        foreach ($definitions as $definition) {
            $byField[$definition->label][] = $definition;
            $byField[$definition->key][] = $definition;
        }

        self::$matcher ??= SmithWatermanMatcher::new(requireFullQuery: true);

        $found = [];
        foreach (self::$matcher->matchAll($query, array_map('strval', array_keys($byField))) as $result) {
            foreach ($byField[$result->haystack] ?? [] as $definition) {
                $found[$definition->key] ??= $definition;
            }
        }

        foreach ($definitions as $definition) {
            if (isset($found[$definition->key])) {
                continue;
            }

            if (self::holdsEveryTerm(self::haystack($definition), $query)) {
                $found[$definition->key] = $definition;
            }
        }

        return array_values($found);
    }

    /** Everything the substring pass looks in, as one string. */
    private static function haystack(SettingDefinition $definition): string
    {
        return implode(' ', [
            $definition->label,
            $definition->key,
            $definition->help,
            ...array_map('strval', $definition->enumValues),
            $definition->category->label(),
            (string) $definition->envVar,
            (string) $definition->cliFlag,
        ]);
    }

    private static function holdsEveryTerm(string $haystack, string $query): bool
    {
        $terms = preg_split('/\s+/u', $query, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($terms as $term) {
            if (mb_stripos($haystack, $term) === false) {
                return false;
            }
        }

        return $terms !== [];
    }
}
