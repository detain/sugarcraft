<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Builds blob-safe "row preview" SQL for the TUI table browser.
 *
 * The browser shows a small peek at a table's rows. Selecting a column of raw
 * bytes — a LONGBLOB image, a BYTEA payload — transfers megabytes per row over
 * the wire and dumps binary into the grid. On a remote server a single
 * `SELECT *` over a media table can move hundreds of MB and freeze the UI for
 * minutes. previewSql lists every column explicitly but replaces blob/binary
 * (and large-document) columns with a lightweight `[<type> <n> bytes]` size
 * placeholder built server-side, so the bytes never leave the database. A NULL
 * cell stays NULL.
 *
 * This is deliberately NOT the path exporters use — they call
 * {@see DatabaseInterface::rows()} (full `SELECT *`) because a dump must keep
 * blob fidelity. previewSql is the interactive *browse* path only.
 *
 * Pure + flavor-keyed (mirrors {@see \SugarCraft\Query\SchemaBrowser} provider
 * selection): {@see columnsSql()} yields the introspection query,
 * {@see classify()} normalises its rows, {@see build()} assembles the preview
 * SELECT. The caller runs the two queries — synchronously for SQLite (a local
 * file can't freeze the loop), on the React loop for MySQL/Postgres.
 */
final class PreviewQuery
{
    /** Default row cap for a browse preview. */
    public const DEFAULT_LIMIT = 100;

    /**
     * MySQL data types whose bytes we never pull into a row preview: the blob
     * family, raw binary, the multi-MB text variants, spatial, and JSON.
     * tinytext/text and char/varchar are bounded enough to show verbatim.
     */
    private const MYSQL_ELIDE = [
        'tinyblob', 'blob', 'mediumblob', 'longblob',
        'binary', 'varbinary',
        'mediumtext', 'longtext',
        'json',
        'geometry', 'point', 'linestring', 'polygon',
        'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection',
    ];

    /** Postgres types elided from a preview: binary + large-document types. */
    private const POSTGRES_ELIDE = ['bytea', 'json', 'jsonb', 'xml'];

    /** Postgres elided types that must be cast to text before octet_length(). */
    private const POSTGRES_TEXT_CAST = ['json', 'jsonb', 'xml'];

    /**
     * The introspection query listing a table's columns and their types.
     * MySQL/Postgres read information_schema; SQLite uses PRAGMA table_info.
     */
    public static function columnsSql(Flavor $flavor, string $table): string
    {
        return match ($flavor) {
            Flavor::Sqlite => sprintf(
                'PRAGMA table_info("%s")',
                str_replace('"', '""', $table),
            ),
            Flavor::Postgres => 'SELECT column_name, data_type FROM information_schema.columns '
                . "WHERE table_schema = CURRENT_SCHEMA() AND table_name = '"
                . str_replace("'", "''", $table)
                . "' ORDER BY ordinal_position",
            default => 'SELECT column_name, data_type FROM information_schema.columns '
                . "WHERE table_schema = DATABASE() AND table_name = '"
                . str_replace("'", "''", $table)
                . "' ORDER BY ordinal_position",
        };
    }

    /**
     * Normalise raw introspection rows into a column list with an elide flag.
     *
     * @param list<array<string,mixed>> $rows Rows from {@see columnsSql()}
     * @return list<array{name:string, type:string, elide:bool}>
     */
    public static function classify(Flavor $flavor, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            // MySQL upper-cases information_schema columns; PRAGMA uses name/type.
            $row = array_change_key_case($row, CASE_LOWER);
            if ($flavor === Flavor::Sqlite) {
                $name = isset($row['name']) ? (string) $row['name'] : '';
                $type = isset($row['type']) ? (string) $row['type'] : '';
            } else {
                $name = isset($row['column_name']) ? (string) $row['column_name'] : '';
                $type = isset($row['data_type']) ? (string) $row['data_type'] : '';
            }
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'type' => strtolower(trim($type)),
                'elide' => self::isElided($flavor, $type),
            ];
        }
        return $out;
    }

    /**
     * Assemble the blob-safe preview SELECT. Falls back to `SELECT *` when the
     * column list is empty (introspection failed / table not visible) so a
     * preview still renders.
     *
     * @param list<array{name:string, type:string, elide:bool}> $columns
     */
    public static function build(Flavor $flavor, string $table, array $columns, int $limit = self::DEFAULT_LIMIT): string
    {
        $limit = max(1, $limit);
        $quote = match ($flavor) {
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => '`',
            default => '"',
        };
        $tbl = self::quoteIdent($table, $quote);

        if ($columns === []) {
            return sprintf('SELECT * FROM %s LIMIT %d', $tbl, $limit);
        }

        $select = [];
        foreach ($columns as $col) {
            $id = self::quoteIdent($col['name'], $quote);
            $select[] = $col['elide']
                ? self::placeholder($flavor, $col['type'], $id)
                : $id;
        }

        return sprintf('SELECT %s FROM %s LIMIT %d', implode(', ', $select), $tbl, $limit);
    }

    private static function quoteIdent(string $ident, string $quote): string
    {
        return $quote . str_replace($quote, $quote . $quote, $ident) . $quote;
    }

    private static function isElided(Flavor $flavor, string $type): bool
    {
        $type = strtolower(trim($type));
        return match ($flavor) {
            // SQLite is loosely typed; any column declared with BLOB affinity.
            Flavor::Sqlite => str_contains($type, 'blob'),
            Flavor::Postgres => in_array($type, self::POSTGRES_ELIDE, true),
            default => in_array($type, self::MYSQL_ELIDE, true),
        };
    }

    /**
     * A server-side expression yielding `[<type> <n> bytes]` for the column, or
     * NULL when the cell is NULL (so NULL still renders as NULL). $id is the
     * already-quoted identifier.
     */
    private static function placeholder(Flavor $flavor, string $type, string $id): string
    {
        // $type is a system-catalog type name; keep only safe chars before
        // embedding it as a SQL string literal.
        $label = '[' . preg_replace('/[^a-z0-9_ ]/', '', $type) . ' ';

        return match ($flavor) {
            Flavor::Sqlite =>
                "'" . $label . "' || length($id) || ' bytes]' AS $id",
            Flavor::Postgres => in_array($type, self::POSTGRES_TEXT_CAST, true)
                ? "'" . $label . "' || octet_length(($id)::text) || ' bytes]' AS $id"
                : "'" . $label . "' || octet_length($id) || ' bytes]' AS $id",
            default =>
                "CONCAT('" . $label . "', OCTET_LENGTH($id), ' bytes]') AS $id",
        };
    }
}
