<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Database interface for candy-query.
 *
 * Abstracts the database operations so App can work with any
 * DatabaseInterface implementation (SQLite, MySQL, Postgres, or test fakes).
 *
 * @see Mirrors charmbracelet/lazysql Database interface
 */
interface DatabaseInterface
{
    /**
     * Get all table/view names in the database.
     *
     * @return list<string>
     */
    public function tables(): array;

    /**
     * Get rows from a specific table.
     *
     * @param string $table Table name
     * @param int $limit Maximum rows to return
     * @return list<array<string,mixed>>
     */
    public function rows(string $table, int $limit = 100): array;

    /**
     * Execute an arbitrary SQL query.
     *
     * For SELECT statements returns the result set.
     * For other statements returns [['affected' => N]].
     *
     * @return list<array<string,mixed>>
     */
    public function query(string $sql): array;

    /**
     * Get the ID of the last inserted row.
     *
     * @return string|int
     */
    public function lastInsertId(): string|int;

    /**
     * Quote a string value for safe SQL inclusion.
     *
     * @param string $value Value to quote
     * @return string Quoted value
     */
    public function quote(string $value): string;

    /**
     * Execute a non-select SQL statement.
     *
     * @param string $sql SQL statement to execute
     * @return int Number of affected rows
     */
    public function exec(string $sql): int;

    /**
     * Close the database connection.
     */
    public function close(): void;
}
