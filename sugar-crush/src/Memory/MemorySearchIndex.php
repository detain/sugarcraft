<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

/**
 * A SQLite FTS5 full-text index over a {@see MemoryStore}'s notes, ranked by
 * BM25 (roadmap 5.3-1).
 *
 * THE INDEX IS A DERIVED CACHE, never a source of truth. The notes are plain
 * markdown files a person may edit by hand (docs/MEMORY.md "Hand-edited
 * notes"), so every {@see rank()} first re-syncs against the files it is
 * handed: a file whose size or mtime moved since it was indexed — or whose
 * mtime is not strictly older than the second it was indexed in, which is how
 * a rewrite inside that same second is still caught — is read again, and a
 * file that vanished is dropped. Deleting the cache file loses nothing; the
 * next search rebuilds it.
 *
 * WHERE IT LIVES is the caller's choice: the home store keeps it beside its
 * notes as a dot-file (`.search.sqlite`, or `.search-<key>.sqlite` for a
 * project-keyed store, so two projects sharing the home directory never evict
 * each other's rows), which no listing reads as a note. A repository store
 * passes {@see inMemory()} instead: its directory is git-visible, and a cache
 * file there would be one more file in the user's checkout.
 *
 * PDO, like the session store and the repo-map tag cache: `doctor` probes
 * `pdo_sqlite`, and every SQLite file this app opens is reached the same way.
 *
 * DEGRADES, NEVER FAILS. A PHP build without pdo_sqlite, an SQLite without
 * FTS5, a cache file that cannot be opened or a lock held too long by another
 * process all make {@see rank()} answer null, and the store falls back to the
 * substring scan it always did. FTS5 being absent is remembered for the life
 * of the instance; a transient failure is not.
 *
 * NO ON-DISK CONNECTION OUTLIVES A CALL. Each {@see rank()} opens, works and
 * lets the handle go:
 * the parent process forks engine turns, and an SQLite handle carried across
 * fork() is one the SQLite documentation tells callers never to share.
 *
 * The keyword half of roadmap 5.3 (Claw's hybrid memory search); the
 * embedding half is 5.3-2.
 */
final class MemorySearchIndex
{
    /** The cache file's name in an unkeyed home store. */
    public const FILENAME = '.search.sqlite';

    /** In-process cache: the repository store's, see the class docblock. */
    private const IN_MEMORY = ':memory:';

    /**
     * BM25 column weights, in table column order: `path` (unindexed), the
     * note body, its type, its tags. A tag is a label someone chose, so a hit
     * there outranks the same word in passing prose.
     */
    private const WEIGHTS = '0.0, 1.0, 0.5, 2.0';

    /** How long a search waits on another process's write lock, in seconds. */
    private const BUSY_TIMEOUT_SECONDS = 2;

    /** SQLITE_CORRUPT and SQLITE_NOTADB: a cache file worth deleting. */
    private const DAMAGED_CODES = [11, 26];

    private bool $unavailable = false;

    /** The open in-memory connection; an on-disk index keeps none. */
    private ?\PDO $memory = null;

    /**
     * @param string $location the cache file, or `:memory:`
     * @param string $module   the FTS module the virtual table uses; a test
     *                         names a missing one to exercise the fallback
     */
    private function __construct(
        private readonly string $location,
        private readonly string $module,
    ) {
    }

    /** An index cached in $file, created owner-only on first use. */
    public static function at(string $file, string $module = 'fts5'): self
    {
        return new self($file, $module);
    }

    /** An index held in this process only, rebuilt from the files per instance. */
    public static function inMemory(string $module = 'fts5'): self
    {
        return new self(self::IN_MEMORY, $module);
    }

    /** Where the cache lives: a file path, or `:memory:`. */
    public function location(): string
    {
        return $this->location;
    }

    /**
     * Whether this instance has given up on FTS5 (absent from the SQLite
     * build, or no pdo_sqlite at all). A transient failure does not set it.
     */
    public function unavailable(): bool
    {
        return $this->unavailable;
    }

    /**
     * Re-sync the index against $files, then rank them for $query.
     *
     * Every word of the query must match (in any order, any column; a word
     * also matches as a prefix, and the porter stemmer folds `deploying` onto
     * `deploy`), ranked best first by BM25. Notes that contain the query
     * verbatim as a substring but miss a word match — `oy-sta` inside
     * `deploy-staging` — follow, in path order, so no note the old substring
     * scan found is ever lost.
     *
     * @param list<string> $files the note files to search, every other path is ignored
     * @param \Closure(string): (MemoryEntry|string) $read reads one file: the
     *        entry, or the reason it cannot be read
     *
     * @return array{paths: list<string>, unreadable: array<string, string>}|null
     *         the matching paths, best first, and every file currently
     *         unreadable with its reason; null when the index cannot be used
     */
    public function rank(array $files, string $query, \Closure $read): ?array
    {
        if ($this->unavailable) {
            return null;
        }

        $db = $this->open();
        if ($db === null) {
            return null;
        }

        try {
            $unreadable = $this->sync($db, $files, $read);
            $paths = $this->query($db, $query, array_flip($files));
        } catch (\Throwable $e) {
            $this->discardIfDamaged($e);

            return null;
        }

        // An on-disk connection closes with $db, here; only the in-memory
        // one, which IS the index, is kept.
        return ['paths' => $paths, 'unreadable' => $unreadable];
    }

    /**
     * Drop $file's row so the next {@see rank()} reads it again. The store
     * calls this on every write it makes itself; the mtime rule would catch
     * the change anyway, this just does not depend on the clock to do it.
     * A no-op until the index has been opened once.
     */
    public function forget(string $file): void
    {
        if ($this->unavailable) {
            return;
        }
        if ($this->location === self::IN_MEMORY ? $this->memory === null : !is_file($this->location)) {
            return;
        }

        $db = $this->open();
        if ($db === null) {
            return;
        }

        try {
            $this->dropPath($db, $file);
        } catch (\Throwable) {
            // The next rank() re-reads by mtime regardless.
        }
    }

    private function open(): ?\PDO
    {
        if ($this->memory !== null) {
            return $this->memory;
        }

        if (!\extension_loaded('pdo_sqlite')) {
            $this->unavailable = true;

            return null;
        }

        // Owner-only from the first byte: the index holds every note's text,
        // and the notes themselves are 0600. The umask covers SQLite's
        // journal file too.
        $previousUmask = umask(0077);
        try {
            $db = new \PDO('sqlite:' . $this->location);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(\PDO::ATTR_TIMEOUT, self::BUSY_TIMEOUT_SECONDS);

            // A derived cache: a crash costs a rebuild, never a note, so it
            // does not pay for an fsync per search.
            $db->exec('PRAGMA synchronous = OFF');
            $db->exec(
                'CREATE TABLE IF NOT EXISTS docs ('
                . 'id INTEGER PRIMARY KEY, path TEXT NOT NULL UNIQUE, mtime INTEGER NOT NULL, '
                . 'size INTEGER NOT NULL, indexed_at INTEGER NOT NULL, reason TEXT)'
            );
            $db->exec(
                'CREATE VIRTUAL TABLE IF NOT EXISTS notes USING ' . $this->module
                . "(path UNINDEXED, content, type, tags, tokenize = 'porter unicode61')"
            );
        } catch (\Throwable $e) {
            // A missing module is permanent for this build; anything else
            // (a lock, a damaged cache) is retried on the next search.
            if (str_contains($e->getMessage(), 'no such module')) {
                $this->unavailable = true;
            }
            $this->discardIfDamaged($e);

            return null;
        } finally {
            umask($previousUmask);
        }

        if ($this->location === self::IN_MEMORY) {
            $this->memory = $db;
        }

        return $db;
    }

    /**
     * Delete a cache file SQLite reported as damaged (SQLITE_CORRUPT, or
     * SQLITE_NOTADB for a file that is not a database at all), so the next
     * search rebuilds it instead of falling back to the scan for good.
     */
    private function discardIfDamaged(\Throwable $e): void
    {
        $code = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        if ($this->location !== self::IN_MEMORY && \in_array($code, self::DAMAGED_CODES, true)) {
            @unlink($this->location);
        }
    }

    /**
     * @param list<string> $files
     * @param \Closure(string): (MemoryEntry|string) $read
     *
     * @return array<string, string> the unreadable files among $files, path => reason
     */
    private function sync(\PDO $db, array $files, \Closure $read): array
    {
        $known = [];
        foreach ($db->query('SELECT id, path, mtime, size, indexed_at, reason FROM docs', \PDO::FETCH_ASSOC) ?: [] as $row) {
            $known[(string) $row['path']] = $row;
        }

        $now = time();
        $unreadable = [];
        $db->exec('BEGIN IMMEDIATE');
        try {
            foreach ($files as $file) {
                clearstatcache(true, $file);
                $mtime = @filemtime($file);
                $size = @filesize($file);
                if ($mtime === false || $size === false) {
                    continue;
                }

                $row = $known[$file] ?? null;
                unset($known[$file]);
                if ($row !== null && (int) $row['mtime'] === $mtime && (int) $row['size'] === $size
                    && $mtime < (int) $row['indexed_at']) {
                    if ($row['reason'] !== null) {
                        $unreadable[$file] = (string) $row['reason'];
                    }
                    continue;
                }

                $entry = $read($file);
                $reason = $entry instanceof MemoryEntry ? null : $entry;
                if ($reason !== null) {
                    $unreadable[$file] = $reason;
                }
                $this->store($db, $file, $mtime, $size, $now, $entry instanceof MemoryEntry ? $entry : null, $reason);
            }

            // Whatever is left was indexed once and is no longer among the
            // files: deleted, renamed, or moved to another scope.
            foreach (array_keys($known) as $gone) {
                $this->dropPath($db, $gone);
            }

            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');

            throw $e;
        }

        return $unreadable;
    }

    private function store(\PDO $db, string $file, int $mtime, int $size, int $now, ?MemoryEntry $entry, ?string $reason): void
    {
        $this->dropPath($db, $file);

        $db->prepare('INSERT INTO docs (path, mtime, size, indexed_at, reason) VALUES (?, ?, ?, ?, ?)')
            ->execute([$file, $mtime, $size, $now, $reason]);

        if ($entry === null) {
            return;
        }

        $db->prepare('INSERT INTO notes (rowid, path, content, type, tags) VALUES (?, ?, ?, ?, ?)')->execute([
            (int) $db->lastInsertId(),
            $file,
            $entry->content(),
            $entry->type(),
            // Newline-joined: a query never spans two tags the way a
            // space-joined string would let it.
            implode("\n", $entry->tags()),
        ]);
    }

    private function dropPath(\PDO $db, string $file): void
    {
        $find = $db->prepare('SELECT id FROM docs WHERE path = ?');
        $find->execute([$file]);
        $id = $find->fetchColumn();
        $find->closeCursor();
        if ($id === false) {
            return;
        }

        $db->prepare('DELETE FROM notes WHERE rowid = ?')->execute([(int) $id]);
        $db->prepare('DELETE FROM docs WHERE id = ?')->execute([(int) $id]);
    }

    /**
     * @param array<string, int> $allowed the searched files, as keys
     *
     * @return list<string>
     */
    private function query(\PDO $db, string $query, array $allowed): array
    {
        $paths = [];

        $match = self::matchExpression($query);
        if ($match !== null) {
            $stmt = $db->prepare(
                'SELECT path FROM notes WHERE notes MATCH ? '
                . 'ORDER BY bm25(notes, ' . self::WEIGHTS . '), path'
            );
            $stmt->execute([$match]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $path) {
                $paths[(string) $path] = true;
            }
        }

        // The substring tail: LIKE is ASCII case-insensitive, exactly the
        // stripos() the scan used, so the union is a superset of its answer.
        $like = '%' . strtr($query, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $stmt = $db->prepare(
            "SELECT path FROM notes WHERE content LIKE :l ESCAPE '\\' OR type LIKE :l ESCAPE '\\' "
            . "OR tags LIKE :l ESCAPE '\\' ORDER BY path"
        );
        $stmt->execute([':l' => $like]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $path) {
            $paths[(string) $path] ??= true;
        }

        return array_values(array_filter(
            array_map('strval', array_keys($paths)),
            static fn(string $path): bool => isset($allowed[$path]),
        ));
    }

    /**
     * The FTS5 expression for $query: every word as a quoted prefix term,
     * implicitly AND-ed. Quoting is the escape — FTS5 syntax (`OR`, `NEAR`,
     * `-`, `:`) typed in a query is matched as words, never interpreted.
     * Null when the query holds no word at all.
     */
    public static function matchExpression(string $query): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, \PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return null;
        }

        return implode(' ', array_map(
            static fn(string $word): string => '"' . str_replace('"', '""', $word) . '"*',
            array_values(array_unique($words)),
        ));
    }
}
