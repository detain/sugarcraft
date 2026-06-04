<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Connections;

use SugarCraft\Query\Admin\ServerContextInterface;

/**
 * Detail tabs for a processlist thread: Details, Attributes, MDL locks.
 *
 * Details: basic thread info from performance_schema.threads or SHOW PROCESSLIST
 * Attributes: session_connect_attrs join to get client info
 * MDL: metadata lock info from information_schema or performance_schema
 *
 * @see Mirrors charmbracelet/lazysql connection detail tabs
 */
final class ConnectionDetailTabs
{
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
     * Tab: basic thread details for a processlist ID.
     *
     * @return array<string, scalar>|null Thread info or null if not found
     */
    public function getDetails(int|string $processId): ?array
    {
        $sql = <<<'SQL'
SELECT
    PROCESSLIST_ID AS id,
    PROCESSLIST_USER AS user,
    PROCESSLIST_HOST AS host,
    PROCESSLIST_DB AS db,
    PROCESSLIST_COMMAND AS command,
    PROCESSLIST_TIME AS time,
    PROCESSLIST_STATE AS state,
    PROCESSLIST_INFO AS info,
    TYPE AS thread_type,
    NAME AS thread_name
FROM performance_schema.threads
WHERE PROCESSLIST_ID = ?
SQL;

        try {
            $connection = $this->context->connection();
            $stmt = $connection->prepare($sql);
            if ($stmt === false) {
                return null;
            }
            $stmt->execute([(string) $processId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $rows[0] ?? null;
        } catch (\PDOException $e) {
            if ($this->isAccessDenied($e)) {
                return null;
            }
            return null;
        }
    }

    /**
     * Tab: session connect attributes for a processlist ID.
     *
     * @return array<string, scalar>|null Attributes or null if not accessible
     */
    public function getAttributes(int|string $processId): ?array
    {
        $sql = <<<'SQL'
SELECT
    t.PROCESSLIST_ID AS id,
    a.ATTR_NAME AS attr_name,
    a.ATTR_VALUE AS attr_value,
    a.ORDINAL_POSITION AS ordinal
FROM performance_schema.threads t
JOIN performance_schema.session_connect_attrs a
    ON t.THREAD_ID = a.THREAD_ID
WHERE t.PROCESSLIST_ID = ?
ORDER BY a.ORDINAL_POSITION
SQL;

        try {
            $connection = $this->context->connection();
            $stmt = $connection->prepare($sql);
            if ($stmt === false) {
                return null;
            }
            $stmt->execute([(string) $processId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            if ($rows === []) {
                return null;
            }

            // Flatten to name => value map
            $attrs = [];
            foreach ($rows as $row) {
                $attrs[(string) $row['attr_name']] = (string) $row['attr_value'];
            }
            return ['id' => $processId, 'attributes' => $attrs];
        } catch (\PDOException $e) {
            if ($this->isAccessDenied($e)) {
                return null;
            }
            return null;
        }
    }

    /**
     * Tab: metadata locks held by a processlist ID.
     *
     * Uses information_schema.metadata_lock_info (MySQL 5.7+) or
     * performance_schema.metadata_locks (MySQL 8.0+).
     *
     * @return list<array<string, scalar>>|null Lock list or null if not accessible
     */
    public function getMdlLocks(int|string $processId): ?array
    {
        $locks = $this->fetchMdlFromPslocks($processId);
        if ($locks !== null) {
            return $locks;
        }

        return $this->fetchMdlFromInfoSchema($processId);
    }

    /**
     * Get thread stack dump via sys.ps_thread_stack().
     *
     * @return string|null Stack trace or null if not accessible
     */
    public function getThreadStack(int|string $processId): ?string
    {
        $sql = "SELECT sys.ps_thread_stack(?, '') AS stack";

        try {
            $connection = $this->context->connection();
            $stmt = $connection->prepare($sql);
            if ($stmt === false) {
                return null;
            }
            $stmt->execute([(string) $processId]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $stack = $rows[0]['stack'] ?? null;
            return \is_string($stack) && $stack !== '' ? $stack : null;
        } catch (\PDOException $e) {
            // sys schema may not exist or user may lack privileges
            return null;
        }
    }

    /**
     * Get EXPLAIN output for a thread's current query.
     *
     * Only executes EXPLAIN on a single, simple SELECT statement. Rejects
     * multi-statement queries and any statement type other than SELECT.
     * Uses a prepared statement to safely run the EXPLAIN.
     *
     * @return list<array<string, mixed>>|null EXPLAIN results or null if unavailable
     */
    public function getExplain(int|string $processId): ?array
    {
        // First get the current query from the thread
        $details = $this->getDetails($processId);
        if ($details === null || ($details['info'] ?? '') === '') {
            return null;
        }

        $query = (string) $details['info'];
        if ($query === '' || stripos($query, 'EXPLAIN') === 0) {
            return null;
        }

        // Guard: only allow single SELECT statements (no multi-statement, no DML/DDL)
        if (!$this->isSafeExplainQuery($query)) {
            return null;
        }

        try {
            $connection = $this->context->connection();
            $stmt = $connection->prepare("EXPLAIN {$query}");
            if ($stmt === false) {
                return null;
            }
            $stmt->execute([]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * Returns true only if the query is a single SELECT safe to EXPLAIN.
     *
     * Rejects multi-statement queries (containing ;) and any statement type
     * other than SELECT (no INSERT/UPDATE/DELETE/DROP/etc).
     */
    private function isSafeExplainQuery(string $query): bool
    {
        $trimmed = \trim($query);

        // Must start with SELECT (with optional leading whitespace)
        if (!preg_match('/^SELECT\s+/i', $trimmed)) {
            return false;
        }

        // No semicolons = no multi-statement
        if (str_contains($trimmed, ';')) {
            return false;
        }

        return true;
    }

    /**
     * @return list<array<string, scalar>>|null
     */
    private function fetchMdlFromPslocks(int|string $processId): ?array
    {
        // metadata_locks.OWNER_THREAD_ID joins to threads.THREAD_ID
        $sql = <<<'SQL'
SELECT
    ml.LOCK_ID AS lock_id,
    ml.LOCK_TYPE AS lock_type,
    ml.LOCK_STATUS AS lock_status,
    ml.LOCK_MODE AS lock_mode,
    ml.OWNER_THREAD_ID AS thread_id,
    t.PROCESSLIST_ID AS processlist_id
FROM performance_schema.metadata_locks ml
JOIN performance_schema.threads t
    ON ml.OWNER_THREAD_ID = t.THREAD_ID
WHERE t.PROCESSLIST_ID = ?
SQL;

        try {
            $connection = $this->context->connection();
            $stmt = $connection->prepare($sql);
            if ($stmt === false) {
                return null;
            }
            $stmt->execute([(string) $processId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            if ($this->isAccessDenied($e)) {
                return null;
            }
            return null;
        }
    }

    /**
     * @return list<array<string, scalar>>|null
     */
    private function fetchMdlFromInfoSchema(int|string $processId): ?array
    {
        // Try MySQL 5.7+ information_schema.metadata_lock_info
        $sql = <<<'SQL'
SELECT
    THREAD_ID AS thread_id,
    LOCK_TYPE AS lock_type,
    LOCK_STATUS AS lock_status,
    COLUMN_NAME AS column_name
FROM information_schema.metadata_lock_info
WHERE THREAD_ID = ?
SQL;

        try {
            $connection = $this->context->connection();
            $stmt = $connection->prepare($sql);
            if ($stmt === false) {
                return null;
            }
            $stmt->execute([(string) $processId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: null;
        } catch (\PDOException $e) {
            return null;
        }
    }

    private function isAccessDenied(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        return \in_array($code, ['1142', '1146', '1227', '42000'], true)
            || (str_contains(strtolower($e->getMessage()), 'denied')
                && str_contains(strtolower($e->getMessage()), 'performance_schema'));
    }
}
