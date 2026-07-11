<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Simple query logger for debugging admin pane queries.
 *
 * Collects log entries with timestamp, query type, SQL, row count,
 * and optional error message. Entries are stored in a static array
 * and can be retrieved via getEntries().
 *
 * For production, entries would be shown in a debug pane or overlay.
 */
final class QueryLogger
{
    /** @var list<array{timestamp: float, type: string, sql: string, rows: int, error: string|null}> */
    private static array $entries = [];

    private const MAX_ENTRIES = 100;

    /**
     * Log a query execution.
     *
     * Credential material is redacted before the entry is retained: this log is
     * kept in memory and rendered on the Debug pane, so a statement like
     * `CREATE USER x IDENTIFIED BY 'secret'` must never persist the plaintext
     * password. The error text is redacted too, since driver errors frequently
     * echo the offending statement verbatim.
     */
    public static function log(string $type, string $sql, int $rows, ?string $error = null): void
    {
        self::$entries[] = [
            'timestamp' => microtime(true),
            'type' => $type,
            'sql' => self::redact($sql),
            'rows' => $rows,
            'error' => $error === null ? null : self::redact($error),
        ];

        // Keep only the most recent entries
        if (count(self::$entries) > self::MAX_ENTRIES) {
            self::$entries = array_slice(self::$entries, -self::MAX_ENTRIES);
        }
    }

    /**
     * Replace passwords in credential-bearing statements with `'***'` while
     * preserving the statement shape.
     *
     * Covers the common MySQL and PostgreSQL credential forms:
     *   - `IDENTIFIED BY '<secret>'` / `IDENTIFIED BY PASSWORD '<secret>'`
     *   - `IDENTIFIED WITH <plugin> BY|AS '<secret>'`
     *   - `PASSWORD('<secret>')` (function form)
     *   - `PASSWORD [=] '<secret>'` (CREATE ROLE / ALTER USER / SET PASSWORD)
     *
     * Patterns run in order (each on the previous result), so the function form
     * is handled before the bare `PASSWORD '...'` form. The quoted-string body
     * tolerates backslash escapes so an embedded quote doesn't cut redaction short.
     */
    private static function redact(string $sql): string
    {
        $patterns = [
            '/(IDENTIFIED\s+WITH\s+\S+\s+(?:BY|AS)\s+)([\'"])(?:\\\\.|(?!\2).)*\2/i',
            '/(IDENTIFIED\s+BY\s+(?:PASSWORD\s+)?)([\'"])(?:\\\\.|(?!\2).)*\2/i',
            '/(PASSWORD\s*\(\s*)([\'"])(?:\\\\.|(?!\2).)*\2(\s*\))/i',
            '/(PASSWORD\s*=?\s*)([\'"])(?:\\\\.|(?!\2).)*\2/i',
        ];
        $replacements = [
            "$1'***'",
            "$1'***'",
            "$1'***'$3",
            "$1'***'",
        ];

        return preg_replace($patterns, $replacements, $sql) ?? $sql;
    }

    /**
     * Get all logged entries.
     *
     * @return list<array{timestamp: float, type: string, sql: string, rows: int, error: string|null}>
     */
    public static function getEntries(): array
    {
        return self::$entries;
    }

    /**
     * Clear all log entries.
     */
    public static function clear(): void
    {
        self::$entries = [];
    }

    /**
     * Get entries formatted for display.
     *
     * @return list<string>
     */
    public static function getDisplayLines(): array
    {
        $lines = [];
        foreach (self::$entries as $entry) {
            $ts = date('H:i:s', (int) $entry['timestamp']);
            $ms = (int)(($entry['timestamp'] - floor($entry['timestamp'])) * 1000);
            $time = sprintf('%s.%03d', $ts, $ms);
            $type = str_pad($entry['type'], 10);
            $rows = $entry['rows'] > 0 ? "{$entry['rows']} rows" : '';
            $error = $entry['error'] !== null ? " ERR: {$entry['error']}" : '';
            $lines[] = "{$time} {$type} {$entry['sql']} {$rows}{$error}";
        }
        return $lines;
    }
}
