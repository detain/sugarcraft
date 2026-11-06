<?php

declare(strict_types=1);

namespace SugarCraft\Kv;

use SugarCraft\Kv\Lang;

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
 *     modified    TEXT NOT NULL,
 *     expires_at  INTEGER   -- epoch seconds, NULL = never
 *   );
 *
 * created/modified stay human-readable ISO-8601 (DATE_ATOM) strings;
 * expiry comparison alone is numeric, because offset-bearing strings
 * compare lexicographically, NOT by instant — a '+02:00' stamp read on
 * a '-05:00' box expired early or late depending on the local clock
 * text. Legacy DBs whose expires_at column holds DATE_ATOM strings are
 * converted once on open (see migrateExpiryColumn).
 *
 * The database file is a secrets store: it is chmod'ed to 0600 on open,
 * tightening any pre-existing world/group-readable file in place.
 */
final class Database
{
    private \SQLite3 $db;
    private string $name;
    private bool $closed = false;
    private bool $inTransaction = false;

    /**
     * @param bool $create When false the file MUST already exist: opening a
     *     missing database throws instead of silently creating an empty one.
     *     Read paths pass false so a typo'd "@dbname" cannot litter the data
     *     directory; only writes create.
     */
    public function __construct(string $path, string $name, bool $create = true)
    {
        $flags = \SQLITE3_OPEN_READWRITE | ($create ? \SQLITE3_OPEN_CREATE : 0);
        try {
            $this->db = new \SQLite3($path, $flags);
        } catch (\Exception $e) {
            // The SQLite3 constructor reports open failures as bare
            // Exception (SQLite3Exception only arrives once exceptions are
            // enabled for statements). A read on a missing/typo'd database
            // gets a domain sentence, not SQLite's raw wording.
            throw new \RuntimeException(
                "Cannot open the key-value database '{$name}': {$e->getMessage()}",
                0,
                $e
            );
        }
        // Diagnostics surface as SQLite3Exception at the failing call, not as
        // a false return that detonates later as a null-pointer TypeError.
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(5000);
        $this->name = $name;
        $this->init();
        \clearstatcache(true, $path);
        $this->secureFilePermissions($path);
    }

    /**
     * Tighten the database file (and WAL sidecars, if present) to 0600.
     *
     * SQLite creates files with the process umask, which is commonly 022
     * — leaving stored tokens world-readable. A file we cannot tighten
     * is refused loudly rather than silently exposed.
     */
    private function secureFilePermissions(string $path): void
    {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (!\is_file($file)) {
                continue;
            }
            \clearstatcache(true, $file);
            $perms = @\fileperms($file);
            if ($perms !== false && ($perms & 0o077) === 0) {
                continue;
            }
            if (!@\chmod($file, 0o600)) {
                throw new \RuntimeException(
                    \sprintf('Cannot set 0600 permissions on key-value store file: %s', $file)
                );
            }
        }
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
                expires_at  INTEGER
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
        $this->migrateExpiryColumn();
        $this->db->exec('PRAGMA journal_mode = WAL');
        // Pattern operations must share the byte-exact case semantics of the
        // exact ones: default LIKE folds case, so list/delete 'Abc-*' would
        // sweep keys that get('Abc-1') reads and delete('Abc-1') misses.
        $this->db->exec('PRAGMA case_sensitive_like = ON');
        // No PRAGMA foreign_keys: the schema has zero foreign keys, the
        // pragma would be dead configuration implying a relation that exists
        // nowhere.
    }

    /**
     * Convert legacy offset-string expiries to integer epoch seconds once.
     *
     * Legacy databases stored expires_at as a DATE_ATOM string; SQLite's
     * dynamic typing means the column may hold a mix. Rows whose value is
     * non-numeric text are parsed (offset-aware) and rewritten as epoch
     * seconds — an integer in fresh INTEGER-affinity columns, numeric text
     * in legacy TEXT-affinity columns (SQLite column affinity); the read
     * predicates CAST either shape to the same integer comparison.
     * Unparseable garbage is refused loudly — silently leaving
     * it would resurrect the lexicographic comparison for that row.
     * The rewrite runs inside a transaction: a crash mid-migration leaves
     * the old values intact, and every read predicate already tolerates
     * unmigrated text via CAST, so the migration is safe to re-run.
     */
    private function migrateExpiryColumn(): void
    {
        // SQLite GLOB negates a set with [^…] (NOT [!…] — that brackets the
        // literal characters ! 0-9 and would match every migrated digit row,
        // re-selecting it forever). A pure-digit TEXT cell is already a
        // numeric epoch that CAST compares correctly, so skip it.
        $select = $this->db->prepare(
            "SELECT key, expires_at FROM entries
             WHERE typeof(expires_at) = 'text'
               AND (expires_at NOT GLOB '[0-9]*' OR expires_at GLOB '*[^0-9]*')"
        );
        $rows = $select->execute();
        $pending = [];
        while ($row = $rows->fetchArray(\SQLITE3_ASSOC)) {
            $pending[] = [$row['key'], $row['expires_at']];
        }
        $select->close();

        if ($pending === []) {
            return;
        }

        $update = $this->db->prepare('UPDATE entries SET expires_at = :epoch WHERE key = :key');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            foreach ($pending as [$key, $raw]) {
                if ($raw === '') {
                    // An empty-string expiry means "never"; normalise to NULL
                    // (new DateTimeImmutable('') would silently mean "now").
                    $update->bindValue(':epoch', null, \SQLITE3_NULL);
                    $update->bindValue(':key', $key, \SQLITE3_TEXT);
                    $update->execute();
                    $update->reset();
                    continue;
                }
                try {
                    $instant = new \DateTimeImmutable((string) $raw);
                } catch (\Exception $e) {
                    throw new \RuntimeException(
                        \sprintf('Cannot migrate expiry "%s" for key "%s" to epoch seconds: %s', $raw, $key, $e->getMessage()),
                        0,
                        $e
                    );
                }
                $update->bindValue(':epoch', $instant->getTimestamp(), \SQLITE3_INTEGER);
                $update->bindValue(':key', $key, \SQLITE3_TEXT);
                $update->execute();
                $update->reset();
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            // The original failure is the reportable one; a ROLLBACK that
            // itself fails (connection already dead) must not mask it.
            try {
                $this->db->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            $update->close();
            throw $e;
        }
        $update->close();
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
               AND (expires_at IS NULL OR CAST(expires_at AS INTEGER) >= :now)'
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $stmt->bindValue(':now', \time(), \SQLITE3_INTEGER);
        $result = $stmt->execute();

        $row = $result->fetchArray(\SQLITE3_ASSOC);
        $stmt->close();

        return $row ? Entry::fromRow($row) : null;
    }

    /**
     * Get a single entry by key, bypassing TTL / expiry filter.
     * Returns null if not found.
     */
    public function raw(string $key): ?Entry
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
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::raw()} instead. REMOVED-IN: 2.0.0.
     */
    public function getRaw(string $key): ?Entry { return $this->raw($key); }

    /**
     * Set a value, creating or overwriting the entry.
     *
     * @param string $key    Entry key (unique within this database)
     * @param string $value  Stored value form: raw text when $binary is
     *                       false, already-base64 when true (Store::set
     *                       encodes; this layer stores what it is handed).
     * @param bool   $binary Whether the value is base64-encoded binary data
     * @param int|null $ttlSeconds If set, entry expires after this many seconds
     */
    public function set(string $key, string $value, bool $binary = false, ?int $ttlSeconds = null): Entry
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        // Expiry is stored as an absolute epoch integer; created/modified
        // stay DATE_ATOM because they are display metadata only.
        $expiresAt = null;
        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $expiresAt = \time() + $ttlSeconds;
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
        $stmt->bindValue(':expires_at', $expiresAt, $expiresAt === null ? \SQLITE3_NULL : \SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();

        // Read-back is deliberate: returning a locally built Entry would
        // duplicate the row-parsing contract and could
        // hand the caller a value no reader can ever observe. One extra
        // SELECT keeps writer and reader views provably identical.
        return $this->get($key) ?? throw new \RuntimeException(Lang::t('database.entry_unreadable'));
    }

    /**
     * Delete an entry by key.
     * Returns true if deleted, false if key was not present.
     *
     * Only LIVE rows are deletable by exact key: an expired row is invisible
     * to every reader, so silently removing it under a "Deleted 1" verdict
     * would delete data the operator never agreed exists. Expired residue is
     * {@see self::pruneExpired()}'s job.
     */
    public function delete(string $key): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM entries
             WHERE key = :key
               AND (expires_at IS NULL OR CAST(expires_at AS INTEGER) >= :now)'
        );
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $stmt->bindValue(':now', \time(), \SQLITE3_INTEGER);
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
     * @param string        $delimiter Accepted for signature compatibility only —
     *                                 this body never reads it (mode 'all' yields
     *                                 `Entry`; row joining is a renderer-side
     *                                 concern). Slated for v2 removal.
     * @return \Generator<Entry|string> Yields Entry objects, or string keys/values
     */
    public function list(
        ?string $pattern = null,
        bool $reverse = false,
        string $mode = 'all',
        string $delimiter = "\t",
    ): \Generator {
        $order = $reverse ? 'DESC' : 'ASC';

        $now = \time();
        if ($pattern !== null) {
            [$sql, $bindings] = $this->buildGlobQuery($pattern, $order, $now);
        } else {
            $sql = "SELECT key, value, binary, created, modified, expires_at
                    FROM entries
                    WHERE expires_at IS NULL OR CAST(expires_at AS INTEGER) >= :now
                    ORDER BY key {$order}";
            $bindings = [':now' => $now];
        }

        $stmt = $this->db->prepare($sql);
        foreach ($bindings as $k => $v) {
            $stmt->bindValue($k, $v, \is_int($v) ? \SQLITE3_INTEGER : \SQLITE3_TEXT);
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
     *
     * @param string|null $pattern Glob pattern, or null for total count.
     */
    public function count(?string $pattern = null): int
    {
        $now = \time();

        if ($pattern === null) {
            // Total count must honour TTL exactly like get()/list()/allKeys():
            // expired rows are dead and must not inflate the total.
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM entries
                 WHERE expires_at IS NULL OR CAST(expires_at AS INTEGER) >= :now'
            );
            $stmt->bindValue(':now', $now, \SQLITE3_INTEGER);
            $r = $stmt->execute();
            if ($r === false) {
                $stmt->close();
                throw new \RuntimeException(Lang::t('database.query_failed'));
            }
            $row = $r->fetchArray();
            $stmt->close();
            return (int) ($row[0] ?? 0);
        }

        $like = $this->globToLike($pattern);
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM entries WHERE key LIKE :pat ESCAPE \'\\\''
                . ' AND (expires_at IS NULL OR CAST(expires_at AS INTEGER) >= :now)'
        );
        $stmt->bindValue(':pat', $like, \SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, \SQLITE3_INTEGER);
        $result = $stmt->execute();
        if ($result === false) {
            $stmt->close();
            throw new \RuntimeException(Lang::t('database.query_failed'));
        }
        $row = $result->fetchArray(\SQLITE3_NUM);
        $stmt->close();

        return (int) ($row[0] ?? 0);
    }

    /**
     * Delete all LIVE entries matching a glob pattern.
     * Returns the number of entries deleted.
     *
     * The same liveness predicate every reader applies: expired rows under
     * the pattern are skipped (they are already invisible; counting them as
     * "deleted" inflated a number the operator could never reconcile against
     * a listing). {@see self::pruneExpired()} reclaims that residue.
     */
    public function deleteMany(string $pattern): int
    {
        $like = $this->globToLike($pattern);
        $stmt = $this->db->prepare(
            'DELETE FROM entries WHERE key LIKE :pat ESCAPE \'\\\''
                . ' AND (expires_at IS NULL OR CAST(expires_at AS INTEGER) >= :now)'
        );
        $stmt->bindValue(':pat', $like, \SQLITE3_TEXT);
        $stmt->bindValue(':now', \time(), \SQLITE3_INTEGER);
        $stmt->execute();
        $deleted = $this->db->changes();
        $stmt->close();

        return $deleted;
    }

    /**
     * Physically remove every already-expired entry.
     *
     * Same liveness predicate as every reader, negated: a row is dead when
     * expires_at is not NULL and its epoch is strictly before "now" — the
     * exact complement of the `>= :now` read filter, so a row this method
     * keeps can never be one a read would skip.
     */
    public function pruneExpired(): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM entries
             WHERE expires_at IS NOT NULL AND CAST(expires_at AS INTEGER) < :now'
        );
        $stmt->bindValue(':now', \time(), \SQLITE3_INTEGER);
        $stmt->execute();
        $deleted = $this->db->changes();
        $stmt->close();

        return $deleted;
    }

    /**
     * Convert a glob pattern (* and ?) to a SQL LIKE string.
     *
     * Spelling: `*` = any run, `?` = exactly one byte, `\*`/`\?` request a
     * literal asterisk/question-mark. A backslash before anything else is
     * itself a literal (doubled so the LIKE ESCAPE introducer cannot
     * quietly capture the next character).
     */
    private function globToLike(string $pattern): string
    {
        $like = '';
        $i = 0;
        $len = \strlen($pattern);

        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === '\\' && $i + 1 < $len && ($pattern[$i + 1] === '*' || $pattern[$i + 1] === '?')) {
                // Glob escape wins over wildcard expansion: a bare '*'/'?'
                // carries no meaning in LIKE, so the escaped pair collapses
                // to the literal character the caller asked to match.
                $like .= $pattern[$i + 1];
                $i += 2;
            } elseif ($c === '*') {
                $like .= '%';
                $i++;
            } elseif ($c === '?') {
                $like .= '_';
                $i++;
            } elseif ($c === '\\') {
                // A literal backslash is the LIKE ESCAPE introducer itself:
                // left unescaped it silently turns the NEXT character into an
                // escape sequence (e.g. "a\b" matching "ab" instead of the
                // real "a\b" key — audit #13). Every other backslash spelling
                // keeps its backslash and lets the following character take
                // its own rule.
                $like .= '\\\\';
                $i++;
            } elseif ($c === '%' || $c === '_') {
                // SQL LIKE wildcards arriving un-escaped from the caller are
                // literal characters in glob vocabulary: hide them behind
                // the ESCAPE introducer so they cannot widen the match.
                $like .= '\\' . $c;
                $i++;
            } else {
                $like .= $c;
                $i++;
            }
        }

        return $like;
    }

    /**
     * Return all non-expired keys in this database.
     *
     * @return list<string>
     */
    public function allKeys(): array
    {
        $keys = [];
        $now = \time();
        $stmt = $this->db->prepare(
            'SELECT key FROM entries
             WHERE expires_at IS NULL OR CAST(expires_at AS INTEGER) >= :now
             ORDER BY key ASC'
        );
        $stmt->bindValue(':now', $now, \SQLITE3_INTEGER);
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
     * scandir (not glob): glob treats '[', '*', '?' in directory names as
     * pattern syntax, so a crafted directory entry could hide or fake a
     * database listing. Plain readdir matching is injection-proof.
     *
     * @return list<string>
     */
    public static function listDatabases(string $dataDir): array
    {
        if (!\is_dir($dataDir)) {
            return [];
        }

        $dbs = [];
        foreach ((array) \scandir($dataDir) as $entry) {
            if (!\is_string($entry) || !\str_ends_with($entry, '.db')) {
                continue;
            }
            $basename = \substr($entry, 0, -3);
            if ($basename === '' || !\is_file($dataDir . '/' . $entry)) {
                continue;
            }
            // `settings` is reserved: it carried per-app config in the
            // upstream skate tool this port began from, and skate's own
            // listing excluded it. The exclusion survives so a directory
            // shared with skate does not surface a phantom value database.
            if ($basename !== 'settings') {
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
        if ($this->inTransaction) {
            throw new \RuntimeException('Nested transactions are not supported.');
        }
        $this->inTransaction = true;
        try {
            $this->db->exec('BEGIN IMMEDIATE');
            try {
                $result = $fn();
                $this->db->exec('COMMIT');
                return $result;
            } catch (\Throwable $e) {
                // Rollback failures must not mask the original error: if the
                // connection is already gone there is nothing to unwind and
                // the caller needs the first cause, not a teardown echo.
                try {
                    $this->db->exec('ROLLBACK');
                } catch (\Throwable) {
                }
                throw $e;
            }
        } finally {
            $this->inTransaction = false;
        }
    }

    /**
     * Close the database connection.
     *
     * Idempotent: safe to call multiple times. After close() the database
     * should not be used for further operations.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->db->close();
        $this->closed = true;
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
    private function buildGlobQuery(string $pattern, string $order, int $now): array
    {
        $like = $this->globToLike($pattern);

        return [
            "SELECT key, value, binary, created, modified, expires_at
             FROM entries
             WHERE key LIKE :pat ESCAPE '\\'
               AND (expires_at IS NULL OR CAST(expires_at AS INTEGER) >= :now)
             ORDER BY key {$order}",
            [':pat' => $like, ':now' => $now],
        ];
    }
}
