<?php

declare(strict_types=1);

namespace SugarCraft\Code\Session;

use PDO;
use SugarCraft\Code\Memory\MemorySearchIndex;

/**
 * The FTS5 side table behind `withBodySearch` (SES-2, plan_comparison §SES-2).
 *
 * Two tables live beside the store's own inside `sessions.db`, on the SAME
 * PDO connection so a transcript save and its index update commit together:
 *
 *  - `session_message_rows` is the join anchor: one row per indexed message
 *    (session id, message id, body hash) whose INTEGER PRIMARY KEY doubles
 *    as the FTS rowid. Its FK `ON DELETE CASCADE` mirrors `session_events`,
 *    so deleting or pruning a session takes its index rows with everything
 *    else the cascade already owns.
 *  - `session_messages_fts` is an `fts5(session_id UNINDEXED, message_id
 *    UNINDEXED, body)` table keyed to the anchor's rowid, tokenized
 *    `porter unicode61` — the same tokenizer the memory cache indexes with.
 *
 * DEGRADE-NOT-FAIL is the whole point: a SQLite built without FTS5 throws
 * `no such module: fts5` at the `CREATE VIRTUAL TABLE`, the latch closes,
 * and every operation becomes a silent no-op so callers answer from the
 * LIKE prefilter that existed before this file, byte-unchanged. The latch
 * is process-lifetime on purpose — FTS5 is a compile option of SQLite, it
 * cannot appear between two calls of one process.
 *
 * Reads INNER-JOIN the anchor, so a session removed between its cascade
 * delete and the garbage pass can never surface through a stale FTS row;
 * {@see collectGarbage()} reclaims space, it is not load-bearing.
 *
 * BUILD TRUST IS PERSISTED (C-SES-2): the one-row `session_search_meta`
 * marker records the index shape, when the sweep finished, and whether a
 * sync failed. A fresh process seeds itself from that marker instead of
 * sweeping every transcript again at its first body search — only the first
 * build per database pays for the sweep, and the per-save
 * {@see EnhancedSessionStore::syncSearchIndex()} diff keeps it current in
 * between. A lock contention that merely refused to ANSWER a search never
 * touches the marker; a failed sync records `dirty = 1` durably so the next
 * process re-sweeps.
 *
 * @see MemorySearchIndex the sibling FTS5 user — a cache on its own file,
 *      rebuilt from source notes; this one is a side table that shares the
 *      session store's connection, transaction and FK cascade.
 */
final class SessionSearchIndex
{
    /**
     * Bodies longer than this are indexed up to the cap only. A message can
     * carry a whole file a tool dumped; the first 16 KiB answers "which
     * session talked about X" as well as the rest, and keeps one runaway
     * paste from inflating the database beside the transcript that already
     * stores it. The hash covers the capped bytes, so a grow-past-the-cap
     * edit still re-indexes what is inside.
     */
    public const MAX_BODY_BYTES = 16384;

    /** Sessions one ranked search may return; the picker pages far below this. */
    public const SESSION_CAP = 200;

    /**
     * Message hits one search reads (bm25 order). `ORDER BY bm25` only
     * resolves at the top level of the FTS query — wrapped in a subquery
     * SQLite refuses it — so sessions are folded from ranked messages in
     * PHP, each session keeping its first (best) hit.
     */
    private const MESSAGE_CAP = 800;

    /** Placeholder-chunk size for IN lists — the same law as SessionStore::childCount(). */
    private const CHUNK = 400;

    /**
     * The shape this class can read. Bump it whenever the anchor/FTS pair,
     * the tokenizer or the capping law changes meaning, and every database
     * carrying a marker for the old shape rebuilds exactly once more; within
     * one shape the marker is what lets the NEXT process trust the table on
     * disk instead of sweeping every transcript again at first search (C-SES-2).
     */
    public const INDEX_VERSION = 1;

    /**
     * Sessions one rebuild transaction sweeps. One WAL commit per session is
     * what made the old open sweep measure 128 s at 300 sessions; one
     * transaction for a whole database hands the write lock to a concurrent
     * writer only at chunk boundaries, and 500 transcripts keeps each commit
     * comfortably inside the busy timeout.
     */
    public const REBUILD_CHUNK = 500;

    /**
     * Test seam — sees whether the store's rebuild hands the sweep a live
     * transaction. Set only from tests, cleared back to null there.
     *
     * @var (callable(): void)|null
     */
    private static $syncObserver = null;

    private bool $latched;

    /**
     * Trust bookkeeping for THIS process. The on-disk marker seeds it, so a
     * second process over an index the first one built (and kept current via
     * the per-save {@see syncMessages()} diff) does zero sweep work. "Never
     * swept yet" rides {@see $built}; {@see $dirty} means a failure THIS
     * process actually observed, and markBuilt persists exactly that.
     */
    private bool $built = false;
    private bool $dirty = false;

    private function __construct(
        private readonly PDO $pdo,
        private readonly string $module,
    ) {
        $this->latched = !$this->createSchema();
        if (!$this->latched) {
            $this->seedTrustFromMarker();
        }
    }

    /**
     * Open the index on the session store's connection. Never throws: a
     * database without FTS5 (or one that refused the DDL for any other
     * structural reason) comes back latched, and the caller degrades.
     *
     * @param string $module test seam — the FTS5 module name to attempt
     */
    public static function create(PDO $pdo, string $module = 'fts5'): self
    {
        return new self($pdo, $module);
    }

    /** False once the latch is set; while false every method is a no-op or null. */
    public function available(): bool
    {
        return !$this->latched;
    }

    /**
     * Test seam: arm (or disarm with null) an observer fired at the top of
     * every {@see syncMessages()} body — the pin for "the rebuild sweep runs
     * inside a transaction". Keep the callable side-effect-free: an observer
     * that throws lands in the sync's catch and marks the index dirty, so
     * tests collect values and assert them outside.
     */
    public static function observeSyncsForTesting(?callable $observer): void
    {
        self::$syncObserver = $observer;
    }

    /**
     * True until this process has swept the transcripts (the first body
     * search of a process the on-disk marker does not vouch for pays for it):
     * no marker, a marker from a different {@see INDEX_VERSION}, a durably
     * recorded failed sync, or a failure seen since the last sweep.
     */
    public function needsRebuild(): bool
    {
        return !$this->latched && (!$this->built || $this->dirty);
    }

    /**
     * The store calls this after a successful {@see clearAll()}-plus-sweep,
     * inside the sweep's last transaction: the marker lands atomically with
     * the index it vouches for. A sync failure the sweep swallowed is written
     * into the marker as `dirty = 1` instead of being cleared away — this
     * process answers from what it has (retrying a broken sweep on every
     * search is the 128 s freeze all over again), and the NEXT process reads
     * the durable flag and re-sweeps.
     */
    public function markBuilt(): void
    {
        if ($this->latched) {
            return;
        }

        $this->writeMarker($this->dirty);
        $this->built = true;
        $this->dirty = false;
    }

    /**
     * Upsert $bodies — message id => body text — as the session's CURRENT
     * indexed set: unchanged messages (hash equal) are untouched, changed
     * ones keep their rowid and replace their FTS row, vanished ones (a
     * rewind shortened the transcript) leave both tables. An empty body
     * indexes nothing, and a message id that never had a row simply gets one.
     *
     * Never throws: a failed sync records the index dirty DURABLY for the
     * next rebuild, because a search index is never worth failing a save over
     * — the same law that keeps {@see MemorySearchIndex::sync()} off the note
     * writer's error path.
     *
     * @param array<string, string> $bodies
     */
    public function syncMessages(string $sessionId, array $bodies): void
    {
        if ($this->latched) {
            return;
        }

        try {
            $observer = self::$syncObserver;
            if ($observer !== null) {
                $observer();
            }
            $this->syncMessagesInside($sessionId, $bodies);
        } catch (\Throwable) {
            $this->recordDirty();
        }
    }

    /**
     * Ranked body search: best-matching sessions first (bm25, lower is
     * better), each with a snippet of its best hit. Null means "this index
     * cannot answer" — latched, or a query with no indexable words — which
     * the caller reads as "fall back to LIKE"; an empty array is a real
     * answer meaning nothing matched.
     *
     * @return list<array{session_id: string, snippet: string}>|null
     */
    public function search(string $query): ?array
    {
        if ($this->latched) {
            return null;
        }
        $match = MemorySearchIndex::matchExpression($query);
        if ($match === null) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare(
                ' SELECT anchor.session_id AS session_id,'
                . ' snippet(session_messages_fts, 2, \'[\', \']\', \'…\', 12) AS snippet'
                . ' FROM session_messages_fts'
                . ' INNER JOIN session_message_rows AS anchor ON anchor.id = session_messages_fts.rowid'
                . ' WHERE session_messages_fts MATCH ?'
                . ' ORDER BY bm25(session_messages_fts) LIMIT ?'
            );
            $stmt->execute([$match, self::MESSAGE_CAP]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        } catch (\PDOException $error) {
            if (self::isLockContention($error)) {
                return null;
            }
            $this->dirty = true;

            return null;
        } catch (\Throwable) {
            $this->dirty = true;

            return null;
        }

        // bm25 refuses a subquery context, so the fold to one row per
        // session happens here: rows arrive best-hit-first, the first row a
        // session owns is its entry, and iteration order is the rank.
        $ranked = [];
        foreach ($rows as $row) {
            $sessionId = (string) $row['session_id'];
            if (isset($ranked[$sessionId])) {
                continue;
            }
            $ranked[$sessionId] = ['session_id' => $sessionId, 'snippet' => (string) $row['snippet']];
            if (count($ranked) >= self::SESSION_CAP) {
                break;
            }
        }

        return array_values($ranked);
    }

    /**
     * Drop every indexed row of $ids from BOTH tables. The FK cascade
     * already took `session_message_rows`; the FTS table is virtual and
     * un-cascaded, so this is where a deleted session's text actually goes,
     * and leaving it to the garbage pass alone would keep searchable bodies
     * of a session the user deleted.
     *
     * @param list<string> $ids
     */
    public function forgetSessions(array $ids): void
    {
        if ($this->latched || $ids === []) {
            return;
        }

        try {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $placeholders = $this->placeholders(count($chunk));
                foreach (['session_messages_fts', 'session_message_rows'] as $table) {
                    $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE session_id IN ({$placeholders})");
                    $stmt->execute(array_map('strval', $chunk));
                    $stmt->closeCursor();
                }
            }
        } catch (\Throwable) {
            $this->dirty = true;
        }
    }

    /**
     * Reclaim FTS rows whose anchor row is gone (cascade deletes reach the
     * anchor but never the virtual table). Space reclamation only — reads
     * inner-join the anchor, so nothing stale is searchable before this runs.
     */
    public function collectGarbage(): void
    {
        if ($this->latched) {
            return;
        }

        try {
            $this->pdo->exec('DELETE FROM session_messages_fts WHERE rowid NOT IN (SELECT id FROM session_message_rows)');
        } catch (\Throwable) {
            // A garbage pass that failed leaves no trustworthy build state
            // to advertise: the marker is cleared here, and on the in-sweep
            // path markBuilt re-writes it as dirty=1 in the same transaction
            // — either way, the next launch sweeps again. An un-reclaimed
            // table is merely fat; one this pass could not complete is not.
            $this->dirty = true;
            $this->clearMarkerRow();
        }
    }

    /** Empty both tables (the rebuild sweep starts from here). */
    public function clearAll(): void
    {
        if ($this->latched) {
            return;
        }

        $this->pdo->exec('DELETE FROM session_messages_fts');
        $this->pdo->exec('DELETE FROM session_message_rows');
        // An emptied table with a surviving built marker would tell the next
        // process its history is indexed when every row is gone.
        $this->clearMarkerRow();
    }

    /**
     * @param array<string, string> $bodies
     */
    private function syncMessagesInside(string $sessionId, array $bodies): void
    {
        $stmt = $this->pdo->prepare('SELECT message_id, id, hash FROM session_message_rows WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $existing = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[(string) $row['message_id']] = ['id' => (int) $row['id'], 'hash' => (string) $row['hash']];
        }
        $stmt->closeCursor();

        $wanted = [];
        foreach ($bodies as $messageId => $body) {
            $body = self::capBody((string) $body);
            if ($body === '') {
                continue;
            }
            $wanted[(string) $messageId] = $body;
        }

        $insert = $this->pdo->prepare('INSERT INTO session_message_rows (session_id, message_id, hash) VALUES (?, ?, ?)');
        $ftsInsert = $this->pdo->prepare('INSERT INTO session_messages_fts (rowid, session_id, message_id, body) VALUES (?, ?, ?, ?)');
        $reclaim = $this->pdo->prepare('DELETE FROM session_messages_fts WHERE rowid = ?');
        $raise = $this->pdo->prepare('UPDATE session_message_rows SET hash = ? WHERE id = ?');

        foreach ($wanted as $messageId => $body) {
            $hash = hash('sha256', $body);
            $current = $existing[$messageId] ?? null;
            if ($current !== null && $current['hash'] === $hash) {
                continue;
            }
            if ($current === null) {
                $insert->execute([$sessionId, $messageId, $hash]);
                $rowid = (int) $this->pdo->lastInsertId();
            } else {
                $reclaim->execute([$current['id']]);
                $raise->execute([$hash, $current['id']]);
                $rowid = $current['id'];
            }
            $ftsInsert->execute([$rowid, $sessionId, $messageId, $body]);
        }

        $dropped = array_diff_key($existing, $wanted);
        if ($dropped !== []) {
            foreach (array_chunk(array_values($dropped), self::CHUNK) as $chunk) {
                $ids = implode(',', array_map(static fn(array $row): int => (int) $row['id'], $chunk));
                $this->pdo->exec("DELETE FROM session_messages_fts WHERE rowid IN ({$ids})");
                $this->pdo->exec("DELETE FROM session_message_rows WHERE id IN ({$ids})");
            }
        }
    }

    /**
     * Both CREATEs of the side table. False means the latch; true means the
     * tables exist and are empty-or-populated on disk.
     *
     * `no such module` is the honest FTS5-absent answer (the same reading
     * {@see MemorySearchIndex::open()} makes). Any other throw — a locked or
     * corrupt database — latches too: this runs on a connection whose other
     * DDL already succeeded, so anything left is structural for the process,
     * and LIKE is always available.
     */
    private function createSchema(): bool
    {
        try {
            // FTS first: if the module is absent this throws BEFORE the
            // anchor table is made, so a latched database is left exactly as
            // the LIKE-only path would have left it — no orphan tables, and
            // "byte-unchanged" is true of the schema as well as the reads.
            $this->pdo->exec(
                'CREATE VIRTUAL TABLE IF NOT EXISTS session_messages_fts USING '
                . $this->module
                . " (session_id UNINDEXED, message_id UNINDEXED, body, tokenize = 'porter unicode61')"
            );
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS session_message_rows (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    session_id TEXT NOT NULL,
                    message_id TEXT NOT NULL,
                    hash TEXT NOT NULL,
                    UNIQUE (session_id, message_id),
                    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
                )
            ');
            // The build marker, third and last: a database that latched above
            // takes no new table at all, exactly as the LIKE-only path would
            // have left it. One row, rowid 1, rewritten by every markBuilt.
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS session_search_meta (
                    version INTEGER NOT NULL,
                    built_at TEXT NOT NULL,
                    dirty INTEGER NOT NULL
                )
            ');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Cut a body to {@see MAX_BODY_BYTES} without splitting a UTF-8 sequence:
     * the tokenizer and snippet both assume valid text, and a dangling
     * lead byte at the cap would corrupt the last token for a word nobody
     * will ever search.
     */
    private static function capBody(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY_BYTES) {
            return $body;
        }
        $cut = substr($body, 0, self::MAX_BODY_BYTES);
        for ($trim = 0; $trim < 4 && !mb_check_encoding($cut, 'UTF-8'); $trim++) {
            $cut = substr($cut, 0, -1);
        }

        return mb_check_encoding($cut, 'UTF-8') ? $cut : '';
    }

    /**
     * Adopt the on-disk build state. Only a marker for this exact
     * {@see INDEX_VERSION} with `dirty = 0` vouches for the tables; anything
     * else — absent (pre-C-SES-2 database or one that was just emptied),
     * mismatched, or a recorded failure — leaves this process asking for the
     * one sweep, which is what it did before the marker existed.
     */
    private function seedTrustFromMarker(): void
    {
        $marker = $this->readMarker();
        if ($marker === null || $marker['version'] !== self::INDEX_VERSION || $marker['dirty'] !== 0) {
            return;
        }

        $this->built = true;
        $this->dirty = false;
    }

    /**
     * @return array{version: int, dirty: int}|null
     */
    private function readMarker(): ?array
    {
        try {
            $row = $this->pdo->query('SELECT version, dirty FROM session_search_meta WHERE rowid = 1')
                ->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }

        return is_array($row)
            ? ['version' => (int) $row['version'], 'dirty' => (int) $row['dirty']]
            : null;
    }

    /**
     * Write the build state in the caller's transaction — for a successful
     * sweep that is the sweep's last chunk commit, so a reader can never see
     * "vouched" beside a half-swept table. A marker write that fails merely
     * buys the next process one more sweep.
     */
    private function writeMarker(bool $dirty): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT OR REPLACE INTO session_search_meta (rowid, version, built_at, dirty) VALUES (1, ?, ?, ?)'
            );
            $stmt->execute([self::INDEX_VERSION, gmdate('Y-m-d H:i:s'), $dirty ? 1 : 0]);
        } catch (\Throwable) {
            // Degrade-not-fail, same law as the index body: a marker the
            // database refused is a rebuild the next process pays for.
        }
    }

    /**
     * A failed sync is recorded durably, not just in this process: the next
     * launch reads `dirty = 1` and re-sweeps instead of answering from a
     * table this one knows is suspect. No marker row to flip means the
     * database was never (or is no longer) vouched — the absence already
     * asks for the sweep.
     */
    private function recordDirty(): void
    {
        $this->dirty = true;

        try {
            $stmt = $this->pdo->prepare('UPDATE session_search_meta SET dirty = 1 WHERE rowid = 1');
            $stmt->execute();
        } catch (\Throwable) {
            // The in-process flag stands on its own; losing only the durable
            // copy re-asks the next process the same question it must ask of
            // any database whose marker write once failed anyway.
        }
    }

    /** Drop the vouch (the sweep's markBuilt re-issues it atomically). */
    private function clearMarkerRow(): void
    {
        try {
            $this->pdo->exec('DELETE FROM session_search_meta WHERE rowid = 1');
        } catch (\Throwable) {
            // A marker this call could not delete is overwritten whole by the
            // next successful sweep's markBuilt — stale vouches never outrank
            // a rebuild actually completing.
        }
    }

    /**
     * SQLITE_BUSY (5) / SQLITE_LOCKED (6): "another writer is on it right
     * now" — a read the lock schedule refused, not a table gone wrong. The
     * caller's LIKE degrade answers this search; the index keeps its trust.
     */
    private static function isLockContention(\PDOException $error): bool
    {
        return in_array((int) ($error->errorInfo[1] ?? 0), [5, 6], true);
    }

    private function placeholders(int $count): string
    {
        $mark = \chr(63);

        return implode(',', array_fill(0, $count, $mark));
    }
}
