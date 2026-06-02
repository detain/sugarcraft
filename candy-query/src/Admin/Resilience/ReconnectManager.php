<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Resilience;

use SugarCraft\Query\Db\ConnectionConfig;
use SugarCraft\Query\Db\DatabaseInterface;

/**
 * Manages reconnection logic for MySQL connection errors.
 *
 * Detects error codes 2002 (Can't connect to local MySQL server),
 * 2003 (Can't connect to MySQL server), and 2013 (Lost connection
 * during query). Attempts reconnection and lets caller retry.
 *
 * @see Mirrors charmbracelet/lazysql reconnect logic
 */
final class ReconnectManager
{
    /** @var array<int, string> MySQL error codes that indicate connection issues */
    private const RECONNECT_CODES = [
        2002 => 'Can\'t connect to local MySQL server',
        2003 => 'Can\'t connect to MySQL server',
        2013 => 'Lost connection to MySQL server during query',
    ];

    private ?ConnectionConfig $lastConfig = null;

    /**
     * Check if the given PDOException indicates a reconnectable error.
     */
    public function shouldReconnect(\PDOException $e): bool
    {
        $code = $e->getCode();
        if ($code === 0) {
            $code = $this->extractMysqlCode($e->getMessage());
        }

        return is_int($code) && isset(self::RECONNECT_CODES[$code]);
    }

    /**
     * Attempt to reconnect using the last known connection config.
     *
     * @param callable(): (DatabaseInterface|false) $connect Factory that returns a new connection or false on failure
     * @return bool True if reconnect succeeded, false otherwise
     * @throws ReconnectException If reconnect fails with details of original error
     */
    public function attemptReconnect(callable $connect): bool
    {
        $connection = $connect();

        if ($connection !== false && $connection->ping()) {
            return true;
        }

        return false;
    }

    /**
     * Remember the connection config for reconnection attempts.
     */
    public function setConnectionConfig(ConnectionConfig $config): void
    {
        $this->lastConfig = $config;
    }

    /**
     * Get the last known connection config.
     */
    public function lastConnectionConfig(): ?ConnectionConfig
    {
        return $this->lastConfig;
    }

    /**
     * Extract MySQL error code from an error message.
     *
     * MySQL error messages typically look like:
     * "SQLSTATE[HY000] [2002] Can't connect to local MySQL server"
     */
    private function extractMysqlCode(string $message): int
    {
        if (preg_match('/\[(\d+)\]/', $message, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }
}
