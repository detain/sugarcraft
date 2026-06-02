<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Connections;

use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Actions on processlist threads: KILL, KILL QUERY, instrumentation toggle.
 *
 * KILL/KILL QUERY refuse to target background threads (user=NULL/empty)
 * as killing those can destabilize the server. Prepared statement IDs are
 * used to prevent SQL injection.
 *
 * @see Mirrors charmbracelet/lazysql connection actions
 */
final class ConnectionActions
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
     * KILL a thread (disconnect).
     *
     * Refuses to kill background threads (no user or NULL user).
     *
     * @param int|string $threadId Processlist ID
     * @param bool $isBackground True if this is a background thread
     * @return bool True on success, false if refused or error
     */
    public function kill(int|string $threadId, bool $isBackground): bool
    {
        if ($isBackground) {
            return false;
        }
        return $this->executeKill($threadId, '');
    }

    /**
     * KILL QUERY a thread (cancel running statement, keep connection).
     *
     * Refuses to kill query on background threads.
     *
     * @param int|string $threadId Processlist ID
     * @param bool $isBackground True if this is a background thread
     * @return bool True on success, false if refused or error
     */
    public function killQuery(int|string $threadId, bool $isBackground): bool
    {
        if ($isBackground) {
            return false;
        }
        return $this->executeKill($threadId, 'QUERY');
    }

    /**
     * Enable or disable performance_schema instrumentation.
     *
     * Uses UPDATE performance_schema.setup_actors to enable/disable
     * instrumentation for new threads.
     *
     * @param bool $enabled True to enable, false to disable
     * @return bool True on success
     */
    public function setInstrumentation(bool $enabled): bool
    {
        $value = $enabled ? 'YES' : 'NO';
        $sql = "UPDATE performance_schema.setup_actors SET ENABLED = '{$value}' WHERE HOST = '%' AND USER = '%'";

        try {
            $connection = $this->context->connection();
            $connection->exec($sql);
            return true;
        } catch (\PDOException $e) {
            // 1142: UPDATE privilege denied on performance_schema
            // 1146: setup_actors table doesn't exist (old MySQL)
            if ($this->isAclError($e)) {
                return false;
            }
            return false;
        }
    }

    /**
     * Check if instrumentation is currently enabled.
     *
     * @return bool|null True if enabled, false if disabled, null if cannot determine
     */
    public function isInstrumentationEnabled(): ?bool
    {
        $sql = "SELECT ENABLED FROM performance_schema.setup_actors WHERE HOST = '%' AND USER = '%' LIMIT 1";

        try {
            $connection = $this->context->connection();
            $rows = $connection->query($sql);
            if (($rows[0]['ENABLED'] ?? '') === 'YES') {
                return true;
            }
            return false;
        } catch (\PDOException $e) {
            return null;
        }
    }

    /**
     * Execute KILL [QUERY] on a thread ID.
     *
     * @param int|string $threadId Processlist ID
     * @param string $modifier '' for KILL, 'QUERY' for KILL QUERY
     * @return bool True on success
     */
    private function executeKill(int|string $threadId, string $modifier): bool
    {
        $sql = $modifier !== ''
            ? "KILL {$modifier} ?"
            : "KILL ?";

        try {
            $connection = $this->context->connection();
            $stmt = $connection->prepare($sql);
            if ($stmt === false) {
                return false;
            }
            $stmt->execute([(string) $threadId]);
            return true;
        } catch (\PDOException $e) {
            // 2005: Unknown MySQL server host / 2003: Can't connect
            // 1094: Unknown thread ID
            if ($this->isConnectionError($e) || $this->isUnknownThreadError($e)) {
                return false;
            }
            return false;
        }
    }

    private function isAclError(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        return \in_array($code, ['1142', '1146', '1227', '42000'], true)
            || (str_contains(strtolower($e->getMessage()), 'denied')
                && str_contains(strtolower($e->getMessage()), 'performance_schema'));
    }

    private function isConnectionError(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        return \in_array($code, ['2002', '2003', '2013', '08000', '08006'], true)
            || str_contains(strtolower($e->getMessage()), 'lost connection')
            || str_contains(strtolower($e->getMessage()), 'connection refused')
            || str_contains(strtolower($e->getMessage()), "can't connect");
    }

    private function isUnknownThreadError(\PDOException $e): bool
    {
        $code = (string) $e->getCode();
        return $code === '1094' || $code === '45000'
            || str_contains(strtolower($e->getMessage()), 'unknown thread');
    }
}
