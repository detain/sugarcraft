<?php

declare(strict_types=1);

namespace SugarCraft\Kv;

use SugarCraft\Kv\Lang;
use SugarCraft\Kv\Support\Ttl;

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
 * converted once on open (see migrateExpiryColumn), and any db whose
 * column still carries TEXT affinity — the ALTER-add lineage, which
 * gives one logical column two physical schemas — is rebuilt to the
 * canonical INTEGER column once as well (see rebuildForIntegerAffinity).
 * Affinity is what makes the comparison laws self-enforcing: SQLite
 * coerces digit-like writes to numeric storage, so no reader needs the
 * old CAST bridge and `expires_at` can carry a partial index.
 *
 * The database file is a secrets store: it is chmod'ed to 0600 on open,
 * tightening any pre-existing world/group-readable file in place.
 */
final class Database
{
    /**
     * Schema stamp persisted in `PRAGMA user_version` (kv-028). The stamp
     * records which one-time migrations this file has paid: opens at the
     * current version skip every detection scan, so a completed migration
     * never turns into a forever-repeating O(rows) heuristic walk. Future
     * schema changes append a numbered step and raise this ceiling.
     *   1 — expiry VALUE normalisation (legacy DATE_ATOM cells → epoch)
     *   2 — expiry AFFINITY rebuild (TEXT-affinity ALTER lineage → INTEGER)
     */
    public const SCHEMA_VERSION = 2;

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
        // Tighten BEFORE the first write. init() migrates legacy schemas
        // — migrateExpiryColumn UPDATEs every row that carries an expiry —
        // so a legacy 0644 database would otherwise stay world-readable
        // through the very act that was supposed to fix it.
        \clearstatcache(true, $path);
        $this->secureFilePermissions($path);
        $this->init();
        // The journal_mode = WAL switch mints -wal/-shm sidecars at the
        // process umask; a second sweep so those land 0600 as well.
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
        // Does the table exist BEFORE this open? Fresh creates pay schema
        // construction only — never the legacy detection scans (kv-028).
        $wasPresent = (bool) $this->db->querySingle(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'entries'"
        );

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

        if (!$wasPresent) {
            // Born current: stamp immediately, no migration work exists.
            $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
        } elseif ($this->schemaVersion() < self::SCHEMA_VERSION) {
            // Legacy lineage (pre-versioning databases arrive unstamped;
            // version-1 files paid the value normalisation but still carry
            // the ALTER-add TEXT affinity). Both steps are idempotent, so
            // paying them in one pass is safe at any stamp below the top.
            // Migrate legacy schema (no expires_at column) first.
            $result = $this->db->query('PRAGMA table_info(entries)');
            $columns = [];
            while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
                $columns[] = $row['name'];
            }
            if (!\in_array('expires_at', $columns, true)) {
                // The historical spelling of the ALTER-add (TEXT): matched
                // against the real legacy files, then normalised on the
                // rebuild below — future majors delete this TEXT branch
                // entirely (kv-128).
                $this->db->exec('ALTER TABLE entries ADD COLUMN expires_at TEXT');
            }
            $this->migrateExpiryColumn();
            $this->rebuildForIntegerAffinity();
            // Stamp after the commits: a crash between the two costs one
            // idempotent rescan, never a skipped step.
            $this->db->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
        }

        $this->db->exec('PRAGMA journal_mode = WAL');
        // Partial index on the expiry column (kv-079): every liveness
        // predicate is now a bare comparison against numeric storage (the
        // affinity rebuild retired the CAST bridge that made every TTL
        // walk unindexable), so prune's `IS NOT NULL AND < :now` arm
        // seeks directly. The `expires_at IS NOT NULL` qualifier keeps the
        // index off the forever rows — the majority in a personal vault —
        // so it stays small. IF NOT EXISTS: opens pay the build once.
        $this->db->exec(
            'CREATE INDEX IF NOT EXISTS entries_expires ON entries (expires_at) WHERE expires_at IS NOT NULL'
        );
        // 64,000 KiB (62.5 MiB) page-cache cap per connection (negative spelling is KiB):
        // list/glob/pattern walks on large stores reread the same pages, and
        // the cap lets them land in cache instead of disk. It is a ceiling,
        // not an allocation — a small store never grows past its working set.
        $this->db->exec('PRAGMA cache_size = -64000');
        // No PRAGMA synchronous = NORMAL: the WAL companion is declined. This
        // is a secrets store with per-command write cadence — at that rate the
        // speed win is worth nothing, while the power-loss window that can
        // drop the newest commits is.
        // Pattern operations must share the case-sensitive semantics of the
        // exact ones: default LIKE folds case, so list/delete 'Abc-*' would
        // sweep keys that get('Abc-1') reads and delete('Abc-1') misses.
        $this->db->exec('PRAGMA case_sensitive_like = ON');
        // No PRAGMA foreign_keys: the schema has zero foreign keys, the
        // pragma would be dead configuration implying a relation that exists
        // nowhere.
    }

    /**
     * Current schema stamp of the open file (`PRAGMA user_version`).
     */
    private function schemaVersion(): int
    {
        return (int) $this->db->querySingle('PRAGMA user_version');
    }

    /**
     * Convert legacy offset-string expiries to epoch seconds exactly once.
     *
     * Legacy databases stored expires_at as a DATE_ATOM string; SQLite's
     * dynamic typing means the column may hold a mix. Rows whose text is
     * not already canonical integer spelling are parsed and rewritten —
     * an integer in fresh INTEGER-affinity columns, numeric text in legacy
     * TEXT-affinity columns (SQLite column affinity), which the affinity
     * rebuild immediately below then converts to numeric storage as part
     * of the same open.
     *
     * The whole pass runs inside one `BEGIN IMMEDIATE`: the snapshot is
     * taken AFTER the write lock (kv-027), so no concurrent commit can
     * land between reading and rewriting a cell, and every per-row rewrite
     * additionally carries the value it was read with as a compare-
     * and-set guard (an optimistic belt: a row that changed under us is
     * skipped, never clobbered). A failure to take the lock surfaces as
     * the library's domain sentence, not a bare SQLite3Exception.
     *
     * Unparseable garbage no longer bricks the vault at open (kv-022):
     * signed-digit text — the shape `Entry::parseExpiry` already accepts
     * — converts to that epoch, and genuine garbage is quarantined to the
     * epoch-0 (definitively expired) cell with a loud sanitized stderr
     * warning. Quarantine deliberately does NOT mean NULL: a NULL expiry
     * would launder a cell that today reads dead into a forever entry —
     * the same laundering the export boundary law (kv-026) refuses. The
     * row stays on disk, reads as expired, and clears through prune.
     */
    private function migrateExpiryColumn(): void
    {
        try {
            $this->db->exec('BEGIN IMMEDIATE');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                \sprintf("Cannot start the expiry migration for database '%s': %s", $this->name, $e->getMessage()),
                0,
                $e
            );
        }

        try {
            foreach ($this->collectLegacyExpiryRows() as [$key, $raw]) {
                $this->rewriteLegacyExpiryRow($key, (string) $raw);
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            // The original failure is the reportable one; a ROLLBACK that
            // itself fails (connection already dead) must not mask it.
            try {
                $this->db->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * Snapshot the cells that still carry non-epoch text expiries.
     *
     * The scan predicate is CAST-identity: a text cell already spelling
     * its own canonical integer (unsigned OR signed digits — the negative
     * shape parseExpiry reads as a true past epoch) needs no rewrite and
     * must not re-enter the loop forever. Anything else — DATE_ATOM text,
     * zero-padded digits, garbage — is collected. Runs inside the
     * migration transaction; never call it unlocked.
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function collectLegacyExpiryRows(): array
    {
        $select = $this->db->prepare(
            "SELECT key, expires_at FROM entries
             WHERE typeof(expires_at) = 'text'
               AND expires_at <> CAST(CAST(expires_at AS INTEGER) AS TEXT)"
        );
        $rows = $select->execute();
        $pending = [];
        while ($row = $rows->fetchArray(\SQLITE3_ASSOC)) {
            $pending[] = [$row['key'], $row['expires_at']];
        }
        $select->close();

        return $pending;
    }

    /**
     * Rewrite one snapshotted legacy expiry cell, CAS-guarded.
     *
     * @return bool false when the cell changed under the snapshot (the
     *     concurrent commit wins — the rewrite is skipped, not faked).
     */
    private function rewriteLegacyExpiryRow(string $key, string $raw): bool
    {
        $update = $this->db->prepare(
            'UPDATE entries SET expires_at = :epoch
             WHERE key = :key AND expires_at = :raw'
        );
        $update->bindValue(':key', $key, \SQLITE3_TEXT);
        $update->bindValue(':raw', $raw, \SQLITE3_TEXT);

        if ($raw === '') {
            // An empty-string expiry means "never"; normalise to NULL
            // (new DateTimeImmutable('') would silently mean "now").
            $update->bindValue(':epoch', null, \SQLITE3_NULL);
            $update->execute();
            $changed = $this->db->changes() > 0;
            $update->close();

            return $changed;
        }

        if (\preg_match('/^-?[0-9]+$/', $raw) === 1) {
            // Signed or zero-padded digit text: mirror parseExpiry and
            // store the epoch it already means ((int) spelling).
            $update->bindValue(':epoch', (int) $raw, \SQLITE3_INTEGER);
            $update->execute();
            $changed = $this->db->changes() > 0;
            $update->close();

            return $changed;
        }

        try {
            $instant = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            // kv-022b quarantine: dead-epoch stamp + loud warning instead
            // of bricking every future open of the whole database.
            $update->bindValue(':epoch', 0, \SQLITE3_INTEGER);
            $update->execute();
            $changed = $this->db->changes() > 0;
            $update->close();
            \fwrite(
                \STDERR,
                \sprintf(
                    "%s\n",
                    Store::sanitizeForTty(\sprintf(
                        "candy-kv: key '%s' carries an unreadable expiry '%s'; quarantined it as expired — run `prune` to drop it or set a new expiry to keep it.",
                        $key,
                        $raw,
                    ))
                )
            );

            return $changed;
        }

        $update->bindValue(':epoch', $instant->getTimestamp(), \SQLITE3_INTEGER);
        $update->execute();
        $changed = $this->db->changes() > 0;
        $update->close();

        return $changed;
    }

    /**
     * Give the expiry column its canonical INTEGER affinity exactly once.
     *
     * Two physical schemas served one logical column (kv-128): databases
     * created current arrived with `expires_at INTEGER`, while every
     * pre-column database got the ALTER-add's TEXT affinity forever. The
     * divergence forced a CAST bridge onto every TTL predicate — and a
     * CAST predicate can never be served by an index, so all list/count/
     * prune walks were permanent full scans (kv-079). SQLite's write-time
     * affinity conversion is the enforcement no bridge can match: under
     * INTEGER affinity, digit-like writes land numeric no matter who
     * wrote them.
     *
     * The rebuild is a classic create-copy-swap inside ONE `BEGIN
     * IMMEDIATE`: a mid-flight failure rolls back to the untouched
     * original, and because the schema stamp is only raised after this
     * method commits, a crash costs one idempotent redo, never a skipped
     * step. The copy casts values that the value normalisation above has
     * already reduced to NULL or canonical epoch text — CAST is exact on
     * both shapes. No-op when the column already carries INTEGER affinity
     * (fresh lineage, or a file that already paid this step).
     */
    private function rebuildForIntegerAffinity(): void
    {
        $type = $this->db->querySingle(
            "SELECT type FROM pragma_table_info('entries') WHERE name = 'expires_at'"
        );
        if (\strcasecmp((string) $type, 'INTEGER') === 0) {
            return;
        }

        try {
            $this->db->exec('BEGIN IMMEDIATE');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                \sprintf("Cannot start the affinity rebuild for database '%s': %s", $this->name, $e->getMessage()),
                0,
                $e
            );
        }

        try {
            $this->db->exec('
                CREATE TABLE entries_migrated (
                    key         TEXT PRIMARY KEY,
                    value       TEXT NOT NULL,
                    binary      INTEGER NOT NULL DEFAULT 0,
                    created     TEXT NOT NULL,
                    modified    TEXT NOT NULL,
                    expires_at  INTEGER
                )
            ');
            $this->db->exec('
                INSERT INTO entries_migrated
                    SELECT key, value, binary, created, modified,
                           CAST(expires_at AS INTEGER)
                    FROM entries
            ');
            $this->db->exec('DROP TABLE entries');
            $this->db->exec('ALTER TABLE entries_migrated RENAME TO entries');
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) {
            // The original failure is the reportable one; a ROLLBACK that
            // itself fails (connection already dead) must not mask it.
            try {
                $this->db->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw $e;
        }
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
        // stay DATE_ATOM because they are display metadata only. The DO
        // UPDATE clause deliberately omits `created`: an overwrite refreshes
        // `modified` only, so Entry::$createdAt keeps its documented
        // "first created" meaning. The :created binding lands solely on the
        // INSERT path — reading the bind list without the SET list makes
        // this upsert look like it re-stamps creation time on every write,
        // which it does not.
        // Mirror guard (candy-kv-001): Store::set already refuses out-of-range
        // TTLs, but Database is a public door too — an embedded consumer
        // reaching it directly must hit the same horizon, not the epoch
        // arithmetic underneath.
        if ($ttlSeconds !== null && !Ttl::inRange($ttlSeconds)) {
            throw new \InvalidArgumentException(\sprintf(
                'TTL must be between 1 and %d seconds, got %d; omit the TTL for a forever entry.',
                Ttl::MAX_SECONDS,
                $ttlSeconds,
            ));
        }
        $expiresAt = null;
        if ($ttlSeconds !== null) {
            $expiresAt = \time() + $ttlSeconds;
            // Belt-and-braces on the overflow math that minted the kv-001
            // poison row: with the guard above this cannot fire — if it ever
            // does, the horizon arithmetic broke and the write stops dead.
            \assert($expiresAt <= \PHP_INT_MAX);
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
               AND (expires_at IS NULL OR expires_at >= :now)'
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
        // The docblock declares 'all'|'keys'|'values'; a typo must fail loud
        // here, not reach the yield match below and silently produce Entry
        // objects for a caller that asked for keys or values (kv-015).
        if (!\in_array($mode, ['all', 'keys', 'values'], true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown list mode "%s"; expected "all", "keys" or "values".',
                $mode,
            ));
        }

        $order = $reverse ? 'DESC' : 'ASC';

        $now = \time();
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
                 WHERE expires_at IS NULL OR expires_at >= :now'
            );
            $stmt->bindValue(':now', $now, \SQLITE3_INTEGER);
            // One failure model: enableExceptions(true) makes execute() throw
            // (a false-returning API is a fiction this connection can never
            // produce — kv-064). A dead false-check arm is worse than none:
            // it pretends to handle errors while swallowing the one real
            // path, SQLite3Exception with SQLite's own diagnostic.
            $r = $stmt->execute();
            $row = $r->fetchArray();
            $stmt->close();
            return (int) ($row[0] ?? 0);
        }

        $like = $this->globToLike($pattern);
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM entries WHERE key LIKE :pat ESCAPE \'\\\''
                . ' AND (expires_at IS NULL OR expires_at >= :now)'
        );
        $stmt->bindValue(':pat', $like, \SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, \SQLITE3_INTEGER);
        $result = $stmt->execute();
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
                . ' AND (expires_at IS NULL OR expires_at >= :now)'
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
             WHERE expires_at IS NOT NULL AND expires_at < :now'
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
     * Spelling: `*` = any run, `?` = exactly one character (LIKE operates
     * over the stored text, so a multi-byte codepoint fills a single `_`),
     * `\*`/`\?` request a
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
             WHERE expires_at IS NULL OR expires_at >= :now
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
            // `settings` is reserved in the data directory: a config
            // database may live under that name beside the value
            // databases, and a list-driven backup must never mistake it
            // for one. The exclusion is paired with Store's refusal to
            // write reserved names — hiding a database from enumeration
            // is only honest when the same API cannot create or change
            // what no listing will ever show (kv-023, kv-106).
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
     * `BEGIN` spelling is a lock decision, not a style choice (kv-080):
     * IMMEDIATE claims the sole WAL writer slot before the callback reads a
     * byte, so a callback that only reads would still serialise every other
     * process's writes for its whole duration. `$write = false` opens a
     * DEFERRED read snapshot instead — WAL readers never block writers, and
     * the callback additionally sees one stable instant of the data. A
     * deferred transaction that DOES write upgrades on first write and
     * surfaces SQLITE_BUSY if another writer got there first; that is the
     * documented cost of claiming read-only and lying about it.
     *
     * @param callable(): mixed $fn
     * @param bool $write True (default) claims the write lock up front —
     *     the right choice when the callback commits changes; the upgrade
     *     path from deferred cannot promise a winner.
     * @return mixed The return value of the callback.
     * @throws \Throwable Re-throws any exception from the callback after rollback.
     */
    public function transaction(callable $fn, bool $write = true): mixed
    {
        if ($this->inTransaction) {
            throw new \RuntimeException('Nested transactions are not supported.');
        }
        $this->inTransaction = true;
        try {
            $this->db->exec($write ? 'BEGIN IMMEDIATE' : 'BEGIN DEFERRED');
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
               AND (expires_at IS NULL OR expires_at >= :now)
             ORDER BY key {$order}",
            [':pat' => $like, ':now' => $now],
        ];
    }
}
