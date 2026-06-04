<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\PreparedStatementInterface;

/**
 * Fake DatabaseInterface for testing without a real database.
 */
final class FakeDatabase implements DatabaseInterface
{
    /** @var list<array<string, mixed>> */
    private array $queryResult = [];

    private ?\PDOException $queryException = null;
    private string $serverVersion = 'MySQL version 8.0.33';

    /** @var list<array{sql: string, values: array}> */
    private array $executions = [];

    public function setQueryResult(array $result): void
    {
        $this->queryResult = $result;
        $this->queryException = null;
    }

    public function setQueryThrows(\PDOException $e): void
    {
        $this->queryException = $e;
    }

    /**
     * Pop and return the stored query exception.
     *
     * Returns null if no exception was stored.
     */
    public function popQueryException(): ?\PDOException
    {
        $exception = $this->queryException;
        $this->queryException = null;
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
        if ($this->queryException !== null) {
            throw $this->queryException;
        }
        return $this->queryResult;
    }

    public function lastInsertId(): string|int
    {
        return 0;
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
        return 'mysql';
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

    /**
     * Create a prepared statement.
     *
     * @return PreparedStatementInterface|null
     */
    public function prepare(string $sql): ?PreparedStatementInterface
    {
        // Always return a statement; any stored exception will be thrown at execute() time
        return new FakeStatement($sql, $this);
    }

    /**
     * Record an execution for verification.
     */
    public function recordExecution(string $sql, array $values): void
    {
        $this->executions[] = ['sql' => $sql, 'values' => $values];
    }

    /**
     * Get recorded executions.
     *
     * @return list<array{sql: string, values: array}>
     */
    public function getExecutions(): array
    {
        return $this->executions;
    }

    /**
     * Clear recorded executions.
     */
    public function clearExecutions(): void
    {
        $this->executions = [];
    }

    public function dsn(): string { return ''; }
    public function username(): string { return ''; }
}

/**
 * Fake PDOStatement for testing prepared statements.
 */
final class FakeStatement implements PreparedStatementInterface
{
    /** @var list<array<string, mixed>> */
    private array $results = [];

    private bool $closed = false;

    public function __construct(
        private readonly string $sql,
        private readonly FakeDatabase $db,
    ) {}

    /**
     * Execute the prepared statement.
     *
     * @param list<mixed>|null $params
     * @throws \PDOException if a query exception was set on the database
     */
    public function execute(?array $params = null): bool
    {
        if ($this->closed) {
            return false;
        }

        // Throw the stored exception if one was set (simulates query failure)
        $exception = $this->db->popQueryException();
        if ($exception !== null) {
            throw $exception;
        }

        // Record this execution in the database
        $this->db->recordExecution($this->sql, $params ?? []);

        return true;
    }

    /**
     * Fetch a single row.
     *
     * @return array<string, mixed>|false
     */
    public function fetch(): array|false
    {
        if ($this->closed) {
            return false;
        }
        return $this->results[0] ?? false;
    }

    /**
     * Fetch all results.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(): array
    {
        if ($this->closed) {
            return [];
        }
        return $this->results;
    }

    /**
     * Get row count.
     */
    public function rowCount(): int
    {
        return count($this->results);
    }

    /**
     * Close the cursor.
     */
    public function closeCursor(): bool
    {
        $this->closed = true;
        return true;
    }
}
