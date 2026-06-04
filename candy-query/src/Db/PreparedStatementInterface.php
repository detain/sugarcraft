<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * Driver-neutral prepared statement interface.
 *
 * Abstracts the common operations needed across SQLite, MySQL, and PostgreSQL
 * drivers so callers can work with a uniform interface without depending on
 * the raw PDOStatement type.
 */
interface PreparedStatementInterface
{
    /**
     * Execute the prepared statement with optional parameters.
     *
     * @param array<string, mixed>|null $params Parameters to bind
     * @return bool True on success, false on failure
     */
    public function execute(?array $params = null): bool;

    /**
     * Fetch the next row from the result set.
     *
     * @return array<string, mixed>|false Row data or false if no more rows
     */
    public function fetch(): array|false;

    /**
     * Fetch all rows from the result set.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(): array;

    /**
     * Get the number of rows affected by the last query.
     *
     * @return int Number of affected rows
     */
    public function rowCount(): int;

    /**
     * Close the cursor, enabling the statement to be executed again.
     */
    public function closeCursor(): bool;
}
