<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Providers;

use SugarCraft\Query\Admin\AdminProviderInterface;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;

/**
 * MySQL implementation of AdminProviderInterface wrapping ServerContext.
 *
 * Bridges ServerContext's MySQL-specific methods to the flavor-agnostic
 * AdminProviderInterface. ServerContext already handles SHOW GLOBAL STATUS,
 * SHOW GLOBAL VARIABLES, SHOW PLUGINS, and reset detection — this adapter
 * adds processlist fetching and normalizes naming.
 *
 * @see AdminProviderInterface
 * @see ServerContext
 */
final class MysqlAdminProvider implements AdminProviderInterface
{
    public function __construct(
        private readonly ServerContextInterface $context,
    ) {}

    /**
     * Create a new instance from an existing ServerContext.
     */
    public static function new(ServerContextInterface $context): self
    {
        return new self($context);
    }

    public function flavor(): Flavor
    {
        return $this->context->flavor();
    }

    /** @return array<string, string> */
    public function fetchStatusVariables(): array
    {
        return $this->context->statusVariables();
    }

    /** @return array<string, string> */
    public function fetchServerVariables(): array
    {
        return $this->context->serverVariables();
    }

    /**
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
        $psEnabled = $this->context->serverVariables()['performance_schema'] ?? 'OFF';

        if (strtoupper($psEnabled) === 'ON') {
            return $this->fetchProcesslistFromPs();
        }

        return $this->fetchProcesslistFromShow();
    }

    /**
     * Fetches processlist from Performance Schema when available.
     *
     * Mirrors mysql_workbench_dash.md §5.5 PS path:
     *   SELECT <cols> FROM performance_schema.threads t
     *     LEFT OUTER JOIN performance_schema.session_connect_attrs a
     *       ON t.processlist_id = a.processlist_id
     *       AND (a.attr_name IS NULL OR a.attr_name = 'program_name')
     *     WHERE t.TYPE <> 'BACKGROUND'
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
    private function fetchProcesslistFromPs(): array
    {
        try {
            $sql = <<<'SQL'
                SELECT
                    t.PROCESSLIST_ID AS Id,
                    t.PROCESSLIST_USER AS User,
                    t.PROCESSLIST_HOST AS Host,
                    t.PROCESSLIST_DB AS db,
                    t.PROCESSLIST_COMMAND AS Command,
                    t.PROCESSLIST_TIME AS Time,
                    t.PROCESSLIST_STATE AS State,
                    LEFT(t.PROCESSLIST_INFO, 255) AS Info,
                    a.ATTR_NAME AS attr_name,
                    a.ATTR_VALUE AS attr_value
                FROM performance_schema.threads t
                LEFT OUTER JOIN performance_schema.session_connect_attrs a
                    ON t.PROCESSLIST_ID = a.PROCESSLIST_ID
                    AND (a.ATTR_NAME IS NULL OR a.ATTR_NAME = 'program_name')
                WHERE t.TYPE <> 'BACKGROUND'
                SQL;

            $rows = $this->context->connection()->query($sql);

            // Group connection attributes by processlist row
            $grouped = [];
            foreach ($rows as $row) {
                $id = (int) ($row['Id'] ?? 0);
                if (!isset($grouped[$id])) {
                    $grouped[$id] = [
                        'processId' => $id,
                        'user' => (string) ($row['User'] ?? ''),
                        'host' => (string) ($row['Host'] ?? ''),
                        'database' => (string) ($row['db'] ?? ''),
                        'command' => (string) ($row['Command'] ?? ''),
                        'time' => (int) ($row['Time'] ?? 0),
                        'state' => ($row['State'] ?? null) !== null ? (string) $row['State'] : null,
                        'info' => ($row['Info'] ?? null) !== null ? (string) $row['Info'] : null,
                        'connectionAttr' => [],
                    ];
                }
                // Collect connection attributes (prefer non-null attr_name values)
                if (($row['attr_name'] ?? null) !== null && ($row['attr_value'] ?? null) !== null) {
                    $grouped[$id]['connectionAttr'][(string) $row['attr_name']] = (string) $row['attr_value'];
                }
            }

            return array_values($grouped);
        } catch (\PDOException) {
            // Fall back to SHOW if PS query fails
            return $this->fetchProcesslistFromShow();
        }
    }

    /**
     * Fetches processlist using SHOW FULL PROCESSLIST (fallback when PS is off).
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
    private function fetchProcesslistFromShow(): array
    {
        try {
            $rows = $this->context->connection()->query('SHOW FULL PROCESSLIST');
            $results = [];
            foreach ($rows as $row) {
                $results[] = [
                    'processId' => (int) ($row['Id'] ?? 0),
                    'user' => (string) ($row['User'] ?? ''),
                    'host' => (string) ($row['Host'] ?? ''),
                    'database' => (string) ($row['db'] ?? ''),
                    'command' => (string) ($row['Command'] ?? ''),
                    'time' => (int) ($row['Time'] ?? 0),
                    'state' => ($row['State'] ?? null) !== null ? (string) $row['State'] : null,
                    'info' => ($row['Info'] ?? null) !== null ? (string) $row['Info'] : null,
                    'connectionAttr' => [],
                ];
            }
            return $results;
        } catch (\PDOException) {
            return [];
        }
    }

    public function maxConnections(): int
    {
        $vars = $this->context->serverVariables();
        return (int) ($vars['max_connections'] ?? 151);
    }

    public function statusVariablesTs(): float
    {
        return $this->context->statusVariablesTs();
    }

    public function wasReset(): bool
    {
        return $this->context->wasReset();
    }

    public function refresh(): void
    {
        $this->context->refresh();
    }
}
