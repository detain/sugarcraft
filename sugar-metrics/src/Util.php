<?php

declare(strict_types=1);

namespace SugarCraft\Metrics;

/**
 * Shared utility functions for the metrics library.
 */
final class Util
{
    /**
     * Sort tags by key and build a stable `k=v|k2=v2` string.
     * Used for cardinality tracking (Registry) and storage keys
     * (InMemoryBackend) to ensure identical (name, tags) tuples
     * always produce the same normalized representation.
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
            $parts[] = "{$k}={$v}";
        }
        return implode('|', $parts);
    }
}
