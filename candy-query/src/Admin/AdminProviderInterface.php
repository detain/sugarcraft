<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use SugarCraft\Query\Db\Flavor;

/**
 * Definitive interface for database-agnostic admin data access.
 *
 * Abstracts MySQL SHOW GLOBAL STATUS/VARIABLES, PostgreSQL pg_stat_database,
 * pg_settings, and pg_stat_activity into a uniform API so the admin UI
 * (Dashboard, Connections, Variables, etc.) works with any supported flavor
 * without knowing which driver is underneath.
 *
 * Implementations are tied to their flavor — MySQL provider assumes MySQL,
 * Postgres provider assumes Postgres. The application wires the correct
 * provider at startup based on the detected Flavor.
 *
 * @see MysqlAdminProvider for MySQL implementation
 * @see PostgresAdminProvider for PostgreSQL stub
 */
interface AdminProviderInterface
{
    /**
     * Which database flavor this provider targets.
     */
    public function flavor(): Flavor;

    /**
     * All status variables (equivalent to SHOW GLOBAL STATUS).
     *
     * Returns key=>value pairs where keys are status variable names
     * and values are their string representation.
     *
     * @return array<string, string>
     */
    public function fetchStatusVariables(): array;

    /**
     * All server variables (equivalent to SHOW GLOBAL VARIABLES).
     *
     * @return array<string, string>
     */
    public function fetchServerVariables(): array;

    /**
     * Active server processes/connections.
     *
     * Maps to pg_stat_activity on PostgreSQL and SHOW FULL PROCESSLIST
     * (via performance_schema) on MySQL.
     *
     * @return list<array{
     *     processId: int,
     *     user: string,
     *     host: string,
     *     database: string,
     *     command: string,
     *     time: int,
     *     state: ?string,
     *     info: ?string,
     *     connectionAttr: array<string, string>
     * }>
     */
    public function fetchProcesslist(): array;

    /**
     * The server's max_connections value (or equivalent).
     */
    public function maxConnections(): int;

    /**
     * Unix timestamp (float) when statusVariables was last fetched.
     */
    public function statusVariablesTs(): float;

    /**
     * True when the server has restarted or variables were reset since last poll.
     *
     * On MySQL this is detected via Uptime going backwards.
     * On PostgreSQL restart detection is not yet implemented — always returns false.
     */
    public function wasReset(): bool;

    /**
     * Force a refresh of all cached values.
     *
     * Clears any internal caches so the next fetch re-queries the server.
     */
    public function refresh(): void;
}
