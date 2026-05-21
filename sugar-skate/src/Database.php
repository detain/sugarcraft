<?php

declare(strict_types=1);

namespace SugarCraft\Skate;

use SugarCraft\Skate\Lang;

/**
 * SQLite-backed store for one named database.
 *
 * Each database maps to a single SQLite file so that databases can be
 * independently backed up, deleted, or shared without touching others.
 *
 * Schema:
 *   CREATE TABLE IF NOT EXISTS entries (
 *     key         TEXT PRIMARY KEY,
 *     value       TEXT NOT NULL,
 *     binary      INTEGER NOT NULL DEFAULT 0,
 *     created     TEXT NOT NULL,
 *     modified    TEXT NOT NULL
 *   );
 */
final class Database
{
    private \SQLite3 $db;
    private string $name;

    public function __construct(string $path, string $name)
    {
        $this->db = new \SQLite3($path);
        $this->db->busyTimeout(5000);
        $this->name = $name;
        $this->init();
    }

    private function init(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS entries (
                key         TEXT PRIMARY KEY,
                value       TEXT NOT NULL,
                binary      INTEGER NOT NULL DEFAULT 0,
                created     TEXT NOT NULL,
                modified    TEXT NOT NULL,
                expires_at  TEXT
            )
        ');
        // Migrate legacy schema (no expires_at column)
        $result = $this->db->query("PRAGMA table_info(entries)");
        $columns = [];
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }
        if (!\in_array('expires_at', $columns, true)) {
            $this->db->exec('ALTER TABLE entries ADD COLUMN expires_at TEXT');
        }
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Get a single entry by key.
     * Returns null if not found or if the entry has expired.
     */
    public function get(string $key): ?Entry
    {
        $stmt = $this->db->prepare(
            'SELECT key, value, binary, created, modified, expires_at
             FROM entries
             WHERE key = :key
               AND (expires_at IS NULL OR expires_at >= :now)'
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $stmt->bindValue(':now', (new \DateTimeImmutable())->format(\DATE_ATOM), \SQLITE3_TEXT);
        $result = $stmt->execute();

        $row = $result->fetchArray(\SQLITE3_ASSOC);
        $stmt->close();

        return $row ? Entry::fromRow($row) : null;
    }

    /**
     * Get a single entry by key, bypassing TTL / expiry filter.
     * Returns null if not found.
     */
    public function getRaw(string $key): ?Entry
    {
        $stmt = $this->db->prepare(
            'SELECT key, value, binary, created, modified, expires_at FROM entries WHERE key = :key'
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $result = $stmt->execute();

        $row = $result->fetchArray(\SQLITE3_ASSOC);
        $stmt->close();

        return $row ? Entry::fromRow($row) : null;
    }

    /**
     * Set a value, creating or overwriting the entry.
     *
     * @param string $key    Entry key (unique within this database)
     * @param string $value  Value string (pass Entry::binary() for raw bytes)
     * @param bool   $binary Whether the value is base64-encoded binary data
     * @param int|null $ttlSeconds If set, entry expires after this many seconds
     */
    public function set(string $key, string $value, bool $binary = false, ?int $ttlSeconds = null): Entry
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $expiresAt = null;
        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format(\DATE_ATOM);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO entries (key, value, binary, created, modified, expires_at)
             VALUES (:key, :value, :binary, :created, :modified, :expires_at)
             ON CONFLICT(key) DO UPDATE SET
               value     = excluded.value,
               binary    = excluded.binary,
               modified  = excluded.modified,
               expires_at = excluded.expires_at'
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, \SQLITE3_TEXT);
        $stmt->bindValue(':binary', $binary ? 1 : 0, \SQLITE3_INTEGER);
        $stmt->bindValue(':created', $now, \SQLITE3_TEXT);
        $stmt->bindValue(':modified', $now, \SQLITE3_TEXT);
        $stmt->bindValue(':expires_at', $expiresAt, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        return $this->get($key) ?? throw new \RuntimeException(Lang::t('database.entry_unreadable'));
    }

    /**
     * Delete an entry by key.
     * Returns true if deleted, false if key was not present.
     */
    public function delete(string $key): bool
    {
        $stmt = $this->db->prepare('DELETE FROM entries WHERE key = :key');
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $stmt->execute();
        $changes = $this->db->changes();
        $stmt->close();

        return $changes > 0;
    }

    /**
     * List entries matching an optional glob pattern.
     *
     * @param string|null   $pattern   Glob pattern (* and ? wildcards), or null for all
     * @param bool          $reverse   Sort in reverse (descending) order
     * @param 'all'|'keys'|'values' $mode  What to return per entry
     * @param string        $delimiter Delimiter between key and value when mode='all'
     * @return \Generator<Entry|string> Yields Entry objects, or string keys/values
     */
    public function list(
        ?string $pattern = null,
        bool $reverse = false,
        string $mode = 'all',
        string $delimiter = "\t",
    ): \Generator {
        $order = $reverse ? 'DESC' : 'ASC';

        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        if ($pattern !== null) {
            [$sql, $bindings] = $this->buildGlobQuery($pattern, $order, $now);
        } else {
            $sql = "SELECT key, value, binary, created, modified, expires_at
                    FROM entries
                    WHERE expires_at IS NULL OR expires_at >= :now
                    ORDER BY key {$order}";
            $bindings = [':now' => $now];
        }

        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue($k, $v, \SQLITE3_TEXT);
        }
        $result = $stmt->execute();

        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $entry = Entry::fromRow($row);
            yield match ($mode) {
                'keys'   => $entry->key,
                'values' => $entry->rawValue(),
                default  => $entry,
            };
        }
        $stmt->close();
    }

    /**
     * Count entries matching a pattern.
     */
    public function count(?string $pattern = null): int
    {
        if ($pattern === null) {
            $r = $this->db->query('SELECT COUNT(*) FROM entries');
            $row = $r->fetchArray();
            return $row[0] ?? 0;
        }

        $count = 0;
        foreach ($this->list($pattern) as $_) {
            $count++;
        }
        return $count;
    }

    /**
     * Delete all entries matching a glob pattern.
     * Returns the number of entries deleted.
     */
    public function deleteMany(string $pattern): int
    {
        $deleted = 0;
        foreach ($this->list($pattern) as $entry) {
            if ($entry instanceof Entry) {
                $this->delete($entry->key);
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Return all non-expired keys in this database.
     *
     * @return list<string>
     */
    public function allKeys(): array
    {
        $keys = [];
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $stmt = $this->db->prepare(
            'SELECT key FROM entries
             WHERE expires_at IS NULL OR expires_at >= :now
             ORDER BY key ASC'
        );
        $stmt->bindValue(':now', $now, \SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $keys[] = $row['key'];
        }
        $stmt->close();
        return $keys;
    }

    /**
     * Get all database names from the config directory.
     *
     * @return list<string>
     */
    public static function listDatabases(string $dataDir): array
    {
        if (!\is_dir($dataDir)) {
            return [];
        }

        $dbs = [];
        foreach (\glob($dataDir . '/*.db') ?: [] as $file) {
            $basename = \basename($file, '.db');
            if ($basename !== '' && $basename !== 'settings') {
                $dbs[] = $basename;
            }
        }

        \sort($dbs);
        return $dbs;
    }

    /**
     * Execute a callback inside an atomic SQLite transaction.
     *
     * If the callback throws, the transaction is rolled back and the exception
     * propagates outward. On success the transaction is committed.
     *
     * @param callable(): mixed $fn
     * @return mixed The return value of the callback.
     * @throws \Throwable Re-throws any exception from the callback after rollback.
     */
    public function transaction(callable $fn): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->db->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build SQL + bindings for a glob pattern (excludes expired).
     *
     * Translates shell wildcards to SQL LIKE:
     *   *     → %
     *   ?     → _
     *   other glob chars are escaped
     */
    private function buildGlobQuery(string $pattern, string $order, string $now): array
    {
        $like = '';
        $i = 0;
        $len = \strlen($pattern);

        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === '*') {
                $like .= '%';
            } elseif ($c === '?') {
                $like .= '_';
            } elseif ($c === '%' || $c === '_') {
                // Escape SQL LIKE wildcards
                $like .= '\\' . $c;
            } else {
                $like .= $c;
            }
            $i++;
        }

        return [
            "SELECT key, value, binary, created, modified, expires_at
             FROM entries
             WHERE key LIKE :pat ESCAPE '\\'
               AND (expires_at IS NULL OR expires_at >= :now)
             ORDER BY key {$order}",
            [':pat' => $like, ':now' => $now],
        ];
    }
}
