<?php

declare(strict_types=1);

namespace SugarCraft\Metrics;

/**
 * Shared utility functions for the metrics library.
 */
final class Util
{
    /**
     * Sort tags by key and build a stable, collision-free
     * `k=v|k2=v2` string. Every key and value is backslash-escaped
     * first (`\` → `\\`, `|` → `\|`, `=` → `\=`), so a separator that
     * appears inside a tag payload cannot impersonate the pair
     * delimiter: without it, `['x' => 'a|y=b']` and
     * `['x' => 'a', 'y' => 'b']` would serialize identically and
     * collapse two distinct series into one cardinality slot.
     *
     * Used for cardinality tracking (Registry) and storage keys
     * (InMemoryBackend) to ensure identical (name, tags) tuples
     * always produce the same normalized representation — and
     * distinct tuples never share one.
     *
     * @param array<string,string> $tags
     */
    public static function tagKey(array $tags): string
    {
        if ($tags === []) {
            return '';
        }
        ksort($tags);
        $parts = [];
        foreach ($tags as $k => $v) {
            $parts[] = self::escapeTagToken((string) $k) . '=' . self::escapeTagToken((string) $v);
        }
        return implode('|', $parts);
    }

    /**
     * Escape the cardinality-key separators (backslash, pipe, equals)
     * inside one tag key or value. Backslash is replaced first so the
     * escapes themselves stay unambiguous.
     */
    private static function escapeTagToken(string $token): string
    {
        return \str_replace(['\\', '|', '='], ['\\\\', '\\|', '\\='], $token);
    }
}
