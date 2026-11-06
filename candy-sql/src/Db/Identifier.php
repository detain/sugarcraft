<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

/**
 * Centralized SQL identifier quoting for multi-driver support.
 *
 * Database identifiers (table names, column names, index names) must be
 * quoted differently depending on the database flavor:
 *   - MySQL / MariaDB / Percona: backtick `` ` `` quoting, where BOTH `` `` `` and
 *     `` \` `` denote a literal backtick and BOTH `` \\ `` and (per the lexer)
 *     `` \ `` before a special char are escape channels
 *   - PostgreSQL / SQLite: double-quote " " with internal quote doubling
 *
 * This consolidates the four hand-rolled idioms previously scattered across
 * MysqlDatabase::rows, SqliteDatabase::rows, SqliteSchemaProvider::safeIdent,
 * and PreviewQuery::quoteIdent into a single, testable entry point.
 *
 * The MySQL arm escapes backslashes as well as backticks. MySQL's grammar treats
 * `\` as an escape character inside backtick-quoted identifiers (manual §9.2:
 * "quoted identifiers ... `\\` and `` `` ``"; the backslash channel is only
 * unavailable when NO_BACKSLASH_ESCAPES is set). Doubling backticks ALONE is
 * therefore bypassable: a server- or file-sourced name ending in `\` swallows
 * the closing backtick and splices the rest of the statement — the exact shape
 * of the D-C9 finding (hostile information_schema names, CSV import headers).
 * Under NO_BACKSLASH_ESCAPES `\` is literal and the doubling over-selects (a
 * lookup miss), never an injection: fail-loud, fail-safe in both grammars.
 * The double-quote arms have no backslash channel (PG standard_conforming_strings
 * on; SQLite literals are quote-doubling only) so they keep plain doubling.
 *
 * Mirrors charmbracelet/lazysql identifier quoting logic.
 */
final class Identifier
{
    /**
     * Quote a SQL identifier (table name, column name, etc.) for safe inclusion
     * in a query string, using the appropriate quote character for the given flavor.
     *
     * @param Flavor $flavor Database flavor
     * @param string $name Raw identifier name
     * @return string Properly quoted identifier, e.g. `` `users` `` or `"users"`
     */
    public static function quote(Flavor $flavor, string $name): string
    {
        return match ($flavor) {
            // MySQL, MariaDB, Percona: backtick quoting; escape `\` (first — it is
            // itself the escape char) and double any backtick.
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => '`'
                . str_replace(['\\', '`'], ['\\\\', '``'], $name)
                . '`',
            // PostgreSQL and SQLite use double-quote quoting with internal doubling
            Flavor::Postgres, Flavor::Sqlite => '"' . str_replace('"', '""', $name) . '"',
        };
    }

    /**
     * Quote a database identifier-VALUED string literal — the correct genre for
     * `WHERE table_name = <here>` comparisons against information_schema columns.
     *
     * An identifier quoting form is NOT a string literal on PostgreSQL (it parses
     * as a column reference), and value comparisons must not ride the identifier
     * grammar. This helper is pure and connection-free (PreviewQuery and friends
     * build SQL statically); escape semantics:
     *   - MySQL arm: double `\` and `'` (both grammars safe; identical results to
     *     mysql_real_escape_string for the ASCII-significant characters). The
     *     doubling is only quote-safe when the connection really negotiates a
     *     UTF-8-family (or single-byte) charset — and this library does NOT set
     *     one: ConnectionFactory sends no SET NAMES, no MYSQL_ATTR_INIT_COMMAND
     *     and takes no DSN charset= parameter, so the effective charset is the
     *     server's default_character_set from the handshake. Under a GBK- or
     *     SJIS-style server default a byte-level prefix collision could re-open
     *     the quote and PDO::quote's charset awareness would be required; the
     *     live-handle preference below is the mitigation, not a doc fiction.
     *   - Postgres/SQLite arm: standard SQL, double `'` only (backslash is not an
     *     escape inside standard literals with standard_conforming_strings=on).
     *
     * Prefer the live handle's {@see DatabaseInterface::quote()} when one is at
     * hand — it is charset-authoritative. This is the fallback for the pure
     * builders.
     */
    public static function quoteLiteral(Flavor $flavor, string $value): string
    {
        return match ($flavor) {
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => "'"
                . str_replace(['\\', "'"], ['\\\\', "''"], $value)
                . "'",
            Flavor::Postgres, Flavor::Sqlite => "'" . str_replace("'", "''", $value) . "'",
        };
    }
}
