<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Connections;

use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Fetches server processlist via performance_schema or SHOW FULL PROCESSLIST.
 *
 * Uses PS path (performance_schema.threads + session_connect_attrs join) when
 * @@performance_schema=ON and user has PROCESS_ACL privileges. Falls back to
 * SHOW FULL PROCESSLIST when PS is disabled or inaccessible due to permission
 * errors (1142/1146) or connection errors (2002/2003/2013).
 *
 * @see Mirrors charmbracelet/lazysql processlist
 */
final class ProcesslistProvider
{
    private ?bool $psAvailable = null;

    public function __construct(
        private readonly ServerContextInterface $context,
    ) {}

    /**
     * Create a new instance with default context.
     */
    public static function new(ServerContextInterface $context): self
    {
        return new self($context);
    }

    /**
     * Fetch all processlist rows.
     *
     * @return list<ProcesslistResult>
     */
    public function fetchAll(): array
    {
        if ($this->psAvailable === null) {
            $this->psAvailable = $this->checkPerformanceSchema();
        }

        if ($this->psAvailable === true) {
            $result = $this->fetchViaPS();
            if ($result !== null) {
                return $result;
            }
        }

        return $this->fetchViaShowProcesslist();
    }

    /**
     * Clear cached PS availability and force re-detection on next fetch.
     */
    public function refresh(): self
    {
        $clone = clone $this;
        $clone->psAvailable = null;
        return $clone;
    }

    /**
     * Determine whether performance_schema is accessible.
     */
    private function checkPerformanceSchema(): bool
    {
        try {
            $connection = $this->context->connection();
            $rows = $connection->query('SELECT @@performance_schema AS ps');
            return (int) ($rows[0]['ps'] ?? 0) === 1;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Fetch via performance_schema.threads with session_connect_attrs join.
     *
     * @return list<ProcesslistResult>|null null on error (caller should fallback)
     */
    private function fetchViaPS(): ?array
    {
        $sql = <<<'SQL'
SELECT
    t.PROCESSLIST_ID,
    t.PROCESSLIST_USER,
    t.PROCESSLIST_HOST,
    t.PROCESSLIST_DB,
    t.PROCESSLIST_COMMAND,
    t.PROCESSLIST_TIME,
    t.PROCESSLIST_STATE,
    t.PROCESSLIST_INFO,
    COALESCE(a.ATTR_VALUE, '') AS PROCESSLIST_ATTRS
FROM performance_schema.threads t
LEFT JOIN performance_schema.session_connect_attrs a
    ON t.THREAD_ID = a.THREAD_ID
    AND a.ATTR_NAME = 'program_name'
ORDER BY t.PROCESSLIST_TIME DESC
SQL;

        try {
            $connection = $this->context->connection();
            $rows = $connection->query($sql);
            return $this->mapRows($rows, true);
        } catch (\PDOException $e) {
            // 1142: SELECT privilege denied on performance_schema
            // 1146: Table doesn't exist (old MySQL version without this table)
            if ($this->isProcesslistAccessDenied($e)) {
                return null;
            }
            // Connection errors — treat as inaccessible
            if ($this->isConnectionError($e)) {
                return null;
            }
            // Re-throw unexpected errors so caller knows something went wrong
            throw $e;
        }
    }

    /**
     * Fetch via SHOW FULL PROCESSLIST as fallback.
     *
     * @return list<ProcesslistResult>
     */
    private function fetchViaShowProcesslist(): array
    {
        try {
            $connection = $this->context->connection();
            $rows = $connection->query('SHOW FULL PROCESSLIST');
            return $this->mapRows($rows, false);
        } catch (\PDOException $e) {
            // 1227: Processlist command denied (unprivileged user)
            // Connection errors — degrade to empty list
            if ($this->isProcesslistAccessDenied($e) || $this->isConnectionError($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ProcesslistResult>
     */
    private function mapRows(array $rows, bool $isPS): array
    {
        $results = [];
        foreach ($rows as $row) {
            try {
                if ($isPS) {
                    $results[] = ProcesslistResult::fromPSRow($row);
                } else {
                    $results[] = ProcesslistResult::fromShowProcesslist($row);
                }
            } catch (\Throwable) {
                // Skip malformed rows rather than failing the entire fetch
            }
        }
        return $results;
    }

    /**
     * True when the exception indicates PS/processlist access is denied.
     */
    private function isProcesslistAccessDenied(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        // 1142: SELECT privilege denied
        // 1146: Table doesn't exist
        // 1227: Specific command denied
        return \in_array($code, ['1142', '1146', '1227', '42000'], true)
            || str_contains(strtolower($e->getMessage()), 'denied')
            || str_contains(strtolower($e->getMessage()), 'table') && str_contains(strtolower($e->getMessage()), 'exist');
    }

    /**
     * True when the exception indicates a connection-level error.
     */
    private function isConnectionError(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        // 2002: Connection refused (host not reachable)
        // 2003: Can't connect to MySQL server
        // 2013: Lost connection during query
        return \in_array($code, ['2002', '2003', '2013', '08000', '08006'], true)
            || str_contains(strtolower($e->getMessage()), 'lost connection')
            || str_contains(strtolower($e->getMessage()), 'connection refused')
            || str_contains(strtolower($e->getMessage()), "can't connect");
    }
}
