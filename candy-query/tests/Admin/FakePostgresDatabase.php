<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\PreparedStatementInterface;

/**
 * Fake DatabaseInterface for testing PostgreSQL admin providers.
 *
 * Simulates PostgreSQL system catalogs (pg_settings, pg_stat_database,
 * pg_stat_activity, etc.) with configurable query responses.
 */
final class FakePostgresDatabase implements DatabaseInterface
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $queryResults = [];

    /** @var array<string, \PDOException> */
    private array $queryExceptions = [];

    private string $serverVersion = 'PostgreSQL 16.1';

    public function setQueryResult(string $table, array $result): void
    {
        $this->queryResults[$table] = $result;
        unset($this->queryExceptions[$table]);
    }

    public function setQueryThrows(string $table, \PDOException $e): void
    {
        $this->queryExceptions[$table] = $e;
        unset($this->queryResults[$table]);
    }

    /**
     * Pop and return the stored query exception for a table.
     *
     * Returns null if no exception was stored for this table.
     */
    public function popQueryException(string $table): ?\PDOException
    {
        $exception = $this->queryExceptions[$table] ?? null;
        unset($this->queryExceptions[$table]);
        return $exception;
    }

    public function setServerVersion(string $version): void
    {
        $this->serverVersion = $version;
    }

    /** @return list<string> */
    public function tables(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> */
    public function rows(string $table, int $limit = 100): array
    {
        return [];
    }

    /** @return list<array<string, mixed>>|null */
    public function query(string $sql): array|null
    {
        $table = $this->extractTableFromQuery($sql);

        if (isset($this->queryExceptions[$table])) {
            throw $this->queryExceptions[$table];
        }

        $rows = $this->queryResults[$table] ?? [];

        // Filter by WHERE name = 'value' conditions if present
        if (preg_match("/WHERE\s+(\w+)\s*=\s*'([^']+)'/i", $sql, $matches)) {
            $column = $matches[1];
            $value = $matches[2];
            $rows = array_values(array_filter(
                $rows,
                fn(array $row) => isset($row[$column]) && (string) $row[$column] === $value,
            ));
        }

        return $rows;
    }

    public function lastInsertId(): string|int
    {
        return '0';
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function exec(string $sql): int
    {
        return 0;
    }

    public function close(): void
    {
    }

    public function serverVersion(): string
    {
        return $this->serverVersion;
    }

    public function driverName(): string
    {
        return 'pgsql';
    }

    public function ping(): bool
    {
        return true;
    }

    /** @return list<string> */
    public function databases(): array
    {
        return [];
    }

    public function prepare(string $sql): ?PreparedStatementInterface
    {
        $table = $this->extractTableFromQuery($sql);
        $results = $this->queryResults[$table] ?? [];

        // Always return a statement; exception will be thrown at execute() time if set
        return new FakePostgresStatement($sql, $table, $results, $this);
    }

    /**
     * Extract the table name from a SQL query for routing.
     */
    private function extractTableFromQuery(string $sql): string
    {
        $sql = trim($sql);

        // pg_stat_database query
        if (str_contains($sql, 'pg_stat_database')) {
            return 'pg_stat_database';
        }

        // pg_settings query
        if (str_contains($sql, 'pg_settings')) {
            return 'pg_settings';
        }

        // pg_stat_activity query
        if (str_contains($sql, 'pg_stat_activity')) {
            return 'pg_stat_activity';
        }

        // current_database() query
        if (str_contains($sql, 'current_database')) {
            return 'current_database';
        }

        return 'unknown';
    }

    public function dsn(): string { return ''; }
    public function username(): string { return ''; }
}

/**
 * Fake PostgreSQL statement for testing.
 */
final class FakePostgresStatement implements PreparedStatementInterface
{
    /** @var list<array<string, mixed>> */
    private array $results;
    private bool $executed = false;

    public function __construct(
        private readonly string $sql,
        private readonly string $table,
        array $results,
        private readonly FakePostgresDatabase $db,
    ) {
        $this->results = $results;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->executed) {
            return false;
        }

        // Throw stored exception if one was set for this table
        $exception = $this->db->popQueryException($this->table);
        if ($exception !== null) {
            throw $exception;
        }

        $this->executed = true;
        return true;
    }

    public function fetch(): array|false
    {
        if (!$this->executed) {
            return false;
        }
        return $this->results[0] ?? false;
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(): array
    {
        if (!$this->executed) {
            return [];
        }
        return $this->results;
    }

    public function rowCount(): int
    {
        return count($this->results);
    }

    public function closeCursor(): bool
    {
        return true;
    }
}
