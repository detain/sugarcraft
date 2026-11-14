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
     * Escape the cardinality-key separators (backslash, pipe, equals, NUL)
     * inside one tag key or value. Backslash is replaced first so the
     * escapes themselves stay unambiguous. NUL is included because
     * PrometheusFileBackend uses it as its name/label storage separator —
     * an escaped tagKey that never carries a raw NUL cannot forge a split
     * if it ever rides a NUL-delimited path.
     */
    private static function escapeTagToken(string $token): string
    {
        return \str_replace(['\\', '|', '=', "\0"], ['\\\\', '\\|', '\\=', '\\0'], $token);
    }

    /**
     * The storage-key NAME segment gets the identical escape a tag token
     * gets (see {@see self::escapeTagToken()}). In-memory accumulation
     * accepts arbitrary raw names — there is no sanitize-name gate on that
     * path — so without this the name 'x|y=1' would land in exactly the
     * bucket 'x' + tags ['y' => '1'] folds into (finding 013). One escaper
     * for every segment makes the separator unforgeable from both sides,
     * and the map stays readable: a clean name escapes to itself, so the
     * storage key of every well-behaved series is unchanged.
     */
    public static function nameToken(string $name): string
    {
        return self::escapeTagToken($name);
    }

    /**
     * Canonical exposition spellings for non-finite readings.
     *
     * `sprintf('%.6f', -INF)` renders "INF" — the sign silently flips and
     * the byte is not what Prometheus/OpenMetrics parsers accept. Both
     * line-oriented backends route every value through this table first so
     * a divide-by-zero rate publishes `-Inf` rather than a wrong-signed
     * or parser-rejecting token.
     *
     * @return '+Inf'|'-Inf'|'NaN'|null null when the value is finite
     */
    public static function nonFiniteToken(float $value): ?string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if ($value === \INF) {
            return '+Inf';
        }
        if ($value === -\INF) {
            return '-Inf';
        }
        return null;
    }

    /**
     * True when the fixed six-decimal rendering would lie: magnitudes
     * beyond the integer-precision band print noise, and non-zero values
     * below 1e-6 collapse to "0.000000" (a sub-microsecond timed() span
     * reporting a zero sum). Exponent form keeps both honest.
     */
    public static function needsExponentForm(float $value): bool
    {
        $abs = abs($value);
        return $abs >= 1e15 || ($value != 0.0 && $abs < 1e-6);
    }

    /**
     * Sweep terminal-control bytes out of a string that is about to be
     * interpolated into an exception message. TUI hosts print throwable
     * messages verbatim, and metric names/paths are operator- (or in the
     * SSH middleware, attacker-) controlled — delegating to the shared
     * sugar-core display sanitizer keeps the sweep consistent fleet-wide.
     */
    public static function displaySafe(string $text): string
    {
        return \SugarCraft\Core\Util\Sanitize::untrustedForDisplay($text);
    }
}
