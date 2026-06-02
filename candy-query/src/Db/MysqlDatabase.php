<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

/**
 * MySQL implementation of DatabaseInterface using PDO.
 *
 * Mirrors charmbracelet/lazysql MySQL backend
 */
final class MysqlDatabase implements DatabaseInterface
{
    private ?\PDO $pdo;

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

        return new self($pdo);
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

        // Safe: backtick identifiers are properly escaped via placeholder
        $sql = sprintf(
            'SELECT * FROM `%s` LIMIT %d',
            str_replace('`', '``', $table),
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
}
