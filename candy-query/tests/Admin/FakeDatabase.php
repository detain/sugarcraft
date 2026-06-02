<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use SugarCraft\Query\Db\DatabaseInterface;

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
        $this->queryResult = [];
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

    /** @return list<array<string, mixed>> */
    public function query(string $sql): array
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
     * @return FakeStatement|false
     */
    public function prepare(string $sql): FakeStatement|false
    {
        if ($this->queryException !== null) {
            return false;
        }
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
}

/**
 * Fake PDOStatement for testing prepared statements.
 */
final class FakeStatement
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
     * @param list<mixed> $values
     */
    public function execute(array $values = []): bool
    {
        if ($this->closed) {
            return false;
        }

        // Record this execution in the database
        $this->db->recordExecution($this->sql, $values);

        return true;
    }

    /**
     * Close the cursor.
     */
    public function closeCursor(): void
    {
        $this->closed = true;
    }

    /**
     * Fetch results.
     *
     * @return list<array<string, mixed>>|false
     */
    public function fetchAll(int $mode = \PDO::FETCH_ASSOC): array|false
    {
        if ($this->closed) {
            return false;
        }
        return $this->results;
    }
}
