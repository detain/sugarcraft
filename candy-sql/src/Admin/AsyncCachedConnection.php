<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\PreparedStatementInterface;
use SugarCraft\Sql\Admin\Resilience\StatementTimeout;

/**
 * A read-through {@see DatabaseInterface} that serves query() results from the
 * async {@see AdminQueryCache} instead of hitting the database synchronously.
 *
 * Admin providers (process list, replica status, sys reports, availability)
 * call connection()->query() during view(). Routing those through this wrapper
 * means a cache hit returns instantly and a miss is recorded as "pending" and
 * returns []  — the slow query is then run on the React event loop by App's
 * admin tick. The net effect: the render hot path does no unbounded database
 * I/O, so the event loop never freezes on a slow sys query.
 *
 * Every non-query method delegates to the real connection. Honest footnote
 * (SQL-C11): those delegations ARE synchronous — action paths (KILL, the GTID
 * toggle, detail-tab reads) call exec()/prepare()/rows() straight through.
 * They are bounded now, not unbounded: each sync leg runs inside the shared
 * {@see StatementTimeout} wall-clock cap, and the transport itself dials with
 * driver network timeouts, so a stall costs at most the budget and surfaces a
 * named error — the freeze this class once silently promised away.
 */
final class AsyncCachedConnection implements DatabaseInterface
{
    private StatementTimeout $guard;

    public function __construct(
        private readonly DatabaseInterface $inner,
        private readonly ?AdminQueryCache $cache = null,
    ) {
        $this->guard = new StatementTimeout();
    }

    /**
     * Serve from the async cache; a miss is queued for background fetch.
     *
     * @return list<array<string,mixed>>
     */
    public function query(string $sql): array
    {
        return $this->cache()->lookup($sql) ?? [];
    }

    /**
     * Resolved lazily (not in the constructor) so the process-global default
     * stays live across AdminQueryCache::reset() in tests.
     */
    private function cache(): AdminQueryCache
    {
        return $this->cache ?? AdminQueryCache::instance();
    }

    public function tables(): array
    {
        return $this->guard->run(fn (): array => $this->inner->tables());
    }

    public function rows(string $table, int $limit = 100, int $offset = 0): array
    {
        return $this->guard->run(fn (): array => $this->inner->rows($table, $limit, $offset));
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
        // KILL/GTID-style write-through: bounded sync, never a frozen loop.
        return $this->guard->run(fn (): int => $this->inner->exec($sql));
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
        return $this->guard->run(fn (): bool => $this->inner->ping());
    }

    public function databases(): array
    {
        return $this->guard->run(fn (): array => $this->inner->databases());
    }

    public function prepare(string $sql): ?PreparedStatementInterface
    {
        // The prepare round-trip is the network leg here; the statement's
        // own execute/fetch legs ride the transport-level timeouts.
        return $this->guard->run(fn (): ?PreparedStatementInterface => $this->inner->prepare($sql));
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
