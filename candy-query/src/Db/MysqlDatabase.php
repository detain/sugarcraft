<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

use SugarCraft\Query\Admin\Sampler;
use SugarCraft\Query\Admin\Resilience\ReconnectException;
use SugarCraft\Query\Admin\Resilience\ReconnectManager;

/**
 * MySQL implementation of DatabaseInterface using PDO.
 *
 * Mirrors charmbracelet/lazysql MySQL backend
 */
final class MysqlDatabase implements DatabaseInterface
{
    private ?\PDO $pdo = null;
    private ?float $lastUptime = null;
    private ?ConnectionConfig $connectionConfig = null;
    private ?ReconnectManager $reconnectManager = null;
    private ?Sampler $sampler = null;

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Connect to a MySQL database using connection configuration.
     */
    public static function connect(ConnectionConfig $config): self
    {
        if ($config->driver !== 'mysql') {
            throw new \InvalidArgumentException(
                'Cannot connect to non-MySQL driver using MySQL connector',
            );
        }

        $pdo = new \PDO($config->dsn, $config->user, $config->pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        $instance = new self($pdo);
        $instance->connectionConfig = $config;

        return $instance;
    }

    /** @return list<string> */
    public function tables(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $rows = $this->pdo->query(
            "SELECT table_name FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() "
            . "AND table_type IN ('BASE TABLE', 'VIEW') "
            . "ORDER BY table_name",
        );

        if ($rows === false) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            // information_schema columns are uppercase on MySQL; normalize
            $row = array_change_key_case($row, CASE_LOWER);
            if (isset($row['table_name'])) {
                $out[] = (string) $row['table_name'];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $table, int $limit = 100): array
    {
        if ($this->pdo === null) {
            return [];
        }

        // Safe: backtick identifiers are properly escaped via placeholder
        $sql = sprintf(
            'SELECT * FROM `%s` LIMIT %d',
            str_replace('`', '``', $table),
            $limit,
        );
        $stmt = $this->pdo->query($sql);
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>>|null */
    public function query(string $sql): array|null
    {
        if ($this->pdo === null) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();

            if ($stmt->columnCount() > 0) {
                return $stmt->fetchAll();
            }

            return [['affected' => $stmt->rowCount()]];
        } catch (\PDOException $e) {
            if ($this->reconnectManager !== null && $this->reconnectManager->shouldReconnect($e)) {
                $this->pdo = null;
                $reconnected = $this->reconnectManager->attemptReconnect(
                    fn () => $this->reconnect(),
                );
                if ($reconnected) {
                    $this->reconnectManager->setConnectionConfig($this->connectionConfig);
                    // Reset sampler to clear state after server restart
                    $this->sampler?->resetAll();
                    // Track uptime to detect future restarts
                    $this->trackUptimeAfterReconnect();
                    return null;
                }
                throw new ReconnectException(
                    'Failed to reconnect after connection error: ' . $e->getMessage(),
                    $e,
                );
            }
            throw $e;
        }
    }

    public function lastInsertId(): string|int
    {
        if ($this->pdo === null) {
            return 0;
        }

        return $this->pdo->lastInsertId();
    }

    public function quote(string $value): string
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Cannot quote without connection');
        }

        return $this->pdo->quote($value);
    }

    public function exec(string $sql): int
    {
        if ($this->pdo === null) {
            return 0;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function close(): void
    {
        $this->pdo = null;
        $this->connectionConfig = null;
    }

    public function serverVersion(): string
    {
        if ($this->pdo === null) {
            return 'MySQL version unknown';
        }

        $result = $this->pdo->query('SELECT VERSION() as ver');
        if ($result === false) {
            return 'MySQL version unknown';
        }

        $row = $result->fetch();
        $version = $row['ver'] ?? 'unknown';

        return 'MySQL version ' . $version;
    }

    public function driverName(): string
    {
        return 'mysql';
    }

    public function ping(): bool
    {
        if ($this->pdo === null) {
            return false;
        }

        try {
            $result = $this->pdo->query('SELECT 1');
            return $result !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @return list<string> */
    public function databases(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $rows = $this->pdo->query(
            "SELECT schema_name FROM information_schema.schemata "
            . "WHERE schema_name NOT IN ('information_schema','mysql','performance_schema','sys') "
            . "ORDER BY schema_name",
        );

        if ($rows === false) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['schema_name'])) {
                $out[] = (string) $row['schema_name'];
            }
        }
        return $out;
    }

    public function prepare(string $sql): mixed
    {
        if ($this->pdo === null) {
            return false;
        }

        return $this->pdo->prepare($sql);
    }

    /**
     * Get the last known server uptime.
     *
     * Returns null if uptime has not been tracked yet.
     */
    public function lastUptime(): ?float
    {
        return $this->lastUptime;
    }

    /**
     * Update the tracked server uptime.
     *
     * Called after fetching SHOW GLOBAL STATUS to detect server restarts.
     */
    public function trackUptime(?float $uptime): void
    {
        $this->lastUptime = $uptime;
    }

    /**
     * Inject a ReconnectManager for connection error handling.
     */
    public function setReconnectManager(ReconnectManager $reconnectManager): void
    {
        $this->reconnectManager = $reconnectManager;
    }

    /**
     * Inject a Sampler for reset signaling after server restarts.
     */
    public function setSampler(Sampler $sampler): void
    {
        $this->sampler = $sampler;
    }

    /**
     * Attempt to reconnect using the stored connection config.
     *
     * @return DatabaseInterface|false Returns a new connection or false on failure
     */
    private function reconnect(): DatabaseInterface|false
    {
        if ($this->connectionConfig === null) {
            return false;
        }

        try {
            $pdo = new \PDO(
                $this->connectionConfig->dsn,
                $this->connectionConfig->user,
                $this->connectionConfig->pass,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ],
            );
            $this->pdo = $pdo;
            return $this;
        } catch (\PDOException) {
            $this->pdo = null;
            return false;
        }
    }

    /**
     * Track uptime after a successful reconnect to detect future restarts.
     */
    private function trackUptimeAfterReconnect(): void
    {
        if ($this->pdo === null) {
            return;
        }

        try {
            $result = $this->pdo->query('SHOW GLOBAL STATUS LIKE "Uptime"');
            if ($result !== false) {
                $row = $result->fetch();
                if ($row !== false && isset($row['Value'])) {
                    $uptime = (float) $row['Value'];
                    $this->lastUptime = $uptime;
                    $this->sampler?->registerUptime($uptime);
                }
            }
        } catch (\PDOException) {
            // Silently ignore - uptime tracking is best-effort
        }
    }

    public function dsn(): string
    {
        return $this->connectionConfig?->dsn ?? '';
    }

    public function username(): string
    {
        return $this->connectionConfig?->user ?? '';
    }

    public function password(): string
    {
        return $this->connectionConfig?->pass ?? '';
    }
}
