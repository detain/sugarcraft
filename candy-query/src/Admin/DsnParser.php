<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Shared DSN value extractor for PDO-style connection strings.
 *
 * PDO DSNs use the form "<flavor>:<key>=<value>;<key>=<value>...".
 * This parser extracts individual values robustly:
 *   - The optional prefix (mysql:/pgsql:) is handled via the lookbehind,
 *     so it is not consumed from the key-value match itself.
 *   - Handles both "mysql:host=..." (prefixed) and "host=..." (bare) DSNs.
 *   - Returns null when the key is absent rather than returning an empty string.
 *
 * @see Mirrors charmbracelet/lazysql DsnParser
 */
final class DsnParser
{
    /**
     * Extract a value from a PDO-style DSN by key name.
     *
     * @param string $dsn  PDO DSN string, e.g. "mysql:host=localhost;port=3306"
     * @param string $key  Key to extract, e.g. "host", "port", "dbname"
     * @return string|null The extracted value, or null when the key is absent
     */
    public static function extract(string $dsn, string $key): ?string
    {
        // Match the key=value pair, with an optional flavor prefix handled via
        // lookbehind so it is not consumed from the kv match itself.
        // The lookbehind (?<=mysql:|pgsql:|(?<=;)|^)\b asserts the key is
        // preceded by the flavor prefix, a semicolon separator, or string start.
        $pattern = sprintf(
            '/(?<=mysql:|pgsql:|(?<=;)|(?<=\A))\b%s=([^;]+)/',
            preg_quote($key, '/'),
        );

        if (preg_match($pattern, $dsn, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
