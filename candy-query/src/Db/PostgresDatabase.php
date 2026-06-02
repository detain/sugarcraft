<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * PostgreSQL implementation of DatabaseInterface using PDO.
 *
 * Mirrors charmbracelet/lazysql PostgreSQL backend
 */
final class PostgresDatabase implements DatabaseInterface
{
    private ?\PDO $pdo;

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Connect to a PostgreSQL database using connection configuration.
     */
    public static function connect(ConnectionConfig $config): self
    {
        if ($config->driver !== 'pgsql') {
            throw new \InvalidArgumentException(
                'Cannot connect to non-PostgreSQL driver using PostgreSQL connector',
            );
        }

        $pdo = new \PDO($config->dsn, $config->user, $config->pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        return new self($pdo);
    }

    /** @return list<string> */
    public function tables(): array
    {
        if ($this->pdo === null) {
            return [];
        }

        // PostgreSQL uses double-quotes for identifiers
        $rows = $this->pdo->query(
            'SELECT table_name FROM information_schema.tables '
            . 'WHERE table_schema = CURRENT_SCHEMA() '
            . "AND table_type IN ('BASE TABLE', 'VIEW') "
            . 'ORDER BY table_name',
        );

        if ($rows === false) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['table_name'])) {
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

        // PostgreSQL uses double-quotes for identifiers
        $sql = sprintf(
            'SELECT * FROM "%s" LIMIT %d',
            str_replace('"', '""', $table),
            $limit,
        );
        $stmt = $this->pdo->query($sql);
        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function query(string $sql): array
    {
        if ($this->pdo === null) {
            return [];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        if ($stmt->columnCount() > 0) {
            return $stmt->fetchAll();
        }

        return [['affected' => $stmt->rowCount()]];
    }

    public function lastInsertId(): string|int
    {
        if ($this->pdo === null) {
            return 0;
        }

        // PostgreSQL uses sequences for auto-increment, fallback to '0'
        $id = $this->pdo->lastInsertId();
        return $id !== false ? $id : '0';
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
    }

    public function serverVersion(): string
    {
        if ($this->pdo === null) {
            return 'PostgreSQL version unknown';
        }

        $result = $this->pdo->query('SELECT version()');
        if ($result === false) {
            return 'PostgreSQL version unknown';
        }

        $row = $result->fetch();
        $version = $row['version'] ?? 'unknown';

        return 'PostgreSQL ' . $version;
    }

    public function driverName(): string
    {
        return 'pgsql';
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
            "SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname",
        );

        if ($rows === false) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['datname'])) {
                $out[] = (string) $row['datname'];
            }
        }
        return $out;
    }
}
