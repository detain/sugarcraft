<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

/**
 * Null-object database that answers every read with empty data.
 *
 * Stands in for a real handle when no server-side admin surface exists
 * (SQLite sessions under EmptyServerContext). Pages and fold arms may
 * dereference {@see connection()} freely; every result is empty rather
 * than a thrown RuntimeException, which is what previously killed the
 * TUI on entry to the Query Stats / Table Stats panes (SQL-C01).
 */
final class NullDatabase implements DatabaseInterface
{
    public function tables(): array
    {
        return [];
    }

    public function rows(string $table, int $limit = 100): array
    {
        return [];
    }

    public function query(string $sql): array|null
    {
        return [];
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
        // No handle to release.
    }

    public function serverVersion(): string
    {
        return '';
    }

    public function driverName(): string
    {
        return 'none';
    }

    public function ping(): bool
    {
        return false;
    }

    public function databases(): array
    {
        return [];
    }

    public function prepare(string $sql): ?PreparedStatementInterface
    {
        return null;
    }

    public function dsn(): string
    {
        return '';
    }

    public function username(): string
    {
        return '';
    }
}
