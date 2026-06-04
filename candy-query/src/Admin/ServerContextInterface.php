<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Read-only context for a database server's runtime state.
 *
 * Caches server variables, status variables with timestamps, plugin list,
 * and parsed version/flavor information. Read by all admin pages.
 *
 * @see Mirrors charmbracelet/lazysql ServerContext
 */
interface ServerContextInterface
{
    /**
     * Get the database connection this context is bound to.
     */
    public function connection(): \SugarCraft\Query\Db\DatabaseInterface;

    /**
     * All SHOW GLOBAL VARIABLES as key => value.
     *
     * @return array<string, string>
     */
    public function serverVariables(): array;

    /**
     * All SHOW GLOBAL STATUS as key => value.
     *
     * @return array<string, string>
     */
    public function statusVariables(): array;

    /**
     * Unix timestamp (float) when statusVariables was last fetched.
     *
     * @return float
     */
    public function statusVariablesTs(): float;

    /**
     * All SHOW PLUGINS as list of row arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function plugins(): array;

    /**
     * Parsed server version.
     */
    public function version(): \SugarCraft\Query\Db\Version;

    /**
     * Detected database flavor.
     *
     * @return \SugarCraft\Query\Db\Flavor
     */
    public function flavor(): \SugarCraft\Query\Db\Flavor;

    /**
     * Raw version string as returned by the server.
     */
    public function versionString(): string;

    /**
     * Get the database password for async connection creation.
     *
     * @return string Password (may be empty for SQLite)
     */
    public function password(): string;

    /**
     * True when SHOW GLOBAL STATUS returned different Uptime than last poll
     * (server was restarted or variables were reset).
     */
    public function wasReset(): bool;

    /**
     * Force a refresh of all cached values.
     */
    public function refresh(): void;
}