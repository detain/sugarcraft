<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Placeholder context for unsupported database flavors (e.g., SQLite).
 * Returns empty data for all queries to avoid render errors.
 */
final class EmptyServerContext implements ServerContextInterface
{
    public function connection(): \SugarCraft\Query\Db\DatabaseInterface
    {
        throw new \RuntimeException('Not supported');
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

    public function version(): \SugarCraft\Query\Db\Version
    {
        // EmptyServerContext is used for unsupported flavors like SQLite.
        // Return a zero version so pages like DashboardPage can initialize
        // without throwing in tests or when SQLite is used. Pages that need
        // real version data should check flavor() first.
        return \SugarCraft\Query\Db\Version::parse('');
    }

    public function flavor(): \SugarCraft\Query\Db\Flavor
    {
        // EmptyServerContext is used for unsupported flavors like SQLite.
        // Return Sqlite as the default so pages like DashboardPage can
        // initialize without throwing in tests or when SQLite is used.
        return \SugarCraft\Query\Db\Flavor::Sqlite;
    }

    public function versionString(): string
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
