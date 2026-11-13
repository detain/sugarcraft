<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

/**
 * Placeholder context for unsupported database flavors (e.g., SQLite).
 * Returns empty data for all queries to avoid render errors.
 *
 * SQL-C01: {@see connection()} no longer throws — pages construct eagerly
 * through it (ReportsPage via adminPage()), and a throw there killed the
 * whole TUI three keys into a default SQLite session. The null-sentinel
 * seam answers empty data exactly like the other accessors here, keeping
 * the class docblock's "avoid render errors" promise true. An injected
 * handle is honoured for tests that need the sentinel to observe calls.
 */
final class EmptyServerContext implements ServerContextInterface
{
    public function __construct(
        private readonly ?\SugarCraft\Sql\Db\DatabaseInterface $db = null,
    ) {
    }

    public function connection(): \SugarCraft\Sql\Db\DatabaseInterface
    {
        return $this->db ?? new \SugarCraft\Sql\Db\NullDatabase();
    }

    /** @return array<string, string> */
    public function serverVariables(): array
    {
        return [];
    }

    /** @return array<string, string> */
    public function statusVariables(): array
    {
        return [];
    }

    public function statusVariablesTs(): float
    {
        return 0.0;
    }

    /** @return list<array<string, mixed>> */
    public function plugins(): array
    {
        return [];
    }

    public function version(): \SugarCraft\Sql\Db\ServerVersion
    {
        // EmptyServerContext is used for unsupported flavors like SQLite.
        // Return a zero version so pages like DashboardPage can initialize
        // without throwing in tests or when SQLite is used. Pages that need
        // real version data should check flavor() first.
        return \SugarCraft\Sql\Db\ServerVersion::parse('');
    }

    public function flavor(): \SugarCraft\Sql\Db\Flavor
    {
        // EmptyServerContext is used for unsupported flavors like SQLite.
        // Return Sqlite as the default so pages like DashboardPage can
        // initialize without throwing in tests or when SQLite is used.
        return \SugarCraft\Sql\Db\Flavor::Sqlite;
    }

    public function versionString(): string
    {
        return '';
    }

    public function password(): string
    {
        return '';
    }

    public function wasReset(): bool
    {
        return false;
    }

    public function refresh(): void
    {
        // No-op
    }
}
