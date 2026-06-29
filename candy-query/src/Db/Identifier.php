<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Centralized SQL identifier quoting for multi-driver support.
 *
 * Database identifiers (table names, column names, index names) must be
 * quoted differently depending on the database flavor:
 *   - MySQL / MariaDB / Percona: backtick `` ` `` with internal backtick doubling
 *   - PostgreSQL / SQLite: double-quote " " with internal quote doubling
 *
 * This consolidates the four hand-rolled idioms previously scattered across
 * MysqlDatabase::rows, SqliteDatabase::rows, SqliteSchemaProvider::safeIdent,
 * and PreviewQuery::quoteIdent into a single, testable entry point.
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
            // MySQL, MariaDB, Percona use backtick quoting with internal doubling
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => '`' . str_replace('`', '``', $name) . '`',
            // PostgreSQL and SQLite use double-quote quoting with internal doubling
            Flavor::Postgres, Flavor::Sqlite => '"' . str_replace('"', '""', $name) . '"',
        };
    }
}
