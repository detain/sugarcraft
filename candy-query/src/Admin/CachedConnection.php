<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use SugarCraft\Query\Db\DatabaseInterface;

/**
 * A read-through {@see DatabaseInterface} that serves query() results from the
 * async {@see AdminQueryCache} instead of hitting the database synchronously.
 *
 * Admin providers (process list, replica status, sys reports, availability)
 * call connection()->query() during view(). Routing those through this wrapper
 * means a cache hit returns instantly and a miss is recorded as "pending" and
 * returns []  — the slow query is then run on the React event loop by App's
 * admin tick. The net effect: no synchronous DB I/O during render, so the
 * event loop never freezes on a slow sys query.
 *
 * Every non-query method delegates to the real connection (these are metadata
 * accessors used outside the render hot-path).
 */
final class CachedConnection implements DatabaseInterface
{
    public function __construct(
        private readonly DatabaseInterface $inner,
    ) {}

    /**
     * Serve from the async cache; a miss is queued for background fetch.
     *
     * @return list<array<string,mixed>>
     */
    public function query(string $sql): array
    {
        return AdminQueryCache::instance()->lookup($sql) ?? [];
    }

    public function tables(): array
    {
        return $this->inner->tables();
    }

    public function rows(string $table, int $limit = 100): array
    {
        return $this->inner->rows($table, $limit);
    }

    public function lastInsertId(): string|int
    {
        return $this->inner->lastInsertId();
    }

    public function quote(string $value): string
    {
        return $this->inner->quote($value);
    }

    public function exec(string $sql): int
    {
        return $this->inner->exec($sql);
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function serverVersion(): string
    {
        return $this->inner->serverVersion();
    }

    public function driverName(): string
    {
        return $this->inner->driverName();
    }

    public function ping(): bool
    {
        return $this->inner->ping();
    }

    public function databases(): array
    {
        return $this->inner->databases();
    }

    public function prepare(string $sql): mixed
    {
        return $this->inner->prepare($sql);
    }

    public function dsn(): string
    {
        return $this->inner->dsn();
    }

    public function username(): string
    {
        return $this->inner->username();
    }
}
