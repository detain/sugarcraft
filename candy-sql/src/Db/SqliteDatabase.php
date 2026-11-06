<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Db;

use SugarCraft\Sql\Lang;

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
        // The constructor is the single chokepoint for every SQLite handle we
        // wrap: ::open(), ConnectionFactory::createSqlite() and the deprecated
        // Database alias all arrive here, so the client posture below rides
        // every user connection exactly once (re-arming an already-armed PDO
        // is idempotent — both pragmas are per-connection set-and-forget).
        self::armClientPragmas($this->pdo);
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

    /**
     * Per-connection posture for a database this client does NOT own.
     *
     * busy_timeout=5000: pdo_sqlite arms its own build default of 60000 ms
     * on every handle, so an un-armed browse of a file another process holds
     * in a transaction waits out the full minute before failing; five seconds
     * is the house cap — the same figure the history store passes to
     * \SQLite3::busyTimeout — trading a long stale wait for a prompt retry.
     * cache_size=-64000: negative means KiB — a 64,000 KiB (62.5 MiB) page
     * cache for the browse-heavy reader, living only in this connection's
     * memory.
     *
     * Deliberately NOT sent here: journal_mode (WAL is persistent — it is
     * written into the FILE header, and opening someone's database must not
     * rewrite it), synchronous (would silently change the durability of the
     * user's own writes), foreign_keys (turning it ON starts rejecting
     * statements the user already runs; SQLite and the sqlite3 CLI both
     * default it OFF). User databases stay neutral.
     */
    private static function armClientPragmas(\PDO $pdo): void
    {
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA cache_size = -64000');
    }

    /** @return list<string> */
    public function tables(): array
    {
        if ($this->pdo === null) {
            return [];
        }

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
        if ($this->pdo === null) {
            return [];
        }

        $sql = sprintf('SELECT * FROM %s LIMIT %d', Identifier::quote(Flavor::Sqlite, $table), $limit);
        $stmt = $this->pdo->query($sql);
        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>>|null */
    public function query(string $sql): array|null
    {
        if ($this->pdo === null) {
            return null;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        if ($stmt->columnCount() > 0) {
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
        // Post-close contract (D-M32, 2026-11-03): every member access is
        // guarded like MysqlDatabase's — list readers answer [] / 0, version
        // readers say 'unknown', quote() fails loudly — so a late call after
        // teardown degrades like every other driver instead of dying with a
        // "call to member function on null" fatal.
        $this->pdo = null;
    }

    public function serverVersion(): string
    {
        if ($this->pdo === null) {
            return 'SQLite version unknown';
        }

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

    public function prepare(string $sql): ?PreparedStatementInterface
    {
        if ($this->pdo === null) {
            return null;
        }
        return new PdoPreparedStatement($this->pdo->prepare($sql));
    }

    public function dsn(): string { return 'sqlite:' . $this->path; }
    public function username(): string { return ''; }
}
