<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Providers;

use SugarCraft\Query\Admin\AdminProviderInterface;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;

/**
 * PostgreSQL stub implementation of AdminProviderInterface.
 *
 * Provides placeholder data so the admin UI remains functional when
 * connected to PostgreSQL. The processlist, status variables, and
 * server variables map PostgreSQL's system catalogs to the AdminProvider
 * interface schema.
 *
 * Full PostgreSQL support requires:
 * - pg_stat_activity GRANT for processlist
 * - pg_stat_database mapping for status counters
 * - pg_settings for server variables
 *
 * @see AdminProviderInterface
 */
final class PostgresAdminProvider implements AdminProviderInterface
{
    private ?array $statusVariablesCache = null;
    private ?float $statusVariablesTsCache = null;
    private ?array $serverVariablesCache = null;
    private ?int $maxConnectionsCache = null;
    private bool $wasResetCache = false;

    public function __construct(
        private readonly DatabaseInterface $connection,
    ) {}

    /**
     * Create a new instance from a PostgreSQL database connection.
     */
    public static function new(DatabaseInterface $connection): self
    {
        return new self($connection);
    }

    public function flavor(): Flavor
    {
        return Flavor::Postgres;
    }

    /**
     * Fetch pg_stat_database metrics for the current database as "status variables".
     *
     * Maps numbackends, xact_commit, xact_rollback, blks_read, blks_hit,
     * tup_returned, tup_fetched, tup_inserted, tup_updated, tup_deleted,
     * conflicts, temp_files, temp_bytes, deadlocks to the status variable schema.
     */
    public function fetchStatusVariables(): array
    {
        if ($this->statusVariablesCache !== null) {
            return $this->statusVariablesCache;
        }

        $this->statusVariablesTsCache = microtime(true);

        try {
            $currentDb = $this->getCurrentDatabaseName();
            if ($currentDb === null) {
                $this->statusVariablesCache = [];
                return [];
            }

            $sql = <<<SQL
                SELECT
                    numbackends,
                    xact_commit,
                    xact_rollback,
                    blks_read,
                    blks_hit,
                    tup_returned,
                    tup_fetched,
                    tup_inserted,
                    tup_updated,
                    tup_deleted,
                    conflicts,
                    temp_files,
                    temp_bytes,
                    deadlocks,
                    stats_reset
                FROM pg_stat_database
                WHERE datname = :datname
            SQL;

            $stmt = $this->connection->prepare($sql);
            if ($stmt === false) {
                $this->statusVariablesCache = [];
                return [];
            }

            if (!$stmt->execute(['datname' => $currentDb])) {
                $this->statusVariablesCache = [];
                return [];
            }
            $row = $stmt->fetch();

            if ($row === false) {
                $this->statusVariablesCache = [];
                return [];
            }

            $this->statusVariablesCache = [
                'pg_stat_database.numbackends' => (string) ($row['numbackends'] ?? 0),
                'pg_stat_database.xact_commit' => (string) ($row['xact_commit'] ?? 0),
                'pg_stat_database.xact_rollback' => (string) ($row['xact_rollback'] ?? 0),
                'pg_stat_database.blks_read' => (string) ($row['blks_read'] ?? 0),
                'pg_stat_database.blks_hit' => (string) ($row['blks_hit'] ?? 0),
                'pg_stat_database.tup_returned' => (string) ($row['tup_returned'] ?? 0),
                'pg_stat_database.tup_fetched' => (string) ($row['tup_fetched'] ?? 0),
                'pg_stat_database.tup_inserted' => (string) ($row['tup_inserted'] ?? 0),
                'pg_stat_database.tup_updated' => (string) ($row['tup_updated'] ?? 0),
                'pg_stat_database.tup_deleted' => (string) ($row['tup_deleted'] ?? 0),
                'pg_stat_database.conflicts' => (string) ($row['conflicts'] ?? 0),
                'pg_stat_database.temp_files' => (string) ($row['temp_files'] ?? 0),
                'pg_stat_database.temp_bytes' => (string) ($row['temp_bytes'] ?? 0),
                'pg_stat_database.deadlocks' => (string) ($row['deadlocks'] ?? 0),
            ];

            return $this->statusVariablesCache;
        } catch (\PDOException) {
            $this->statusVariablesCache = [];
            return [];
        }
    }

    /**
     * Fetch all pg_settings as server variables.
     *
     * @return array<string, string>
     */
    public function fetchServerVariables(): array
    {
        if ($this->serverVariablesCache !== null) {
            return $this->serverVariablesCache;
        }

        try {
            $rows = $this->connection->query('SELECT name, setting FROM pg_settings');
            $out = [];
            foreach ($rows as $row) {
                if (isset($row['name'], $row['setting'])) {
                    $out[(string) $row['name']] = (string) $row['setting'];
                }
            }
            $this->serverVariablesCache = $out;
            return $out;
        } catch (\PDOException) {
            $this->serverVariablesCache = [];
            return [];
        }
    }

    /**
     * Fetch active connections from pg_stat_activity.
     *
     * Returns an empty list if pg_stat_activity is not accessible due to
     * missing GRANT. The notice() method provides the guidance string.
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
    public function fetchProcesslist(): array
    {
        try {
            $currentDb = $this->getCurrentDatabaseName();
            $sql = <<<SQL
                SELECT
                    pid,
                    usename,
                    COALESCE(client_addr::text, '') AS client_addr,
                    datname,
                    state,
                    query_start,
                    query,
                    application_name
                FROM pg_stat_activity
                WHERE datname = :datname
                ORDER BY query_start DESC
            SQL;

            $stmt = $this->connection->prepare($sql);
            if ($stmt === false) {
                return [];
            }

            $stmt->execute(['datname' => $currentDb]);
            $rows = $stmt->fetchAll();

            $results = [];
            foreach ($rows as $row) {
                $queryStart = $row['query_start'] ?? null;
                $secondsAgo = 0;
                if ($queryStart !== null && $queryStart !== false) {
                    try {
                        $diff = (new \DateTime())->getTimestamp() - (new \DateTime($queryStart))->getTimestamp();
                        $secondsAgo = max(0, $diff);
                    } catch (\Exception) {
                        $secondsAgo = 0;
                    }
                }

                $results[] = [
                    'processId' => (int) ($row['pid'] ?? 0),
                    'user' => (string) ($row['usename'] ?? ''),
                    'host' => (string) ($row['client_addr'] ?? ''),
                    'database' => (string) ($row['datname'] ?? ''),
                    'command' => (string) ($row['state'] ?? 'unknown'),
                    'time' => $secondsAgo,
                    'state' => ($row['state'] ?? null) !== null ? (string) $row['state'] : null,
                    'info' => ($row['query'] ?? null) !== null ? (string) $row['query'] : null,
                    'connectionAttr' => [
                        'application_name' => (string) ($row['application_name'] ?? ''),
                    ],
                ];
            }

            return $results;
        } catch (\PDOException) {
            return [];
        }
    }

    public function maxConnections(): int
    {
        if ($this->maxConnectionsCache !== null) {
            return $this->maxConnectionsCache;
        }

        try {
            $rows = $this->connection->query(
                "SELECT setting FROM pg_settings WHERE name = 'max_connections'",
            );

            if (isset($rows[0]['setting'])) {
                $this->maxConnectionsCache = (int) $rows[0]['setting'];
                return $this->maxConnectionsCache;
            }
        } catch (\PDOException) {
        }

        $this->maxConnectionsCache = 100;
        return $this->maxConnectionsCache;
    }

    public function statusVariablesTs(): float
    {
        if ($this->statusVariablesTsCache !== null) {
            return $this->statusVariablesTsCache;
        }

        $this->fetchStatusVariables();
        return $this->statusVariablesTsCache ?? microtime(true);
    }

    public function wasReset(): bool
    {
        // PostgreSQL restart detection not yet implemented
        return false;
    }

    public function refresh(): void
    {
        $this->statusVariablesCache = null;
        $this->statusVariablesTsCache = null;
        $this->serverVariablesCache = null;
        $this->maxConnectionsCache = null;
    }

    /**
     * Get the current database name from the connection.
     */
    private function getCurrentDatabaseName(): string
    {
        try {
            $rows = $this->connection->query('SELECT current_database() AS dbname');
            return (string) ($rows[0]['dbname'] ?? 'postgres');
        } catch (\PDOException) {
            return 'postgres';
        }
    }
}
