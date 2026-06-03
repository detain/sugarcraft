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
     */
    public static function log(string $type, string $sql, int $rows, ?string $error = null): void
    {
        self::$entries[] = [
            'timestamp' => microtime(true),
            'type' => $type,
            'sql' => $sql,
            'rows' => $rows,
            'error' => $error,
        ];

        // Keep only the most recent entries
        if (count(self::$entries) > self::MAX_ENTRIES) {
            self::$entries = array_slice(self::$entries, -self::MAX_ENTRIES);
        }
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
