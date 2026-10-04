<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

use PDO;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Workspace\ShadowGarbageCollector;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * SQLite session persistence with enhanced metadata tracking and checkpointing.
 *
 * This class uses composition (wrapping) rather than inheritance to extend
 * SessionStore with columns for session summary, tasks, modified files,
 * agent states, and checkpoint snapshots to enable meaningful session
 * resumption, context replay, and /rewind functionality.
 *
 * Mirrors charmbracelet/charmbracelet enhanced session storage.
 */
final class EnhancedSessionStore
{
    private PDO $pdo;

    /** Wrapped session store for base session operations. */
    private SessionStore $sessionStore;

    public function __construct(private readonly string $dbPath)
    {
        /** @var \WeakMap<object, string> */
        $this->messageHashes = new \WeakMap();

        // Use umask 0077 before touching filesystem to ensure database files
        // are created with restrictive permissions (same as SessionStore).
        $previousUmask = umask(0077);
        try {
            // Create the base session store for fundamental session operations.
            // This initializes the base schema (sessions, messages, tool_calls).
            $this->sessionStore = new SessionStore($dbPath);

            // Share the same PDO connection for enhanced tables.
            // Both stores use the same SQLite database file.
            $this->pdo = $this->sessionStore->getPdo();

            $this->initEnhancedSchema();
        } finally {
            umask($previousUmask);
        }
    }

    /**
     * Take the single-writer lock on $sessionId (audit SES-3(b)), or null when
     * another TUI holds it. See {@see SessionLock} for what the lock is and why
     * a failure to lock opens the session writable rather than read-only.
     *
     * The lock files live in a `sessions/` directory beside this database, so
     * a launch on the default store locks under `~/.sugar-crush/sessions/` and
     * a store built on a scratch path keeps its locks in that scratch tree. An
     * in-memory database has nowhere to put one, and is unenforced.
     */
    public function lockSession(string $sessionId): ?SessionLock
    {
        return SessionLock::acquire($this->lockDirectory(), $sessionId);
    }

    /**
     * The pid recorded by whoever holds $sessionId's lock, for the read-only
     * notice; null when unknown. Informational only.
     */
    public function sessionLockHolder(string $sessionId): ?int
    {
        return SessionLock::holderPid($this->lockDirectory(), $sessionId);
    }

    private function lockDirectory(): ?string
    {
        if ($this->dbPath === '' || $this->dbPath === ':memory:' || str_starts_with($this->dbPath, 'file::memory:')) {
            return null;
        }

        return \dirname($this->dbPath) . '/' . SessionLock::DIRECTORY;
    }

    // =======================================================================
    // Delegation to SessionStore (base session operations)
    // =======================================================================

    public function createSession(
        string $id,
        string $provider,
        string $model,
        ?string $systemPrompt = null,
        ?string $name = null,
        ?string $cwd = null,
        ?string $gitBranch = null,
    ): void {
        $this->sessionStore->createSession($id, $provider, $model, $systemPrompt, $name, $cwd, $gitBranch);
    }

    /** @see SessionStore::createChildSession() */
    public function createChildSession(
        string $parentId,
        SessionKind $kind,
        ?string $agent,
        ?string $parentCallId,
        string $provider,
        string $model,
        ?string $name = null,
    ): string {
        return $this->sessionStore->createChildSession($parentId, $kind, $agent, $parentCallId, $provider, $model, $name);
    }

    public function getSession(string $id): ?array
    {
        return $this->sessionStore->getSession($id);
    }

    public function getSessionByName(string $name): ?array
    {
        return $this->sessionStore->getSessionByName($name);
    }

    /** @see SessionStore::renameSession() */
    public function renameSession(string $id, string $name, TitleSource $source = TitleSource::User): bool
    {
        return $this->sessionStore->renameSession($id, $name, $source);
    }

    /** @see SessionStore::renameSessionIfUnnamed() */
    public function renameSessionIfUnnamed(string $id, string $name): bool
    {
        return $this->sessionStore->renameSessionIfUnnamed($id, $name);
    }

    /** @see SessionStore::setPinned() */
    public function setPinned(string $id, bool $pinned): bool
    {
        return $this->sessionStore->setPinned($id, $pinned);
    }

    /** @see SessionStore::archive() */
    public function archive(string $id): bool
    {
        return $this->sessionStore->archive($id);
    }

    /** @see SessionStore::unarchive() */
    public function unarchive(string $id): bool
    {
        return $this->sessionStore->unarchive($id);
    }

    /** @see SessionStore::markSubAgentStatus() */
    public function markSubAgentStatus(string $id, string $status): bool
    {
        return $this->sessionStore->markSubAgentStatus($id, $status);
    }

    /** @see SessionStore::recordTurn() */
    public function recordTurn(string $id, string $prompt): bool
    {
        return $this->sessionStore->recordTurn($id, $prompt);
    }

    /**
     * Fork $id into a new session that carries its whole conversation:
     * transcript, checkpoints, the blobs both reference, and its meta.
     *
     * Delegating straight to {@see SessionStore::forkSession()} copied only
     * the legacy `messages`/`tool_calls` tables, which nothing writes any
     * more, so every fork started EMPTY (audit SES-2): `/fork` handed its
     * background session an id with no stored history, and `/rewind` on a
     * `/branch` said "No checkpoints available". It also made the first save
     * under the new id re-intern every message one INSERT at a time, the
     * multi-second `/branch` freeze of audit 15b-21; with the blobs copied
     * here that save finds every message already on disk.
     *
     * One transaction for the lot, so a fork is never visible half-copied.
     *
     * @see SessionStore::forkSession() for the parent link and $kind
     */
    public function forkSession(string $id, SessionKind $kind = SessionKind::Branch): string
    {
        try {
            $newId = $this->writeTransaction(function () use ($id, $kind): string {
                $newId = $this->sessionStore->forkSession($id, $kind);
                $this->copySessionState($id, $newId);

                return $newId;
            });
        } catch (\Throwable $e) {
            $this->discardWorkspaceRefOps();
            throw $e;
        }
        // The fork's checkpoints name refs of their own (see
        // copySessionState()); they are created once the rows are committed.
        $this->flushWorkspaceRefOps();

        return $newId;
    }

    /**
     * Copy every enhanced-table row of $fromId onto $toId.
     *
     * Blobs are keyed by session (`UNIQUE(session_id, hash)`), so the fork
     * gets its own copies under new ids — sharing the parent's rows would let
     * the parent's blob GC, or deleting the parent, delete messages the fork's
     * checkpoints still name. Every copied envelope is therefore rewritten to
     * the new ids; see {@see remapEnvelope()}.
     */
    private function copySessionState(string $fromId, string $toId): void
    {
        $this->pdo->prepare('
            INSERT INTO checkpoint_blobs (session_id, hash, payload)
            SELECT ?, hash, payload FROM checkpoint_blobs WHERE session_id = ? ORDER BY id
        ')->execute([$toId, $fromId]);

        $stmt = $this->pdo->prepare('
            SELECT o.id AS old_id, n.id AS new_id
            FROM checkpoint_blobs o
            JOIN checkpoint_blobs n ON n.session_id = ? AND n.hash = o.hash
            WHERE o.session_id = ?
        ');
        $stmt->execute([$toId, $fromId]);
        /** @var array<int, int> $idMap */
        $idMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $idMap[(int) $row['old_id']] = (int) $row['new_id'];
        }

        $stmt = $this->pdo->prepare('
            SELECT "index", state_data, created_at, undone FROM checkpoints WHERE session_id = ? ORDER BY "index" ASC
        ');
        $stmt->execute([$fromId]);
        // The redo stack comes along (item 3.A-2): a branch taken right after
        // a rewind can still /redo, from its own copies of the rows.
        $insert = $this->pdo->prepare('
            INSERT INTO checkpoints (session_id, "index", state_data, created_at, undone) VALUES (?, ?, ?, ?, ?)
        ');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stateData = $this->remapEnvelope((string) $row['state_data'], $idMap);
            // A workspace snapshot is pinned per session (item 3.A-1): the
            // branch gets its own ref to the same commit, so deleting or
            // rewinding either session never unpins the other's files.
            $workspace = $this->workspaceOf($stateData);
            if (WorkspaceCheckpointer::isCaptured($workspace)) {
                $ref = WorkspaceCheckpointer::refFor($toId, (int) $row['index']);
                $this->pendingRefCopies[] = [$workspace, $ref];
                $stateData = $this->withWorkspace($stateData, ['ref' => $ref] + $workspace);
            }
            // Index and created_at are kept: the branch's `/rewind` steps back
            // through the same turns, taken when they were taken.
            $insert->execute([
                $toId,
                (int) $row['index'],
                $stateData,
                $row['created_at'],
                (int) $row['undone'],
            ]);
        }

        $stmt = $this->pdo->prepare('SELECT state_data FROM session_transcripts WHERE session_id = ?');
        $stmt->execute([$fromId]);
        $transcript = $stmt->fetchColumn();
        $stmt->closeCursor();
        if (\is_string($transcript)) {
            $this->pdo->prepare('
                INSERT INTO session_transcripts (session_id, state_data, updated_at) VALUES (?, ?, ?)
            ')->execute([$toId, $this->remapEnvelope($transcript, $idMap), gmdate('Y-m-d H:i:s')]);
        }

        // last_activity is the fork's, not the parent's: it was used just now.
        $this->pdo->prepare('
            INSERT INTO session_meta (session_id, summary, tasks, modified_files, agent_states, last_activity)
            SELECT ?, summary, tasks, modified_files, agent_states, ? FROM session_meta WHERE session_id = ?
        ')->execute([$toId, gmdate('Y-m-d H:i:s'), $fromId]);
    }

    /**
     * $stateData with its envelope's blob ids translated through $idMap.
     *
     * Decoded to `stdClass`, not to arrays, so an empty JSON object in the
     * saved state re-encodes as `{}` rather than collapsing to `[]`; with the
     * same flags {@see encodeJson()} wrote it with, every byte outside the id
     * list round-trips unchanged. An inline (pre-envelope) row has no ids and
     * is copied verbatim. An id with no row in the parent — an already
     * unreadable checkpoint — maps to 0, which stays unreadable in the fork
     * instead of borrowing a row that is not the fork's.
     *
     * @param array<int, int> $idMap parent blob id => fork blob id
     */
    private function remapEnvelope(string $stateData, array $idMap): string
    {
        $decoded = json_decode($stateData, false);
        if (
            !$decoded instanceof \stdClass
            || ($decoded->{self::CHECKPOINT_ENVELOPE_VERSION} ?? null) !== 1
            || !\is_array($decoded->{self::CHECKPOINT_ENVELOPE_MESSAGES} ?? null)
        ) {
            return $stateData;
        }

        $decoded->{self::CHECKPOINT_ENVELOPE_MESSAGES} = array_map(
            static fn (mixed $id): int => $idMap[(int) $id] ?? 0,
            $decoded->{self::CHECKPOINT_ENVELOPE_MESSAGES},
        );

        return self::encodeJson($decoded);
    }

    /**
     * {@see SessionStore::immediateTransaction()}, plus the one piece of
     * in-memory state a rollback has to undo: {@see internMessages()} records
     * blob ids in {@see $blobIds} as it inserts them, and a rolled-back
     * insert would leave those ids cached with no row behind them — the next
     * save would then write an envelope naming blobs that do not exist.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function writeTransaction(\Closure $work): mixed
    {
        try {
            return $this->sessionStore->immediateTransaction($work);
        } catch (\Throwable $e) {
            $this->blobIds = [];
            throw $e;
        }
    }

    public function updateSession(string $id): void
    {
        $this->sessionStore->updateSession($id);
    }

    /**
     * @see SessionStore::deleteSession() for what happens to children
     *
     * @return list<string> the deleted ids, $id first
     */
    public function deleteSession(string $id, bool $withChildren = false): array
    {
        // Read before the delete: the FK cascade takes the checkpoint rows,
        // and with them the only record of which refs pin their snapshots.
        $workspaces = $this->workspaceRefsBySession();
        $deleted = $this->sessionStore->deleteSession($id, $withChildren);
        $dropped = [];
        foreach ($deleted as $deletedId) {
            array_push($dropped, ...($workspaces[$deletedId] ?? []));
        }
        array_push($this->pendingRefDrops, ...$dropped);
        $this->flushWorkspaceRefOps();
        self::collectShadows($dropped);
        // The FK cascade took these sessions' checkpoint_blobs rows with them,
        // so every id this instance had interned for them is now dangling.
        // See internMessages(): ANY blob deletion has to invalidate the cache,
        // not just the GC's own.
        foreach ($deleted as $deletedId) {
            $this->forgetInternedBlobs($deletedId);
        }

        return $deleted;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(int $limit = 20): array
    {
        return $this->sessionStore->listSessions($limit);
    }

    public function addMessage(string $sessionId, array $message): int
    {
        return $this->sessionStore->addMessage($sessionId, $message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(string $sessionId): array
    {
        return $this->sessionStore->getMessages($sessionId);
    }

    public function addToolCall(string $sessionId, int $messageId, array $toolCall): void
    {
        $this->sessionStore->addToolCall($sessionId, $messageId, $toolCall);
    }

    public function pruneSessions(int $daysOld = 30, ?string $exemptSessionId = null): int
    {
        $workspaces = $this->workspaceRefsBySession();
        $pruned = $this->sessionStore->pruneSessions($daysOld, $exemptSessionId);
        if ($pruned > 0) {
            // Same cascade as deleteSession(), for a set of ids the caller
            // never named.
            $this->blobIds = [];
            // The same goes for the refs pinning the pruned sessions'
            // workspace snapshots: whichever sessions are gone now drop theirs.
            if ($workspaces !== []) {
                $placeholders = implode(',', array_fill(0, \count($workspaces), '?'));
                $stmt = $this->pdo->prepare("SELECT id FROM sessions WHERE id IN ({$placeholders})");
                $stmt->execute(array_map('strval', array_keys($workspaces)));
                $alive = array_flip(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
                $dropped = [];
                foreach ($workspaces as $sessionId => $refs) {
                    if (!isset($alive[(string) $sessionId])) {
                        array_push($dropped, ...$refs);
                    }
                }
                array_push($this->pendingRefDrops, ...$dropped);
                $this->flushWorkspaceRefOps();
                self::collectShadows($dropped);
            }
        }

        return $pruned;
    }

    /**
     * @return array<int, array{id: string, name: ?string, updated_at: string, messages: int}>
     */
    public function pruneReport(): array
    {
        return $this->sessionStore->pruneReport();
    }

    private function initEnhancedSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS session_meta (
                session_id TEXT PRIMARY KEY,
                summary TEXT DEFAULT \'\',
                tasks TEXT DEFAULT \'[]\',
                modified_files TEXT DEFAULT \'[]\',
                agent_states TEXT DEFAULT \'[]\',
                last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        ');

        // Index for efficient last_activity queries (session index sorted by recency)
        $this->pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_session_meta_last_activity
            ON session_meta(last_activity DESC)
        ');

        // Checkpoints table for /rewind functionality
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS checkpoints (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                "index" INTEGER NOT NULL,
                state_data TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                undone INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        ');

        // The redo stack (item 3.A-2): a rewind marks rows instead of
        // deleting them — see restoreCheckpoint(). An older database gains
        // the column the next time it is opened; every existing row is live.
        $checkpointColumns = array_column(
            $this->pdo->query('PRAGMA table_info(checkpoints)')->fetchAll(PDO::FETCH_ASSOC),
            'name',
        );
        if (!\in_array('undone', $checkpointColumns, true)) {
            $this->pdo->exec('ALTER TABLE checkpoints ADD COLUMN undone INTEGER NOT NULL DEFAULT 0');
        }

        $this->migrateCheckpointIndexUnique();

        // Content-addressed message bodies shared by every checkpoint of a
        // session — see saveCheckpoint() for why checkpoints stopped storing
        // the conversation inline. Created here rather than in a one-shot
        // migration because initEnhancedSchema() runs on every construction,
        // so an existing database picks the table up the next time it opens
        // and keeps reading its old inline checkpoints meanwhile.
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS checkpoint_blobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                hash TEXT NOT NULL,
                payload TEXT NOT NULL,
                UNIQUE (session_id, hash),
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        ');

        // The conversation as it stands NOW, one row per session, rewritten
        // whenever the transcript changes — what a resume loads. Checkpoints
        // cannot stand in for it: they are taken as a turn is SENT, so the
        // newest one never holds the reply that turn produced. Messages are
        // interned into checkpoint_blobs exactly as a checkpoint's are, so a
        // save only writes what is new.
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS session_transcripts (
                session_id TEXT PRIMARY KEY,
                state_data TEXT NOT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        ');

        // The durable per-session event log (roadmap O-2b, Appendix O §4.8 /
        // §6.4): every event a host broadcasts as durable is written here
        // first, under a `seq` that is monotonic per session and never reused,
        // so a client that reconnects asks for "everything after seq N" and
        // gets exactly what it missed — across a server restart too, since
        // the next seq is read back from MAX(seq). The primary key IS the
        // replay index (`WHERE session_id = ? AND seq > ?`), so no second
        // index is kept. The FK cascade means a deleted or pruned session
        // takes its events with it, exactly as it takes its checkpoints.
        // Created here because this runs on every construction, so an older
        // database gains the table the next time it is opened.
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS session_events (
                session_id TEXT NOT NULL,
                seq INTEGER NOT NULL,
                ts INTEGER NOT NULL,
                type TEXT NOT NULL,
                payload TEXT NOT NULL,
                PRIMARY KEY (session_id, seq),
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            ) WITHOUT ROWID
        ');
    }

    /**
     * Make `(session_id, "index")` unique on checkpoints, repairing any
     * duplicates an existing database already holds. Idempotent: it runs on
     * every construction and does nothing once the index exists.
     *
     * Index allocation was `SELECT MAX("index")+1` then `INSERT` with nothing
     * tying the two together, so two processes saving into one session could
     * both take the same index (audit SES-3). With duplicates,
     * `getCheckpoint()` returned whichever row SQLite met first and
     * `restoreCheckpoint()`'s `"index" >= ?` deleted both. The allocation is
     * now inside `BEGIN IMMEDIATE` ({@see saveCheckpoint()}); this index is
     * the schema-level guarantee behind it.
     *
     * Duplicates are renumbered, not deleted — each is a real snapshot of
     * some turn. Within a session rows are walked in `("index", id)` order and
     * each takes `max(its index, previous + 1)`, which keeps every index that
     * did not collide and shifts only colliding rows and the ones after them.
     * `/rewind` addresses checkpoints by their order (N steps back), never by
     * an absolute index, so the shift is invisible to it.
     *
     * The unique index replaces the old non-unique
     * `idx_checkpoints_session_index (session_id, "index" DESC)`: it covers
     * the same columns, SQLite scans it backwards for the DESC lookups, and
     * keeping both would maintain two identical B-trees on every insert.
     */
    private function migrateCheckpointIndexUnique(): void
    {
        $exists = $this->pdo->query("
            SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = 'idx_checkpoints_session_index_unique'
        ");
        $done = $exists->fetchColumn() !== false;
        $exists->closeCursor();
        if ($done) {
            return;
        }

        $this->sessionStore->immediateTransaction(function (): void {
            $dupes = $this->pdo->query('
                SELECT DISTINCT session_id FROM (
                    SELECT session_id FROM checkpoints GROUP BY session_id, "index" HAVING COUNT(*) > 1
                )
            ')->fetchAll(PDO::FETCH_COLUMN);

            $rows = $this->pdo->prepare('
                SELECT id, "index" FROM checkpoints WHERE session_id = ? ORDER BY "index" ASC, id ASC
            ');
            $renumber = $this->pdo->prepare('UPDATE checkpoints SET "index" = ? WHERE id = ?');
            foreach ($dupes as $sessionId) {
                $rows->execute([(string) $sessionId]);
                $prev = null;
                foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $index = $prev === null ? (int) $row['index'] : max((int) $row['index'], $prev + 1);
                    if ($index !== (int) $row['index']) {
                        $renumber->execute([$index, (int) $row['id']]);
                    }
                    $prev = $index;
                }
            }

            $this->pdo->exec('
                CREATE UNIQUE INDEX IF NOT EXISTS idx_checkpoints_session_index_unique
                ON checkpoints(session_id, "index")
            ');
            $this->pdo->exec('DROP INDEX IF EXISTS idx_checkpoints_session_index');
        });
    }

    /**
     * Get enhanced metadata for a session.
     */
    public function getSessionMeta(string $sessionId): ?SessionMeta
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM session_meta WHERE session_id = ?
        ');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new SessionMeta(
            sessionId: $row['session_id'],
            summary: $row['summary'],
            tasks: json_decode($row['tasks'], true) ?: [],
            modifiedFiles: json_decode($row['modified_files'], true) ?: [],
            agentStates: json_decode($row['agent_states'], true) ?: [],
            // Stored as UTC (see saveSessionMeta()); read back as UTC and
            // shown in the process's zone, so the instant survives the trip.
            lastActivity: (new \DateTimeImmutable((string) $row['last_activity'], new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get())),
        );
    }

    /**
     * Save enhanced metadata for a session.
     *
     * `last_activity` is written in UTC whatever zone the caller's DateTime
     * carries. It used to be formatted in that zone, while the column's
     * DEFAULT and every other timestamp in the database is UTC, so
     * {@see listSessionsWithMeta()}'s `COALESCE(last_activity, updated_at)`
     * ordered rows from two clocks an offset apart (audit SES-5).
     */
    public function saveSessionMeta(SessionMeta $meta): void
    {
        $stmt = $this->pdo->prepare('
            INSERT OR REPLACE INTO session_meta
                (session_id, summary, tasks, modified_files, agent_states, last_activity)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $meta->sessionId,
            $meta->summary,
            json_encode($meta->tasks),
            json_encode($meta->modifiedFiles),
            json_encode($meta->agentStates),
            $meta->lastActivity->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @see SessionStore::listSessionsFiltered()
     *
     * @return list<SessionRow>
     */
    public function listSessionsFiltered(SessionQuery $query): array
    {
        return $this->sessionStore->listSessionsFiltered($query);
    }

    /**
     * @see SessionStore::childrenOf()
     *
     * @return list<SessionRow>
     */
    public function childrenOf(string $id): array
    {
        return $this->sessionStore->childrenOf($id);
    }

    /**
     * @see SessionStore::childCount()
     *
     * @param list<string> $ids
     *
     * @return array<string, int>
     */
    public function childCount(array $ids): array
    {
        return $this->sessionStore->childCount($ids);
    }

    /**
     * List sessions with their enhanced metadata, ordered by last activity.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSessionsWithMeta(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('
            SELECT s.*, sm.summary, sm.tasks, sm.modified_files, sm.agent_states, sm.last_activity
            FROM sessions s
            LEFT JOIN session_meta sm ON s.id = sm.session_id
            ORDER BY COALESCE(sm.last_activity, s.updated_at) DESC
            LIMIT ?
        ');
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            $row['tasks'] = $row['tasks'] ? json_decode($row['tasks'], true) : [];
            $row['modified_files'] = $row['modified_files'] ? json_decode($row['modified_files'], true) : [];
            $row['agent_states'] = $row['agent_states'] ? json_decode($row['agent_states'], true) : [];
            return $row;
        }, $rows);
    }

    // =======================================================================
    // Checkpoint management (for /rewind functionality)
    // =======================================================================

    private const MAX_CHECKPOINTS_PER_SESSION = 100;

    /**
     * Checkpoint-state key holding the workspace snapshot taken for that turn
     * (item 3.A-1): a {@see WorkspaceCheckpointer} outcome — the pinned
     * commit and the ref pinning it, or why none was taken. Its ref follows
     * its row: pruned and deleted rows drop theirs, a row a rewind sets aside
     * keeps its own until the redo stack is discarded, and a `/branch` copy
     * gets one of its own.
     */
    public const CHECKPOINT_WORKSPACE_KEY = 'workspaceRef';

    /** Directory beside the database that holds shadow repositories. */
    public const SHADOW_DIRECTORY = 'checkpoints';

    /**
     * `checkpoints.undone` (item 3.A-2). A rewind does not delete the rows it
     * steps back over: it marks them UNDONE, which puts them on the redo
     * stack, and — the first time, when there is no stack yet — records the
     * state it left as one more row marked REDO_TIP above them all. Every
     * reader but the redo surface sees LIVE rows only, so to `/rewind`,
     * `listCheckpoints()` and a resume a marked row is gone exactly as a
     * deleted one was. The next checkpoint saved deletes them for real.
     */
    private const CHECKPOINT_LIVE = 0;
    private const CHECKPOINT_UNDONE = 1;
    private const CHECKPOINT_REDO_TIP = 2;

    /** @var array<string, true> root + reason pairs already announced */
    private static array $announcedMissingSnapshots = [];

    /**
     * Snapshot refs of rows the current write deleted, dropped once it
     * commits — never inside the write lock, and never for a rolled-back one.
     *
     * @var list<array<string, mixed>>
     */
    private array $pendingRefDrops = [];

    /**
     * `[snapshot, new ref]` pairs a `/branch` copy needs pinned once it commits.
     *
     * @var list<array{0: array<string, mixed>, 1: string}>
     */
    private array $pendingRefCopies = [];

    /**
     * Envelope key marking a `state_data` payload as message-referencing
     * rather than a full inline snapshot. Its presence is what distinguishes
     * the two on read; rows written before this existed have no such key and
     * are still decoded verbatim.
     */
    private const CHECKPOINT_ENVELOPE_VERSION = '__cpv';

    /** Envelope key holding the ordered list of `checkpoint_blobs.id`. */
    private const CHECKPOINT_ENVELOPE_MESSAGES = '__cpm';

    /** Envelope key holding the rest of the chat state, `messages` nulled. */
    private const CHECKPOINT_ENVELOPE_STATE = '__cps';

    /**
     * Interned message bodies, `sessionId => [sha256 => checkpoint_blobs.id]`.
     *
     * Keeps the steady-state cost of {@see saveCheckpoint()} proportional to
     * the messages the turn actually ADDED rather than to the whole history:
     * every message already checkpointed is a cache hit and issues no query
     * at all. Cold on the first save of a process, which costs one batched
     * SELECT.
     *
     * Bounded to {@see MAX_CACHED_SESSIONS} sessions, least-recently-interned
     * evicted first. A single session's map grows with the messages this
     * PROCESS has checkpointed (~80 B each) and is released when the session
     * falls out of the window — nothing else releases it, because a store
     * instance is never told a session is finished.
     *
     * @var array<string, array<string, int>>
     */
    private array $blobIds = [];

    /**
     * Value of `PRAGMA data_version` when {@see $blobIds} was last known to
     * match the database. See {@see forgetInternedBlobsIfStale()}.
     */
    private ?string $blobDataVersion = null;

    /**
     * Content hash of every message object this process has already encoded,
     * keyed by the object itself.
     *
     * ## What this buys, measured
     *
     * {@see $blobIds} took the DISK cost of a checkpoint down to the messages
     * the turn actually added — `INSERT OR IGNORE` writes only new blobs. It
     * did nothing for the CPU cost, because {@see internMessages()} still had
     * to `json_encode()` and `sha256` EVERY message in the history to work out
     * which ones those were. That is O(N) work per turn and O(N²) per session,
     * paid synchronously inside `Chat::submit()` — i.e. between the user
     * pressing Enter and the request going out.
     *
     * On a 400-turn history of 32 KB messages (an ordinary size once tool
     * results are in the transcript) that measured 14 ms of `json_encode` plus
     * 38 ms of `sha256` — 52 ms of dead time on every prompt, ~10 s over the
     * session. A hit here costs 25 µs for the same 400 messages.
     *
     * ⚠️ MEASURE THE ENCODE THE WAY THE LOOP DOES IT. A review re-measured
     * this and reported the encode half as 7.5 ms, i.e. half what is recorded
     * above. That benchmark encoded and threw the payload away;
     * {@see internMessages()} KEEPS it (`$fresh[$hash] = $payload`), so it can
     * insert the bytes for a missing blob without a second encode. Retaining
     * 400 × 32 KB is the other ~7 ms, and it is real work this path performs.
     * Re-measured three ways on the same box: encode-and-discard 7.40 ms,
     * encode-and-retain 14.38 ms, and the faithful
     * encode+`sha256`+retain loop 49.72 ms against `sha256` alone at
     * 37.89 ms — 11.8 ms of encode attributable inside the real loop. The
     * figures above stand; do not "correct" them to the discarded-payload
     * number.
     *
     * ## Why object identity is a sound key
     *
     * {@see Message} is `final` and every property is `readonly`, as is every
     * type reachable from one: `Attachment` and `Usage` are `readonly class`,
     * `ToolCall` and `ToolResult` declare every property `readonly`. So a
     * given instance's JSON encoding is fixed for its lifetime and this map
     * can never go stale:
     *
     *   - EDITING a message produces a DIFFERENT instance — `with*()`
     *     constructs a new `Message` — which misses the memo and is encoded.
     *   - REWINDING replaces the history list wholesale; the instances that
     *     fall out take their entries with them, because a `WeakMap` holds its
     *     keys weakly.
     *   - Anything that is NOT a `Message` — a plain array, or an arbitrary
     *     object an embedder checkpoints — is not memoised; it takes the
     *     encode path every time, exactly as before.
     *
     * THE GUARD MUST MATCH THE PROOF, and for one revision it did not: the
     * memo was written under `is_object()` while the argument above is about
     * `Message`. Every clause of the proof is a claim about THAT type, and
     * nothing constrains an object an embedder hands to
     * {@see saveCheckpoint()} — `EnhancedSessionStore` is a public seam, and
     * `Chat` merely happens to pass `list<Message>`. A mutable object was
     * therefore pinned to its first-seen encoding for the rest of the
     * process: checkpoint, mutate, checkpoint again, and the second
     * checkpoint restored the FIRST value, silently. That is a corruption the
     * pre-memo code could not produce. `messageFingerprint()` now tests
     * `instanceof Message`, and `CheckpointStorageTest` pins it with a
     * mutable object whose two checkpoints must differ.
     *
     * ## Why the HASH and not the payload
     *
     * Caching the encoded payload would cut the remaining encode on the cold
     * path too, at the price of holding a second full copy of the history in
     * memory. The hash is 64 bytes and is all the steady-state path needs:
     * a hash already in {@see $blobIds} never needs its payload again. The
     * rare hash that is NOT (first save of a process, or another connection
     * bumped `data_version`) re-encodes lazily, at which point it was going to
     * pay for the round trip anyway.
     *
     * @var \WeakMap<object, string>
     */
    private \WeakMap $messageHashes;

    /**
     * How many sessions {@see $blobIds} keeps maps for.
     *
     * One live conversation plus room for the sessions `/branch`, `/session`
     * and background runs move between inside one process.
     */
    private const MAX_CACHED_SESSIONS = 4;

    /**
     * Save a checkpoint snapshot for the session.
     *
     * Checkpoints are written once per turn by {@see
     * \SugarCraft\Crush\Chat::submit()} and read back only by `/rewind`, so
     * the feature's contract is "every turn is individually rewindable, up to
     * MAX_CHECKPOINTS_PER_SESSION turns back". That contract is preserved
     * exactly — what changed is where the bytes go.
     *
     * This used to `json_encode($chatState)` in full, meaning turn N wrote
     * the whole N-message history again: O(N²) bytes over a session, and the
     * single largest write amplifier in the store. Messages are now stored
     * once each in `checkpoint_blobs`, addressed by the SHA-256 of their
     * encoded form, and the checkpoint row keeps only the ordered list of
     * blob ids. A turn therefore writes its new messages plus a short id
     * list, so total message BYTES over a session are O(N).
     *
     * The envelope is not: its id list is itself O(N) per checkpoint, so total
     * bytes written stay Θ(N²) with a much smaller constant — a few bytes of
     * decimal id per message instead of the message. Measured on distinct
     * 200-byte bodies that is 18× fewer bytes written at 50 turns and 46× at
     * 400, with the envelope 77% of the total by then; the factor moves with
     * both turn count and message size.
     * Removing that last quadratic term needs a checkpoint format that can
     * reference a RANGE of blobs, which is a schema change and a separate
     * piece of work.
     *
     * Content addressing rather than a delta chain against the previous
     * checkpoint, for two reasons. First, history is not strictly
     * append-only: a `tool_running` placeholder is REPLACED in place when its
     * result lands, and `/compact` rewrites history wholesale — a
     * prefix-delta would degrade to a full snapshot on exactly the turns that
     * matter most, while content addressing just reuses the messages that did
     * not change. Second, a chain makes each checkpoint depend on its
     * predecessor, and `pruneOldCheckpoints()` deletes oldest-first, so every
     * prune would have to re-materialise the new oldest checkpoint as a full
     * snapshot — reintroducing O(N²) past the retention limit and giving one
     * corrupt row a blast radius over every checkpoint above it. Here each
     * checkpoint row is independent: it names blobs, and blobs never change.
     *
     * A throttle ("checkpoint every K turns") was rejected outright: it is
     * the one change that would silently alter what `/rewind 1` means, and
     * losing up to K turns of undo is a worse trade than a slightly larger
     * schema when an O(N) representation is available for the same
     * guarantees. That rejection still stands, and it is worth being precise
     * about what it did and did not buy: content addressing made the WRITE
     * O(N)-total, but the work of DECIDING what to write — encoding and
     * hashing every message in the history — stayed O(N) per turn and so
     * O(N²) per session, and it is paid inside `update()`. {@see
     * $messageHashes} is what makes that half proportional to the turn as
     * well, again without touching what a checkpoint means.
     *
     * @param string $sessionId The session ID
     * @param array $chatState The chat state to snapshot (messages, input buffer, agent context)
     * @return int The checkpoint index that was assigned
     *
     * @throws \JsonException on a state that cannot be encoded — loudly, so a
     *         checkpoint is skipped rather than stored with a hole in it
     */
    public function saveCheckpoint(string $sessionId, array $chatState): int
    {
        // Allocation, insert and prune in one IMMEDIATE transaction: the
        // write lock is held from the MAX() read to the INSERT, so a second
        // writer cannot take the same index (audit SES-3), and the blob
        // interning inside costs one commit, not one per message (15b-21).
        try {
            $index = $this->writeTransaction(fn (): int => $this->insertCheckpoint($sessionId, $chatState));
        } catch (\Throwable $e) {
            $this->discardWorkspaceRefOps();
            throw $e;
        }
        // Refs of checkpoints the prune just deleted go only once that delete
        // is committed, and outside the write lock.
        $this->flushWorkspaceRefOps();

        return $index;
    }

    /**
     * A {@see WorkspaceCheckpointer} for the project at $root whose shadow
     * repositories (non-git projects) live beside this database, in
     * {@see SHADOW_DIRECTORY} — the same place-follows-the-store rule the lock
     * files follow. An in-memory database keeps none, so a non-git project is
     * refused there.
     */
    public function workspaceCheckpointer(string $root): WorkspaceCheckpointer
    {
        $shadow = $this->lockDirectory() === null ? null : \dirname($this->dbPath) . '/' . self::SHADOW_DIRECTORY;

        return WorkspaceCheckpointer::new($root)->withShadowBase($shadow);
    }

    /**
     * Snapshot the files of the project at $root for checkpoint $index of
     * $sessionId and record the outcome in that checkpoint row (item 3.A-1).
     *
     * Called from the turn's Cmd, after {@see saveCheckpoint()} wrote the row
     * and before the turn starts writing files. A row that is gone by then
     * (rewound or pruned in between) keeps no snapshot, so the ref just
     * created is dropped again rather than leaked.
     *
     * @return array<string, mixed> the stored outcome
     */
    public function captureWorkspace(string $sessionId, int $index, string $root): array
    {
        $workspace = $this->workspaceCheckpointer($root)->capture($sessionId, $index);
        if (!$this->attachWorkspaceRef($sessionId, $index, $workspace)) {
            WorkspaceCheckpointer::dropRefs([$workspace]);
        }

        if (!WorkspaceCheckpointer::isCaptured($workspace)) {
            self::announceMissingSnapshot($root, $workspace);
        } elseif (($workspace['kind'] ?? null) === 'shadow') {
            // A shadow's dropped snapshots are never collected by anything
            // else; see ShadowGarbageCollector for how this stays bounded.
            ShadowGarbageCollector::collectIfDue((string) $workspace['gitDir']);
        }

        return $workspace;
    }

    /**
     * Tell the user, once per directory and reason for the life of the
     * process, that the files are not being snapshotted — the W2 hand-off
     * from 3.A-1: the reason was stored in the row, but nobody learned it
     * until a `/rewind --files` came back empty-handed. Once, because a
     * refusal (the home directory, a non-git project with no shadow store)
     * repeats on every turn, and every transcript row is re-sent to the
     * model on every later turn.
     *
     * @param array<string, mixed> $workspace a refused or failed outcome
     */
    private static function announceMissingSnapshot(string $root, array $workspace): void
    {
        $reason = \is_string($workspace['reason'] ?? null) ? $workspace['reason'] : 'no reason was given';
        $key = $root . "\0" . $reason;
        if (isset(self::$announcedMissingSnapshots[$key])) {
            return;
        }
        self::$announcedMissingSnapshots[$key] = true;

        $what = ($workspace['status'] ?? null) === WorkspaceCheckpointer::STATUS_FAILED
            ? 'A file snapshot failed'
            : 'Files are not being snapshotted';
        RuntimeNoticeSink::warn(sprintf(
            '%s in %s: %s. /rewind and /undo can bring back the conversation here, not the files.',
            $what,
            $root,
            $reason,
        ));
    }

    /** Forget which missing-snapshot notices this process has shown. For tests. */
    public static function forgetAnnouncedSnapshots(): void
    {
        self::$announcedMissingSnapshots = [];
    }

    /**
     * Collect the shadow repositories behind $workspaces whose refs were just
     * dropped — at most once a day each, see {@see ShadowGarbageCollector}.
     *
     * @param list<array<string, mixed>> $workspaces
     */
    private static function collectShadows(array $workspaces): void
    {
        $gitDirs = [];
        foreach ($workspaces as $workspace) {
            if (($workspace['kind'] ?? null) === 'shadow' && \is_string($workspace['gitDir'] ?? null)) {
                $gitDirs[$workspace['gitDir']] = true;
            }
        }
        foreach (array_keys($gitDirs) as $gitDir) {
            ShadowGarbageCollector::collectIfDue($gitDir);
        }
    }

    /**
     * Store $workspace under {@see CHECKPOINT_WORKSPACE_KEY} in checkpoint
     * $index of $sessionId; false when there is no such row.
     *
     * The row is rewritten through `stdClass`, as {@see remapEnvelope()} does,
     * so every byte outside the one key round-trips unchanged.
     *
     * @param array<string, mixed> $workspace
     */
    public function attachWorkspaceRef(string $sessionId, int $index, array $workspace): bool
    {
        return $this->writeTransaction(function () use ($sessionId, $index, $workspace): bool {
            $stmt = $this->pdo->prepare('SELECT id, state_data FROM checkpoints WHERE session_id = ? AND "index" = ?');
            $stmt->execute([$sessionId, $index]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if (!\is_array($row)) {
                return false;
            }

            $this->pdo->prepare('UPDATE checkpoints SET state_data = ? WHERE id = ?')->execute([
                $this->withWorkspace((string) $row['state_data'], $workspace),
                (int) $row['id'],
            ]);

            return true;
        });
    }

    /**
     * @param array<string, mixed> $chatState
     *
     * @throws \JsonException
     */
    private function insertCheckpoint(string $sessionId, array $chatState): int
    {
        // A new turn ends the redo stack (item 3.A-2), as in every editor:
        // the states a rewind set aside can no longer be stepped back to once
        // the conversation has moved on from somewhere else. Their rows go,
        // and with them their refs and the message bodies only they named.
        $this->discardRedoStackRows($sessionId);

        $nextIndex = $this->insertCheckpointRow($sessionId, $chatState, self::CHECKPOINT_LIVE);

        // Enforce the 100 checkpoint limit: delete oldest checkpoints if over limit
        $this->pruneOldCheckpoints($sessionId, self::MAX_CHECKPOINTS_PER_SESSION);

        return $nextIndex;
    }

    /**
     * Insert $chatState as the session's next checkpoint index, with
     * `undone` = $undone, and return that index.
     *
     * @param array<string, mixed> $chatState
     *
     * @throws \JsonException
     */
    private function insertCheckpointRow(string $sessionId, array $chatState, int $undone): int
    {
        // Get the next index for this session
        $stmt = $this->pdo->prepare('
            SELECT COALESCE(MAX("index"), -1) + 1 FROM checkpoints WHERE session_id = ?
        ');
        $stmt->execute([$sessionId]);
        $nextIndex = (int) $stmt->fetchColumn();
        // fetchColumn() leaves the cursor open, which holds a read transaction
        // for as long as the statement lives. SQLite will not run its
        // automatic WAL checkpoint at a COMMIT taken while a reader is open,
        // so leaving this cursor across the INSERT below meant the WAL never
        // truncated and `session.db-wal` grew without bound for the life of
        // the process — the "files reaching hundreds of MB" symptom this whole
        // item is about. One turn per line, and every line held one open.
        $stmt->closeCursor();

        // Insert the new checkpoint
        $insertStmt = $this->pdo->prepare('
            INSERT INTO checkpoints (session_id, "index", state_data, created_at, undone)
            VALUES (?, ?, ?, ?, ?)
        ');
        $insertStmt->execute([
            $sessionId,
            $nextIndex,
            $this->encodeCheckpoint($sessionId, $chatState),
            // UTC like every other timestamp in the database; this was the
            // process's local time (audit SES-5).
            gmdate('Y-m-d H:i:s'),
            $undone,
        ]);

        return $nextIndex;
    }

    /**
     * Serialise one chat state into a `state_data` payload.
     *
     * A state with no `messages` list has nothing to intern and is stored
     * inline exactly as before — the envelope is only worth its constant
     * overhead when there is a history to share.
     *
     * `messages` is nulled in place rather than removed from the state array
     * so the key keeps its original position; {@see decodeCheckpoint()}
     * writes the rehydrated list straight back into that slot, and callers
     * that compare a round-tripped state against the one they saved (the
     * checkpoint tests do, with `assertSame`) see identical key order.
     *
     * @param array<string, mixed> $chatState
     *
     * @throws \JsonException on a state that cannot be encoded at all
     */
    private function encodeCheckpoint(string $sessionId, array $chatState): string
    {
        $messages = $chatState['messages'] ?? null;
        if (!is_array($messages)) {
            return self::encodeJson($chatState);
        }

        // The messages go down to internMessages() UNENCODED. Encoding them
        // here is what made every turn cost a full pass over the history; see
        // {@see $messageHashes} for the measurement and for why an
        // identity-keyed memo is sound.
        $interned = $this->internMessages($sessionId, array_values($messages));

        $chatState['messages'] = null;

        return self::encodeJson([
            self::CHECKPOINT_ENVELOPE_VERSION  => 1,
            self::CHECKPOINT_ENVELOPE_MESSAGES => $interned,
            self::CHECKPOINT_ENVELOPE_STATE    => $chatState,
        ]);
    }

    /**
     * This message's content hash, encoding it only if this process has not
     * already done so for this exact instance.
     *
     * Returns the payload alongside the hash ONLY when it had to be produced;
     * a memo hit returns null there, and a caller that then discovers it needs
     * the bytes after all asks {@see encodeJson()} again. Deliberate: holding
     * the payloads would double the memory cost of a history for a saving the
     * steady-state path never collects, since a hash already in
     * {@see $blobIds} is never re-inserted.
     *
     * @return array{0: string, 1: ?string} [hash, payload-if-freshly-encoded]
     *
     * @throws \JsonException
     */
    private function messageFingerprint(mixed $message): array
    {
        // `instanceof Message`, NOT `is_object()`. The memo's soundness proof
        // is a proof about {@see Message} specifically — that type and every
        // type reachable from it is deeply immutable, so an instance's
        // encoding is fixed for its lifetime. `is_object()` extended the memo
        // to objects that proof says nothing about, and a MUTABLE one was
        // then silently checkpointed at its first-seen contents forever:
        // save, mutate the object, save again, and the second checkpoint
        // restored the FIRST value. That is a corruption the pre-memo code
        // could not produce, because it re-encoded every message every time.
        $memo = $message instanceof Message ? ($this->messageHashes[$message] ?? null) : null;
        if ($memo !== null) {
            return [$memo, null];
        }

        $payload = self::encodeJson($message);
        $hash = hash('sha256', $payload);

        if ($message instanceof Message) {
            $this->messageHashes[$message] = $hash;
        }

        return [$hash, $payload];
    }

    /**
     * Encode one checkpoint fragment.
     *
     * WHY the flags: `json_encode()` returns `false` — not a partial string —
     * on a single invalid UTF-8 byte, and `(string) false` is `''`. A message
     * body that hit that path was stored as the empty string, hashed as the
     * empty string (so EVERY un-encodable message in the session collapsed
     * onto one shared blob), and came back from `json_decode('')` as `null`,
     * punching exactly the hole in the restored history that
     * {@see decodeCheckpoint()} promises never to return. `/rewind` then died
     * in its catch-all on `Argument #1 ($m) must be of type array, null
     * given`. Tool results reach history verbatim — `Chat::finishToolCalls()`
     * does not transcode them — so 64 raw bytes out of any binary-emitting
     * command is enough to trigger it.
     *
     * `JSON_INVALID_UTF8_SUBSTITUTE` makes that case WORK (U+FFFD in, message
     * still distinct, still restorable) and `JSON_THROW_ON_ERROR` makes any
     * other encode failure loud at the call site instead of silently empty.
     * The same pair, for the same reason, guards
     * {@see \SugarCraft\Crush\Cli\NonInteractive::encodeDocument()}.
     *
     * @throws \JsonException
     */
    private static function encodeJson(mixed $value): string
    {
        $json = json_encode($value, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);

        // Unreachable with JSON_THROW_ON_ERROR set; asserted rather than cast,
        // because the silent `(string)` cast IS the bug described above.
        return $json === false
            ? throw new \JsonException('json_encode() returned false without raising')
            : $json;
    }

    /**
     * Inverse of {@see encodeCheckpoint()}.
     *
     * Returns null when the envelope references a blob that is no longer on
     * disk. That is unreconstructable state, and reporting it as a missing
     * checkpoint (which every caller already handles) is honest, where
     * silently returning a history with a hole punched in it would not be.
     *
     * @return array<string, mixed>|null
     */
    private function decodeCheckpoint(string $stateData): ?array
    {
        $decoded = json_decode($stateData, true);
        if (!is_array($decoded)) {
            return null;
        }

        // Pre-envelope rows stored the whole state inline; they still read
        // back byte-for-byte as what they were written from.
        if (($decoded[self::CHECKPOINT_ENVELOPE_VERSION] ?? null) !== 1) {
            return $decoded;
        }

        $ids = $decoded[self::CHECKPOINT_ENVELOPE_MESSAGES] ?? null;
        $state = $decoded[self::CHECKPOINT_ENVELOPE_STATE] ?? null;
        if (!is_array($ids) || !is_array($state)) {
            return null;
        }

        $payloads = $this->loadBlobs(array_map('intval', $ids));
        if ($payloads === null) {
            return null;
        }

        $state['messages'] = $payloads;

        return $state;
    }

    /**
     * Map message bodies onto `checkpoint_blobs` ids, inserting the ones this
     * session has never checkpointed before.
     *
     * The cache is only trusted for as long as no OTHER connection has
     * written to the file — see {@see forgetInternedBlobsIfStale()} for why
     * that matters and what it costs.
     *
     * TWO caches meet here and they invalidate on different things.
     * {@see $blobIds} answers "does this hash have a row?" and is dropped
     * whenever the database moves under us. {@see $messageHashes} answers
     * "what is this object's hash?" and never needs dropping at all, because
     * the objects are immutable — a database write cannot change what a
     * `Message` in memory encodes to. Conflating them would have thrown away
     * the expensive half (the encode) to invalidate the cheap half (an int).
     *
     * @param list<mixed> $messages message bodies, in history order
     * @return list<int> blob ids, in the same order
     */
    private function internMessages(string $sessionId, array $messages): array
    {
        $this->forgetInternedBlobsIfStale();

        $known = $this->blobIds[$sessionId] ?? [];

        $hashes = [];
        /** @var array<string, mixed> $missing hash => the message it came from */
        $missing = [];
        /** @var array<string, string> $fresh hash => payload, for the ones encoded this call */
        $fresh = [];
        foreach ($messages as $message) {
            [$hash, $payload] = $this->messageFingerprint($message);
            $hashes[] = $hash;
            if ($payload !== null) {
                $fresh[$hash] = $payload;
            }
            if (!isset($known[$hash])) {
                $missing[$hash] = $message;
            }
        }

        if ($missing !== []) {
            // A blob may already be on disk from an earlier process even
            // though this instance's cache is cold, so look before inserting.
            foreach ($this->lookupBlobIds($sessionId, array_keys($missing)) as $hash => $id) {
                $known[$hash] = $id;
                unset($missing[$hash]);
            }
        }

        if ($missing !== []) {
            $insert = $this->pdo->prepare('
                INSERT OR IGNORE INTO checkpoint_blobs (session_id, hash, payload)
                VALUES (?, ?, ?)
            ');
            foreach ($missing as $hash => $message) {
                // A memo hit that reaches here is a hash whose blob this
                // process has not placed on disk (cold cache, or another
                // connection invalidated it) — the one case where the payload
                // has to be regenerated. Encoding is deterministic for an
                // immutable message, so the bytes match the hash by
                // construction.
                $insert->execute([$sessionId, $hash, $fresh[$hash] ?? self::encodeJson($message)]);
            }
            // Re-read rather than trusting lastInsertId(): OR IGNORE leaves it
            // stale on a row that lost a race with another connection.
            foreach ($this->lookupBlobIds($sessionId, array_keys($missing)) as $hash => $id) {
                $known[$hash] = $id;
            }
        }

        // Re-inserted last so the eviction order below is
        // least-recently-interned first.
        unset($this->blobIds[$sessionId]);
        $this->blobIds[$sessionId] = $known;
        while (count($this->blobIds) > self::MAX_CACHED_SESSIONS) {
            array_shift($this->blobIds);
        }

        $ids = [];
        foreach ($hashes as $hash) {
            $ids[] = $known[$hash] ?? 0;
        }

        return $ids;
    }

    /**
     * Drop every interned id when another connection has written to the file.
     *
     * The cache maps a body hash to a `checkpoint_blobs.id`, and those ids are
     * only stable while nothing deletes blobs. `collectCheckpointBlobs()`
     * drops the ids it deleted from the map on the instance that ran the GC,
     * but two sugar-crush terminals resume the SAME session — `Bootstrap::seedSession()` takes
     * `listSessions(1)[0]`, the globally most recent row — so a `/rewind` in
     * one process silently invalidated the other's cache. That process then
     * wrote envelopes naming deleted ids, and `decodeCheckpoint()` (correctly)
     * reported every one of them as "Checkpoint N not found": `/rewind` dead
     * for the rest of that process's life, with `/rewind` the table's only
     * consumer.
     *
     * `PRAGMA data_version` is the same invalidation `listSessions()` already
     * uses and reads only the database header, so the check is O(1) per save.
     * It changes for other-connection writes and NOT for ours, which is
     * exactly the split needed here: the GC, `deleteSession()` and
     * `pruneSessions()` clear their own instance directly (a same-connection
     * delete moves nothing), and everyone else's writes land here.
     *
     * Dropping rather than re-validating: a re-validation would have to
     * re-read every cached hash, which IS the cold path, so it would cost the
     * same and only complicate the invariant. The cache is kept — it is what
     * keeps the common single-process turn off a full-history hash lookup —
     * and a second live terminal pays one batched `lookupBlobIds()` per turn,
     * the same order as the envelope that turn writes anyway.
     *
     * Every caller now runs inside the save's `BEGIN IMMEDIATE`
     * ({@see writeTransaction()}), so this check and the INSERTs after it
     * happen under one write lock: another connection's GC can no longer
     * delete a blob between the check and the envelope that names it (the
     * interning race folded into audit SES-3). Should that invariant ever be
     * broken, a checkpoint that cannot be read is still refused rather than
     * silently truncated.
     */
    private function forgetInternedBlobsIfStale(): void
    {
        $stmt = $this->pdo->query('PRAGMA data_version');
        $dataVersion = (string) $stmt->fetchColumn();
        $stmt->closeCursor();

        if ($this->blobDataVersion !== $dataVersion) {
            $this->blobIds = [];
            $this->blobDataVersion = $dataVersion;
        }
    }

    /** Forget one session's interned ids, e.g. after its blobs were deleted. */
    private function forgetInternedBlobs(string $sessionId): void
    {
        unset($this->blobIds[$sessionId]);
    }

    /**
     * @param list<string> $hashes
     * @return array<string, int> hash => id, for the hashes that exist
     */
    private function lookupBlobIds(string $sessionId, array $hashes): array
    {
        $found = [];
        // Chunked because SQLite caps bound parameters per statement and a
        // long session's history can exceed that on a cold first save.
        foreach (array_chunk($hashes, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("
                SELECT hash, id FROM checkpoint_blobs
                WHERE session_id = ? AND hash IN ({$placeholders})
            ");
            $stmt->execute([$sessionId, ...$chunk]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[(string) $row['hash']] = (int) $row['id'];
            }
        }

        return $found;
    }

    /**
     * @param list<int> $ids
     * @return list<mixed>|null decoded message bodies in the given order, or
     *         null when any id has no row
     */
    private function loadBlobs(array $ids): ?array
    {
        if ($ids === []) {
            return [];
        }

        $byId = [];
        foreach (array_chunk(array_unique($ids), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("
                SELECT id, payload FROM checkpoint_blobs WHERE id IN ({$placeholders})
            ");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byId[(int) $row['id']] = json_decode((string) $row['payload'], true);
            }
        }

        $messages = [];
        foreach ($ids as $id) {
            if (!array_key_exists($id, $byId)) {
                return null;
            }
            $messages[] = $byId[$id];
        }

        return $messages;
    }

    /**
     * The transcript schema {@see saveTranscript()} writes, stored as `v` in
     * the transcript state (roadmap 1.B-1). A state without `v` is version 1:
     * rows with no identity, migrated on load by {@see loadTranscript()}.
     */
    public const TRANSCRIPT_SCHEMA_VERSION = 2;

    /**
     * One row-identity allocator per session this store has saved or loaded
     * a transcript for, primed from the persisted `nextRef` - see
     * {@see transcriptAllocator()}.
     *
     * @var array<string, \SugarCraft\Crush\Support\MessageIdAllocator>
     */
    private array $transcriptAllocators = [];

    /**
     * The identity each live ROW was given by a save (or by
     * {@see assignIdentity()}), keyed by the row's {@see Message::rowKey()}
     * token, so the same in-memory row is saved under the same id every time
     * even though Chat - immutable, a new instance per change - never holds a
     * stamped copy. Keyed by the token rather than the instance (roadmap
     * O-2b) because every wither makes a new instance: a placeholder finished
     * by `withToolResults()` or a tool row stamped by `withStepId()` is the
     * same row, and keyed by instance it was given a fresh ref on its next
     * save. Store-wide rather than per session: a `/branch` fork's rows are
     * its parent's rows and keep their parent's identities. Weak, so a row
     * dropped from every history takes its entry with it (its ref stays
     * spent). Created lazily.
     *
     * @var \WeakMap<object, array{0: string, 1: int}>|null
     */
    private ?\WeakMap $givenIdentities = null;

    /**
     * The rare second copy of one row in one history - the same
     * {@see Message::rowKey()} twice, which a caller that appends a row and a
     * wither of it gets. The first occurrence keeps the token's identity; each
     * later one is a row of its own, keyed by INSTANCE here so it too is
     * stable across saves, and never shares an id with the first.
     *
     * @var \WeakMap<Message, array{0: string, 1: int}>|null
     */
    private ?\WeakMap $duplicateIdentities = null;

    /**
     * Store $messages as the session's current transcript, replacing the last
     * one, and mark the session as just used.
     *
     * Marking it used is what makes "the most recent session" mean the one
     * last WORKED in rather than the one last created: `updated_at` was only
     * ever moved by a rename, so `--continue` would otherwise reopen whichever
     * session happened to be started last.
     *
     * The session row is recreated if it has gone — deleted from another
     * client by {@see pruneEmptySessions()} while this one sat idle — because
     * the foreign key would otherwise refuse the write and the conversation
     * the user is still having would never be saved.
     *
     * EVERY SAVED ROW HAS AN IDENTITY (roadmap 1.B-1, schema
     * {@see TRANSCRIPT_SCHEMA_VERSION}). A row that already carries its
     * `id`/`ref` keeps them; one that does not is given the next ref from the
     * session's {@see \SugarCraft\Crush\Support\MessageIdAllocator} - the
     * same one for the same row on every save, wither copies included, see
     * {@see $givenIdentities} - and that identity is written to
     * the state's `identities` map, by position, instead of into the row. The
     * row's own bytes are left alone on purpose: its blob is the one the
     * checkpoints of the same history already share, and stamping the id into
     * it would store every message of the session twice. {@see loadTranscript()}
     * folds the map back into the rows, so a resumed row carries its identity
     * from then on. The state also records `nextRef`, the high-water mark, so a
     * ref is never handed out twice however many rows are later dropped.
     *
     * @param list<Message|array<string, mixed>> $messages
     *
     * @throws \JsonException on a message that cannot be encoded
     */
    public function saveTranscript(string $sessionId, array $messages): void
    {
        // One transaction for the whole save. Without it every blob INSERT
        // internMessages() issued autocommitted — one WAL commit and fsync
        // per new message — and a first save of a long history (a resumed
        // legacy session, or the first save under a /branch id) froze the
        // TUI for seconds inside update(): 5.5 s for 800 messages (audit
        // 15b-21). It also makes the save atomic: a failure part-way leaves
        // the previous transcript and no stray blobs.
        $this->writeTransaction(function () use ($sessionId, $messages): void {
            if ($this->sessionStore->getSession($sessionId) === null) {
                $this->sessionStore->createSession($sessionId, 'sugarcrush', 'unknown');
            }

            $messages = array_values($messages);
            $allocator = $this->transcriptAllocator($sessionId);
            $given = $this->givenIdentities ??= new \WeakMap();
            $duplicates = $this->duplicateIdentities ??= new \WeakMap();
            // Every ref already held - carried, or given by an earlier save -
            // is observed BEFORE any is allocated, so a fresh row early in the
            // list cannot take a ref a later row holds.
            foreach ($messages as $message) {
                if ($message instanceof Message) {
                    $allocator->observe($given[$message->rowKey()][1] ?? null);
                    $allocator->observe($duplicates[$message][1] ?? null);
                }
                $allocator->observe(self::carriedIdentity($message)[1]);
            }
            $identities = [];
            $seenRows = [];
            foreach ($messages as $i => $message) {
                [$id, $ref] = self::carriedIdentity($message);
                if ($id !== null && $ref !== null) {
                    continue;
                }
                if ($message instanceof Message) {
                    $key = $message->rowKey();
                    $identities[$i] = isset($seenRows[spl_object_id($key)])
                        ? ($duplicates[$message] ??= $allocator->identityFor($id, $ref))
                        : ($given[$key] ??= $allocator->identityFor($id, $ref));
                    $seenRows[spl_object_id($key)] = true;
                } elseif (\is_array($message)) {
                    $identities[$i] = $allocator->identityFor($id, $ref);
                }
            }

            $stmt = $this->pdo->prepare('
                INSERT OR REPLACE INTO session_transcripts (session_id, state_data, updated_at)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([
                $sessionId,
                $this->encodeCheckpoint($sessionId, [
                    'v' => self::TRANSCRIPT_SCHEMA_VERSION,
                    'nextRef' => $allocator->nextRef(),
                    'identities' => $identities,
                    'messages' => $messages,
                ]),
                gmdate('Y-m-d H:i:s'),
            ]);

            $this->sessionStore->updateSession($sessionId);
        });
    }

    /**
     * The session's transcript as raw message rows, oldest first, or null
     * when there is nothing to resume.
     *
     * A session saved before transcripts existed has none, so the newest
     * checkpoint stands in for it: that loses the final reply (a checkpoint
     * is taken as a turn is sent) but keeps every earlier exchange, which is
     * better than resuming such a session empty.
     *
     * Every row comes back with an `id` and a `ref` (roadmap 1.B-1). A
     * version-2 transcript has its `identities` map folded back in; a
     * version-1 one - and the checkpoint stand-in, which never had a version -
     * is migrated in order, refs 1, 2, 3, … and `nextRef` one past the last.
     * The migration is deterministic, so a legacy session read twice without
     * a save between reads the same identities, and the first save after a
     * resume persists them. A migrated legacy tool row gets no `stepId`: there
     * is no recorded step to rebuild a `tool_calls`/`tool` pair from, so it
     * stays the prose row it always was (see {@see Message::$stepId}).
     *
     * @return list<array<string, mixed>>|null
     */
    public function loadTranscript(string $sessionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT state_data FROM session_transcripts WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $stateData = $stmt->fetchColumn();
        $stmt->closeCursor();

        $state = \is_string($stateData) ? $this->decodeCheckpoint($stateData) : null;
        if ($state === null) {
            $newest = $this->listCheckpoints($sessionId, 1)[0]['state_data'] ?? null;
            $state = \is_array($newest) ? $newest : null;
        }

        $messages = $state['messages'] ?? null;
        if (!\is_array($messages)) {
            return null;
        }

        $versioned = self::transcriptVersion($state) >= 2;
        $allocator = \SugarCraft\Crush\Support\MessageIdAllocator::new(
            $sessionId,
            $versioned && \is_int($state['nextRef'] ?? null) ? $state['nextRef'] : 1,
        );
        $identities = $versioned && \is_array($state['identities'] ?? null) ? $state['identities'] : [];

        $rows = [];
        foreach (array_values($messages) as $i => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $identity = $identities[$i] ?? null;
            if (\is_array($identity) && \is_string($identity[0] ?? null) && \is_int($identity[1] ?? null)) {
                $row['id'] ??= $identity[0];
                $row['ref'] ??= $identity[1];
            }
            $rows[] = $row;
        }
        foreach ($rows as $row) {
            $allocator->observe(self::carriedIdentity($row)[1]);
        }
        foreach ($rows as $i => $row) {
            [$rows[$i]['id'], $rows[$i]['ref']] = $allocator->identityFor(...self::carriedIdentity($row));
        }

        // A save already pending from this process may have allocated past
        // what is on disk; the higher mark wins, so no ref is reissued.
        $cached = $this->transcriptAllocators[$sessionId] ?? null;
        if ($cached !== null) {
            $cached->observe($allocator->nextRef() - 1);
        } else {
            $this->transcriptAllocators[$sessionId] = $allocator;
        }

        return $rows;
    }

    /**
     * $sessionId's allocator, primed on first use from the `nextRef` its
     * stored transcript records (1 when it has none, or a version-1 one - whose
     * rows the save in hand is replacing anyway). Primed once per process: the
     * single-writer session lock ({@see lockSession()}) is what keeps another
     * process from allocating under this one.
     */
    private function transcriptAllocator(string $sessionId): \SugarCraft\Crush\Support\MessageIdAllocator
    {
        if (isset($this->transcriptAllocators[$sessionId])) {
            return $this->transcriptAllocators[$sessionId];
        }

        $stmt = $this->pdo->prepare('SELECT state_data FROM session_transcripts WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $stateData = $stmt->fetchColumn();
        $stmt->closeCursor();

        // Only the envelope is decoded - its blob ids are integers - never
        // the message bodies: the mark is all this needs.
        $decoded = \is_string($stateData) ? json_decode($stateData, true) : null;
        $state = \is_array($decoded) && \is_array($decoded[self::CHECKPOINT_ENVELOPE_STATE] ?? null)
            ? $decoded[self::CHECKPOINT_ENVELOPE_STATE]
            : (\is_array($decoded) ? $decoded : []);
        $nextRef = self::transcriptVersion($state) >= 2 && \is_int($state['nextRef'] ?? null) ? $state['nextRef'] : 1;

        return $this->transcriptAllocators[$sessionId] = \SugarCraft\Crush\Support\MessageIdAllocator::new($sessionId, $nextRef);
    }

    /**
     * The transcript schema a stored state was written with: its `v`, or 1
     * when it has none.
     *
     * @param array<string, mixed> $state
     */
    private static function transcriptVersion(array $state): int
    {
        return \is_int($state['v'] ?? null) ? $state['v'] : 1;
    }

    /**
     * The `[id, ref]` a transcript row already carries, each null when absent
     * or malformed.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private static function carriedIdentity(mixed $row): array
    {
        if ($row instanceof Message) {
            return [$row->id, $row->ref];
        }
        if (!\is_array($row)) {
            return [null, null];
        }

        return [
            \is_string($row['id'] ?? null) && $row['id'] !== '' ? $row['id'] : null,
            \is_int($row['ref'] ?? null) && $row['ref'] >= 1 ? $row['ref'] : null,
        ];
    }

    /**
     * The `[id, ref]` $message has, or null when it has none yet: the identity
     * it carries (a reloaded row), else the one a save or
     * {@see assignIdentity()} gave its row (roadmap O-2b).
     *
     * This is how a LIVE row's identity is read. Chat never holds a stamped
     * copy of a row it minted - the field stays null until the session is
     * reloaded - but the store remembers what it gave the row, by its
     * {@see Message::rowKey()}, so every wither of it answers the same. A row
     * that has been saved only as a duplicate of another row answers with its
     * own, instance-keyed identity.
     *
     * @return array{0: string, 1: int}|null
     */
    public function identityOf(Message $message): ?array
    {
        [$id, $ref] = self::carriedIdentity($message);
        if ($id !== null && $ref !== null) {
            return [$id, $ref];
        }

        return $this->duplicateIdentities[$message] ?? $this->givenIdentities[$message->rowKey()] ?? null;
    }

    /**
     * $message's identity, allocated now from $sessionId's allocator when its
     * row has none yet - so a host can name a row in an event BEFORE the
     * debounced save reaches it, and that save then writes the same identity
     * (it observes every given ref before allocating any).
     *
     * @return array{0: string, 1: int}
     */
    public function assignIdentity(string $sessionId, Message $message): array
    {
        $known = $this->identityOf($message);
        if ($known !== null) {
            return $known;
        }

        $given = $this->givenIdentities ??= new \WeakMap();
        [$id, $ref] = self::carriedIdentity($message);

        return $given[$message->rowKey()] = $this->transcriptAllocator($sessionId)->identityFor($id, $ref);
    }

    /**
     * Append one durable event to $sessionId's log and return its `seq`
     * (roadmap O-2b; the `session_events` table, Appendix O §6.4).
     *
     * The seq is `MAX(seq) + 1` read and written inside one `BEGIN IMMEDIATE`
     * transaction, so two writers on one database can never take the same
     * number and a restarted process continues where the log left off. It is
     * never reused: retention trims from the OLD end only, so MAX survives.
     *
     * The session row is recreated if it has gone, for the reason
     * {@see saveTranscript()} recreates it - the foreign key would otherwise
     * refuse the write.
     *
     * Retention: once the log holds more than $retain events the oldest are
     * dropped in the same transaction; a reader whose cursor fell behind
     * {@see sessionEventBounds()}'s oldest seq must resync from a snapshot.
     * $retain below 1 keeps everything.
     *
     * @param array<string, mixed> $payload JSON-encodable; invalid UTF-8 is substituted, never fatal
     * @param int|null $tsMs milliseconds since the epoch; null is now
     *
     * @throws \InvalidArgumentException on an empty type
     * @throws \JsonException on a payload JSON cannot encode at all (e.g. INF)
     */
    public function appendSessionEvent(
        string $sessionId,
        string $type,
        array $payload = [],
        ?int $tsMs = null,
        int $retain = 0,
    ): int {
        if (trim($type) === '') {
            throw new \InvalidArgumentException('A session event needs a non-empty type.');
        }

        $encoded = json_encode(
            $payload === [] ? new \stdClass() : $payload,
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $tsMs ??= (int) floor(microtime(true) * 1000);

        return $this->writeTransaction(function () use ($sessionId, $type, $encoded, $tsMs, $retain): int {
            if ($this->sessionStore->getSession($sessionId) === null) {
                $this->sessionStore->createSession($sessionId, 'sugarcrush', 'unknown');
            }

            $max = $this->pdo->prepare('SELECT COALESCE(MAX(seq), 0) FROM session_events WHERE session_id = ?');
            $max->execute([$sessionId]);
            $seq = (int) $max->fetchColumn() + 1;
            $max->closeCursor();

            $this->pdo->prepare('
                INSERT INTO session_events (session_id, seq, ts, type, payload) VALUES (?, ?, ?, ?, ?)
            ')->execute([$sessionId, $seq, $tsMs, $type, $encoded]);

            if ($retain >= 1 && $seq > $retain) {
                $this->pdo->prepare('DELETE FROM session_events WHERE session_id = ? AND seq <= ?')
                    ->execute([$sessionId, $seq - $retain]);
            }

            return $seq;
        });
    }

    /**
     * $sessionId's events with a seq above $afterSeq, oldest first, at most
     * $limit of them - one page of a replay. The rows are fetched in full
     * before they are returned, so no read cursor stays open across a later
     * INSERT (the WAL note on this store).
     *
     * @return list<array{seq: int, ts: int, type: string, payload: array<string, mixed>}>
     */
    public function sessionEvents(string $sessionId, int $afterSeq = 0, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare('
            SELECT seq, ts, type, payload FROM session_events
            WHERE session_id = ? AND seq > ? ORDER BY seq ASC LIMIT ?
        ');
        $stmt->bindValue(1, $sessionId);
        $stmt->bindValue(2, $afterSeq, PDO::PARAM_INT);
        $stmt->bindValue(3, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return array_map(static function (array $row): array {
            $payload = json_decode((string) $row['payload'], true);

            return [
                'seq' => (int) $row['seq'],
                'ts' => (int) $row['ts'],
                'type' => (string) $row['type'],
                'payload' => \is_array($payload) ? $payload : [],
            ];
        }, $rows);
    }

    /**
     * `[oldest, latest]` seq in $sessionId's log: oldest is null when the log
     * is empty, latest is 0 then. A replay cursor below oldest - 1 has lost
     * events to retention and must resync from a snapshot (Appendix O §6.8).
     *
     * @return array{0: ?int, 1: int}
     */
    public function sessionEventBounds(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT MIN(seq), MAX(seq) FROM session_events WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $stmt->closeCursor();

        $oldest = \is_array($row) && $row[0] !== null ? (int) $row[0] : null;

        return [$oldest, $oldest === null ? 0 : (int) $row[1]];
    }

    /**
     * The most recently used session that holds a conversation — a saved
     * transcript or at least one checkpoint — or null when none does.
     *
     * What `--continue` means. "The most recent row" is the wrong answer now
     * that every launch opens a row of its own: a launch quit without typing
     * is newer than the conversation the user wants back, and continuing it
     * would open an empty chat.
     *
     * Only the user's own conversations count: a sub-agent's transcript is
     * newer than the session that delegated to it, and an archived session
     * was put away on purpose, so neither is what `--continue` means.
     *
     * @return array<string, mixed>|null
     */
    public function latestResumableSession(): ?array
    {
        $stmt = $this->pdo->query('
            SELECT s.* FROM sessions s
            WHERE s.kind <> \'subagent\' AND s.archived_at IS NULL
              AND (EXISTS (SELECT 1 FROM session_transcripts t WHERE t.session_id = s.id)
                OR EXISTS (SELECT 1 FROM checkpoints c WHERE c.session_id = s.id))
            ORDER BY s.updated_at DESC, s.rowid DESC
            LIMIT 1
        ');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return \is_array($row) ? $row : null;
    }

    /**
     * Delete sessions that never held anything: unnamed, no transcript, no
     * checkpoint, no stored message, and untouched for at least
     * $minAgeSeconds. Returns how many went.
     *
     * Every launch now opens a session of its own, so a launch that was
     * quit without typing leaves an empty row behind; without this the
     * `/sessions` picker and the tab strip fill up with them. Nothing a user
     * could want back is deleted — an empty row has no content by definition —
     * and the age floor keeps it from taking the fresh row a client in
     * another terminal opened moments ago. Rows go through
     * {@see deleteSession()} so the session-list memo and the blob cache see
     * the delete. Sub-agent rows are left to their parent's lifecycle, and a
     * pinned row is kept however empty it is.
     */
    public function pruneEmptySessions(?string $exemptSessionId = null, int $minAgeSeconds = 3600): int
    {
        $stmt = $this->pdo->prepare('
            SELECT s.id FROM sessions s
            WHERE (s.name IS NULL OR s.name = \'\')
              AND s.kind <> \'subagent\'
              AND s.pinned = 0
              AND s.updated_at < ?
              AND s.id != ?
              AND NOT EXISTS (SELECT 1 FROM session_transcripts t WHERE t.session_id = s.id)
              AND NOT EXISTS (SELECT 1 FROM checkpoints c WHERE c.session_id = s.id)
              AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.session_id = s.id)
        ');
        $stmt->execute([gmdate('Y-m-d H:i:s', time() - max(0, $minAgeSeconds)), $exemptSessionId ?? '']);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $id) {
            $this->deleteSession((string) $id);
        }

        return \count($ids);
    }

    /**
     * Retrieve a specific checkpoint by index — a live one: a row a rewind
     * moved onto the redo stack is not returned (see {@see redoStack()}).
     *
     * @param string $sessionId The session ID
     * @param int $index The checkpoint index
     * @return array|null The checkpoint state data, or null if not found
     */
    public function getCheckpoint(string $sessionId, int $index): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT state_data FROM checkpoints WHERE session_id = ? AND "index" = ? AND undone = 0
        ');
        $stmt->execute([$sessionId, $index]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        // restoreCheckpoint() WRITES immediately after calling this, so the
        // read transaction a live cursor holds would straddle that write and
        // suppress the WAL auto-checkpoint — the same trap saveCheckpoint()
        // fell into. Closed explicitly rather than left to $stmt's scope.
        $stmt->closeCursor();

        if (!$row) {
            return null;
        }

        return $this->decodeCheckpoint((string) $row['state_data']);
    }

    /**
     * List recent live checkpoints for a session, newest first. Rows on the
     * redo stack are left out (see {@see redoStack()}).
     *
     * @param string $sessionId The session ID
     * @param int $limit Maximum number of checkpoints to return (default 100)
     * @return array<int, array{index: int, created_at: string, state_data: array}> Checkpoint summaries
     */
    public function listCheckpoints(string $sessionId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('
            SELECT "index", created_at, state_data
            FROM checkpoints
            WHERE session_id = ? AND undone = 0
            ORDER BY "index" DESC
            LIMIT ?
        ');
        $stmt->execute([$sessionId, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return [
                'index' => (int) $row['index'],
                'created_at' => $row['created_at'],
                'state_data' => $this->decodeCheckpoint((string) $row['state_data']),
            ];
        }, $rows);
    }

    /**
     * Restore checkpoint $index and return its state data.
     *
     * THE ROWS FROM $index UP ARE SET ASIDE, NOT DELETED (item 3.A-2; this
     * deleted them, so a rewind could not be undone). They are marked
     * {@see CHECKPOINT_UNDONE} and form the redo stack, its lowest row —
     * $index itself — being the state the conversation is now at. Every live
     * reader stops seeing them at once, refs and message bodies stay, and
     * {@see redoCheckpoint()} steps back up through them. The next
     * {@see saveCheckpoint()} deletes them, refs and bodies included.
     *
     * $redoTip is the state being left (the conversation as it stands, with
     * its files when $root is given). It is recorded as the stack's top row
     * — {@see CHECKPOINT_REDO_TIP} — only when there is no stack yet: with
     * one, the state being left is the stack's lowest row already, so a
     * second rewind adds rows below it rather than a second top.
     *
     * @param string $sessionId The session ID
     * @param int $index The checkpoint index to restore
     * @param array<string, mixed>|null $redoTip the state /redo returns to last
     * @param string|null $root the project whose files the tip snapshots
     * @return array|null The restored state data, or null if not found
     */
    public function restoreCheckpoint(string $sessionId, int $index, ?array $redoTip = null, ?string $root = null): ?array
    {
        $tipIndex = null;
        // Read and mark under one write lock, so another writer cannot add a
        // checkpoint between the read and the update.
        $state = $this->writeTransaction(function () use ($sessionId, $index, $redoTip, &$tipIndex): ?array {
            $state = $this->getCheckpoint($sessionId, $index);
            if ($state === null) {
                return null;
            }

            $hadStack = $this->redoStackSize($sessionId) > 0;
            $this->pdo->prepare('
                UPDATE checkpoints SET undone = ? WHERE session_id = ? AND "index" >= ? AND undone = ?
            ')->execute([self::CHECKPOINT_UNDONE, $sessionId, $index, self::CHECKPOINT_LIVE]);

            if ($redoTip !== null && !$hadStack) {
                $tipIndex = $this->insertCheckpointRow($sessionId, $redoTip, self::CHECKPOINT_REDO_TIP);
            }

            return $state;
        });

        // The tip's files, after the commit and before the caller restores
        // the target's: this is the snapshot /redo puts back at the end.
        if ($tipIndex !== null && $root !== null && $root !== '') {
            try {
                $this->captureWorkspace($sessionId, $tipIndex, $root);
            } catch (\Throwable) {
                // The tip simply has no files to return to; /redo says so.
            }
        }

        return $state;
    }

    /**
     * The redo stack, lowest index first: the first row is the state the
     * conversation is at, each later one a step /redo can take, the last the
     * state the first rewind left (`tip`). Empty when there is nothing to
     * redo. Each row carries its workspace outcome (null when none was
     * recorded), read without loading a message body.
     *
     * @return list<array{index: int, tip: bool, workspace: array<string, mixed>|null}>
     */
    public function redoStack(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT "index", undone, state_data FROM checkpoints
            WHERE session_id = ? AND undone > 0 ORDER BY "index" ASC
        ');
        $stmt->execute([$sessionId]);

        return array_map(fn (array $row): array => [
            'index' => (int) $row['index'],
            'tip' => (int) $row['undone'] === self::CHECKPOINT_REDO_TIP,
            'workspace' => $this->workspaceOf((string) $row['state_data']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Take one step up the redo stack: the row above the lowest becomes the
     * state the conversation is at, and the rows below it are live again.
     * Reaching the tip ends the stack — the tip row is deleted (it is the
     * state after the last turn, not a checkpoint taken before one) and its
     * ref dropped once the write commits; the snapshot commit stays in the
     * object store for the caller's file restore.
     *
     * @return array{index: int, tip: bool, state: array<string, mixed>}|null
     *         null when there is no step to take
     */
    public function redoCheckpoint(string $sessionId): ?array
    {
        try {
            $step = $this->writeTransaction(function () use ($sessionId): ?array {
                $stmt = $this->pdo->prepare('
                    SELECT id, "index", undone, state_data FROM checkpoints
                    WHERE session_id = ? AND undone > 0 ORDER BY "index" ASC LIMIT 2
                ');
                $stmt->execute([$sessionId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (\count($rows) < 2) {
                    return null;
                }
                $to = $rows[1];
                $state = $this->decodeCheckpoint((string) $to['state_data']);
                if ($state === null) {
                    return null;
                }
                $tip = (int) $to['undone'] === self::CHECKPOINT_REDO_TIP;

                $this->pdo->prepare('
                    UPDATE checkpoints SET undone = ? WHERE session_id = ? AND undone = ? AND "index" < ?
                ')->execute([self::CHECKPOINT_LIVE, $sessionId, self::CHECKPOINT_UNDONE, (int) $to['index']]);

                if ($tip) {
                    $this->queueWorkspaceRefDrops('id = ?', [(int) $to['id']]);
                    $this->pdo->prepare('DELETE FROM checkpoints WHERE id = ?')->execute([(int) $to['id']]);
                    $this->collectCheckpointBlobs($sessionId);
                }

                return ['index' => (int) $to['index'], 'tip' => $tip, 'state' => $state];
            });
        } catch (\Throwable $e) {
            $this->discardWorkspaceRefOps();
            throw $e;
        }
        $this->flushWorkspaceRefOps();

        return $step;
    }

    /**
     * Delete the redo stack of $sessionId outright — what the next
     * {@see saveCheckpoint()} does on its own.
     */
    public function discardRedoStack(string $sessionId): void
    {
        try {
            $this->writeTransaction(function () use ($sessionId): void {
                $this->discardRedoStackRows($sessionId);
            });
        } catch (\Throwable $e) {
            $this->discardWorkspaceRefOps();
            throw $e;
        }
        $this->flushWorkspaceRefOps();
    }

    /**
     * Inside a write: delete the redo-stack rows, queue their refs, and
     * collect the message bodies only they named.
     */
    private function discardRedoStackRows(string $sessionId): void
    {
        if ($this->redoStackSize($sessionId) === 0) {
            return;
        }
        $this->queueWorkspaceRefDrops('session_id = ? AND undone > 0', [$sessionId]);
        $this->pdo->prepare('DELETE FROM checkpoints WHERE session_id = ? AND undone > 0')->execute([$sessionId]);
        // A rewind explicitly discarded this state, so the messages only it
        // referenced stop being worth keeping.
        $this->collectCheckpointBlobs($sessionId);
    }

    private function redoStackSize(string $sessionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM checkpoints WHERE session_id = ? AND undone > 0');
        $stmt->execute([$sessionId]);
        $count = (int) $stmt->fetchColumn();
        // An open cursor across the write that follows blocks the WAL
        // auto-checkpoint; see insertCheckpoint().
        $stmt->closeCursor();

        return $count;
    }

    /**
     * Drop `checkpoint_blobs` rows no surviving checkpoint of this session
     * references any more.
     */
    private function collectCheckpointBlobs(string $sessionId): void
    {
        // The transcript row interns into the same blob table, so its ids are
        // live too — without it a /rewind would collect the messages the
        // resumable transcript still names, and the next resume would read
        // back nothing.
        $stmt = $this->pdo->prepare('
            SELECT state_data FROM checkpoints WHERE session_id = ?
            UNION ALL
            SELECT state_data FROM session_transcripts WHERE session_id = ?
        ');
        $stmt->execute([$sessionId, $sessionId]);

        $live = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stateData) {
            $decoded = json_decode((string) $stateData, true);
            $ids = is_array($decoded) ? ($decoded[self::CHECKPOINT_ENVELOPE_MESSAGES] ?? null) : null;
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $id) {
                $live[(int) $id] = true;
            }
        }

        if ($live === []) {
            $this->pdo->prepare('DELETE FROM checkpoint_blobs WHERE session_id = ?')->execute([$sessionId]);
        } else {
            // Ids are ints straight out of (int) casts, never user text.
            $keep = implode(',', array_keys($live));
            $this->pdo
                ->prepare("DELETE FROM checkpoint_blobs WHERE session_id = ? AND id NOT IN ({$keep})")
                ->execute([$sessionId]);
        }

        // Ids this instance had interned may have just been deleted, so drop
        // exactly those from the cache and keep the rest. Forgetting the
        // whole session instead would make the next save re-look-up every
        // hash in the history, and since pruneOldCheckpoints() now collects
        // on every turn past the retention limit, that cold lookup would
        // become the steady state. Other instances see the delete through
        // forgetInternedBlobsIfStale().
        if (isset($this->blobIds[$sessionId])) {
            $this->blobIds[$sessionId] = array_filter(
                $this->blobIds[$sessionId],
                static fn (int $id): bool => isset($live[$id]),
            );
        }
    }

    /**
     * Prune old checkpoints to keep the count under the limit.
     */
    private function pruneOldCheckpoints(string $sessionId, int $maxCheckpoints): void
    {
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM checkpoints WHERE session_id = ?');
        $countStmt->execute([$sessionId]);
        $count = (int) $countStmt->fetchColumn();
        // Same open-cursor-blocks-the-WAL-checkpoint trap as saveCheckpoint().
        $countStmt->closeCursor();

        if ($count <= $maxCheckpoints) {
            return;
        }

        // Delete oldest checkpoints to bring count down to maxCheckpoints
        $deleteCount = $count - $maxCheckpoints;
        $this->queueWorkspaceRefDrops(
            'id IN (SELECT id FROM checkpoints WHERE session_id = ? ORDER BY "index" ASC LIMIT ?)',
            [$sessionId, $deleteCount],
        );
        $deleteStmt = $this->pdo->prepare('
            DELETE FROM checkpoints
            WHERE session_id = ? AND id IN (
                SELECT id FROM checkpoints WHERE session_id = ?
                ORDER BY "index" ASC
                LIMIT ?
            )
        ');
        $deleteStmt->execute([$sessionId, $sessionId, $deleteCount]);

        // The pruned checkpoints may have been the last reference to some
        // blobs. This used to be left to /rewind alone, on the theory that
        // blob storage is O(distinct messages) either way — but history is
        // not append-only: /compact replaces it wholesale and a tool
        // placeholder is replaced in place, so the bodies that fall out of
        // history are referenced only by checkpoints this prune deletes. A
        // session that never rewound kept them forever: 29 orphans out of 150
        // blobs in the audit's repro, each up to a 1 MiB tool result (audit
        // SES-4). The scan reads this session's ≤ MAX_CHECKPOINTS_PER_SESSION
        // envelopes plus its transcript, inside the save's transaction.
        $this->collectCheckpointBlobs($sessionId);
    }

    /**
     * Queue the snapshot refs of the checkpoint rows matching $where for
     * deletion once the current write commits.
     *
     * @param list<mixed> $params
     */
    private function queueWorkspaceRefDrops(string $where, array $params): void
    {
        $stmt = $this->pdo->prepare("SELECT state_data FROM checkpoints WHERE {$where}");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stateData) {
            $workspace = $this->workspaceOf((string) $stateData);
            if (WorkspaceCheckpointer::isCaptured($workspace)) {
                $this->pendingRefDrops[] = $workspace;
            }
        }
    }

    /**
     * Every pinned snapshot this database records, by session. A substring
     * pre-filter keeps the scan to rows that can hold one; each match is
     * decoded and checked, so a false positive costs a decode, never a drop.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function workspaceRefsBySession(): array
    {
        $stmt = $this->pdo->prepare('SELECT session_id, state_data FROM checkpoints WHERE instr(state_data, ?) > 0');
        $stmt->execute(['"' . self::CHECKPOINT_WORKSPACE_KEY . '"']);
        $bySession = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $workspace = $this->workspaceOf((string) $row['state_data']);
            if (WorkspaceCheckpointer::isCaptured($workspace)) {
                $bySession[(string) $row['session_id']][] = $workspace;
            }
        }

        return $bySession;
    }

    /**
     * The workspace outcome stored in a raw `state_data` payload, envelope or
     * inline, without loading any message blob.
     *
     * @return array<string, mixed>|null
     */
    private function workspaceOf(string $stateData): ?array
    {
        if (!str_contains($stateData, '"' . self::CHECKPOINT_WORKSPACE_KEY . '"')) {
            return null;
        }
        $decoded = json_decode($stateData, true);
        if (!\is_array($decoded)) {
            return null;
        }
        $state = ($decoded[self::CHECKPOINT_ENVELOPE_VERSION] ?? null) === 1
            ? ($decoded[self::CHECKPOINT_ENVELOPE_STATE] ?? null)
            : $decoded;
        $workspace = \is_array($state) ? ($state[self::CHECKPOINT_WORKSPACE_KEY] ?? null) : null;

        return \is_array($workspace) ? $workspace : null;
    }

    /**
     * $stateData with {@see CHECKPOINT_WORKSPACE_KEY} set to $workspace.
     *
     * @param array<string, mixed> $workspace
     */
    private function withWorkspace(string $stateData, array $workspace): string
    {
        $decoded = json_decode($stateData, false);
        if (!$decoded instanceof \stdClass) {
            return $stateData;
        }
        $envelope = ($decoded->{self::CHECKPOINT_ENVELOPE_VERSION} ?? null) === 1;
        $state = $envelope ? ($decoded->{self::CHECKPOINT_ENVELOPE_STATE} ?? null) : $decoded;
        if (!$state instanceof \stdClass) {
            return $stateData;
        }
        $state->{self::CHECKPOINT_WORKSPACE_KEY} = $workspace;

        return self::encodeJson($decoded);
    }

    /** Apply the queued ref deletions and copies, then forget them. */
    private function flushWorkspaceRefOps(): void
    {
        [$drops, $copies] = [$this->pendingRefDrops, $this->pendingRefCopies];
        $this->discardWorkspaceRefOps();
        if ($copies !== []) {
            WorkspaceCheckpointer::copyRefs($copies);
        }
        if ($drops !== []) {
            WorkspaceCheckpointer::dropRefs($drops);
        }
    }

    /** Forget the queued ref operations of a write that rolled back. */
    private function discardWorkspaceRefOps(): void
    {
        $this->pendingRefDrops = [];
        $this->pendingRefCopies = [];
    }
}
