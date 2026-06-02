<?php

declare(strict_types=1);

namespace SugarCraft\Query\Db;

use SugarCraft\Query\Lang;
use Sqlite3;

/**
 * SQLite implementation of DatabaseInterface.
 *
 * Mirrors charmbracelet/lazysql SQLite backend
 */
final class SqliteDatabase implements DatabaseInterface
{
    private ?\PDO $pdo;
    private string $path;

    public function __construct(\PDO $pdo, string $path = ':memory:')
    {
        $this->pdo = $pdo;
        $this->path = $path;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Open a SQLite database file.
     */
    public static function open(string $path): self
    {
        if ($path !== ':memory:' && !is_file($path)) {
            throw new \RuntimeException(Lang::t('database.no_file', ['path' => $path]));
        }
        $pdo = new \PDO('sqlite:' . $path);
        return new self($pdo, $path);
    }

    /** @return list<string> */
    public function tables(): array
    {
        $rows = $this->pdo->query(
            "SELECT name FROM sqlite_master "
            . "WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' "
            . "ORDER BY name",
        );
        if ($rows === false) return [];
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['name'])) {
                $out[] = (string) $row['name'];
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $table, int $limit = 100): array
    {
        $sql = sprintf('SELECT * FROM "%s" LIMIT %d', str_replace('"', '""', $table), $limit);
        $stmt = $this->pdo->query($sql);
        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function query(string $sql): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        if ($stmt->columnCount() > 0) {
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return [['affected' => $stmt->rowCount()]];
    }

    public function lastInsertId(): string|int
    {
        return $this->pdo->lastInsertId();
    }

    public function quote(string $value): string
    {
        return $this->pdo->quote($value);
    }

    public function exec(string $sql): int
    {
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
        return 'SQLite version ' . ($this->pdo->query('SELECT sqlite_version()')?->fetchColumn() ?? 'unknown');
    }

    public function driverName(): string
    {
        return 'sqlite';
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
        if ($this->path === ':memory:') {
            return ['memory'];
        }
        return [basename($this->path)];
    }

    public function prepare(string $sql): mixed
    {
        if ($this->pdo === null) {
            return false;
        }
        return $this->pdo->prepare($sql);
    }

    public function dsn(): string { return 'sqlite:' . $this->path; }
    public function username(): string { return ''; }
    public function password(): string { return ''; }
}
