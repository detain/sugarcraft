<?php

declare(strict_types=1);

namespace CandyCore\Skate;

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
                modified    TEXT NOT NULL
            )
        ');
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->db->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Get a single entry by key.
     * Returns null if not found.
     */
    public function get(string $key): ?Entry
    {
        $stmt = $this->db->prepare(
            'SELECT key, value, binary, created, modified FROM entries WHERE key = :key'
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $result = $stmt->execute();

        $row = $result->fetchArray(\SQLITE3_ASSOC);
        $stmt->free();

        return $row ? Entry::fromRow($row) : null;
    }

    /**
     * Set a value, creating or overwriting the entry.
     *
     * @param string $key    Entry key (unique within this database)
     * @param string $value  Value string (pass Entry::binary() for raw bytes)
     * @param bool   $binary Whether the value is base64-encoded binary data
     */
    public function set(string $key, string $value, bool $binary = false): Entry
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $stmt = $this->db->prepare(
            'INSERT INTO entries (key, value, binary, created, modified)
             VALUES (:key, :value, :binary, :created, :modified)
             ON CONFLICT(key) DO UPDATE SET
               value    = excluded.value,
               binary   = excluded.binary,
               modified = excluded.modified'
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, \SQLITE3_TEXT);
        $stmt->bindValue(':binary', $binary ? 1 : 0, \SQLITE3_INTEGER);
        $stmt->bindValue(':created', $now, \SQLITE3_TEXT);
        $stmt->bindValue(':modified', $now, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->free();

        return $this->get($key) ?? throw new \RuntimeException('Entry set but not readable');
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
        $stmt->free();

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

        if ($pattern !== null) {
            [$sql, $bindings] = $this->buildGlobQuery($pattern, $order);
        } else {
            $sql = "SELECT key, value, binary, created, modified FROM entries ORDER BY key {$order}";
            $bindings = [];
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
        $stmt->free();
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build SQL + bindings for a glob pattern.
     *
     * Translates shell wildcards to SQL LIKE:
     *   *     → %
     *   ?     → _
     *   other glob chars are escaped
     */
    private function buildGlobQuery(string $pattern, string $order): array
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
            "SELECT key, value, binary, created, modified FROM entries WHERE key LIKE :pat ESCAPE '\\' ORDER BY key {$order}",
            [':pat' => $like],
        ];
    }
}
