<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

use PDO;

/**
 * SQLite session persistence with WAL mode.
 *
 * Mirrors charmbracelet/charmbracelet session storage.
 */
final class SessionStore
{
    /**
     * The statement {@see listSessions()} prepares, verbatim.
     *
     * Public because the index test EXPLAINs it: a hand-copied duplicate in
     * the test file was green while a mutation of the real `ORDER BY` to an
     * unindexable-but-equivalent form (`ORDER BY datetime(updated_at) DESC`)
     * degraded the actual plan to `SCAN sessions` + `USE TEMP B-TREE`. A test
     * that explains a copy proves nothing about the query that runs.
     */
    public const LIST_SESSIONS_SQL = 'SELECT * FROM sessions ORDER BY updated_at DESC, rowid DESC LIMIT ?';

    /**
     * Upper bound on `pruneSessions()`'s `$daysOld`, ~100 years.
     *
     * `strtotime("-N days")` with an unbounded N does not fail, it OVERFLOWS
     * into a cutoff in the FUTURE, at which point "delete everything older
     * than the cutoff" means "delete everything". Callers that read the value
     * from the environment clamp there too; this is the last line of defence
     * for any other caller.
     */
    public const MAX_RETENTION_DAYS = 36500;

    private PDO $pdo;

    /**
     * Memoised {@see listSessions()} result rows, keyed by the `$limit`
     * argument. See {@see sessionListStamp()} for why this exists and how it
     * is invalidated.
     *
     * @var array<int, array{stamp: string, rows: array<int, array<string, mixed>>}>
     */
    private array $sessionListCache = [];

    /**
     * Bumped by every method on this class that writes the `sessions` table,
     * so a write made through THIS connection invalidates the cache. Writes
     * made through another connection are caught by `PRAGMA data_version`
     * instead, which only ever changes for other-connection writes.
     */
    private int $sessionWriteSeq = 0;

    /** @see sessionListQueries() */
    private int $sessionListQueries = 0;

    /**
     * What the most recent {@see pruneSessions()} call actually deleted.
     *
     * @var array<int, array{id: string, name: ?string, updated_at: string, messages: int}>
     */
    private array $pruneReport = [];

    public function __construct(string $dbPath)
    {
        // The transcript database holds full conversation history — sensitive
        // data that must stay owner-only. Setting a restrictive umask BEFORE PDO
        // touches the filesystem makes the main DB and its WAL/SHM sidecar files
        // 0600 at creation time, closing the world-readable window a
        // create-then-chmod would leave open. The trailing chmod re-asserts 0600
        // for a database that pre-existed with looser permissions.
        $previousUmask = umask(0077);
        try {
            $this->pdo = new PDO("sqlite:$dbPath");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            // Foreign keys are OFF by default in SQLite and must be enabled per
            // connection. Without this the FK/ON DELETE CASCADE clauses below are
            // inert and orphaned rows can accumulate.
            $this->pdo->exec('PRAGMA foreign_keys=ON');
            $this->initSchema();
        } finally {
            umask($previousUmask);
        }

        if (is_file($dbPath)) {
            @chmod($dbPath, 0600);
        }
    }

    private function initSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                provider TEXT NOT NULL,
                model TEXT NOT NULL,
                system_prompt TEXT,
                name TEXT,
                metadata TEXT
            )
        ');

        // Migrate existing tables that lack the name column (added in P6.S11).
        // Only add the column if it doesn't already exist, since new databases
        // already have it in the CREATE TABLE above.
        $existingColumns = $this->pdo->query("PRAGMA table_info(sessions)")->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($existingColumns, 'name');
        if (!in_array('name', $columnNames, true)) {
            $this->pdo->exec('ALTER TABLE sessions ADD COLUMN name TEXT');
        }

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                tool_calls TEXT,
                tool_results TEXT,
                model TEXT,
                tokens_used INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        ');

        // listSessions() runs `ORDER BY updated_at DESC, rowid DESC` from the
        // render path (Renderer::renderSessionTabStrip()), so it is the
        // hottest query in the store and was a full table scan plus a temp
        // B-tree sort until this index existed.
        //
        // The index is deliberately ASC even though the query sorts DESC:
        // SQLite appends the rowid to every index key in ASC order, so a
        // BACKWARDS scan of an (updated_at ASC) index yields exactly
        // `updated_at DESC, rowid DESC` and the planner drops the sort
        // entirely. An (updated_at DESC) index instead leaves
        // "USE TEMP B-TREE FOR RIGHT PART OF ORDER BY", because reversing it
        // would give rowid ASC. `rowid` itself cannot be named in a
        // CREATE INDEX, so the reverse scan is the only way to satisfy both
        // sort terms from one index.
        //
        // `IF NOT EXISTS` here is also the migration: initSchema() runs on
        // every construction, so a database created before this index existed
        // gains it the next time it is opened.
        $this->pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_sessions_updated_at
            ON sessions(updated_at)
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS tool_calls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id TEXT NOT NULL,
                message_id INTEGER NOT NULL,
                tool_name TEXT NOT NULL,
                tool_args TEXT NOT NULL,
                tool_result TEXT,
                duration_ms INTEGER,
                success INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
            )
        ');
    }

    public function createSession(string $id, string $provider, string $model, ?string $systemPrompt = null, ?string $name = null): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sessions (id, provider, model, system_prompt, name)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$id, $provider, $model, $systemPrompt, $name]);
        $this->sessionWriteSeq++;
    }

    public function getSession(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getSessionByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE name = ?');
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function renameSession(string $id, string $name): void
    {
        $stmt = $this->pdo->prepare('UPDATE sessions SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$name, $id]);
        $this->sessionWriteSeq++;
    }

    /**
     * Fork a session by copying it with a new ID.
     * The new session gets a fresh timestamp but preserves all other data.
     *
     * @return string The new session ID
     */
    public function forkSession(string $id): string
    {
        $session = $this->getSession($id);
        if ($session === null) {
            throw new \InvalidArgumentException("Session not found: {$id}");
        }

        $newId = bin2hex(random_bytes(16));

        // Insert new session with forked data (but new id and fresh timestamps)
        $stmt = $this->pdo->prepare('
            INSERT INTO sessions (id, provider, model, system_prompt, name)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $newId,
            $session['provider'],
            $session['model'],
            $session['system_prompt'],
            $session['name'],
        ]);
        $this->sessionWriteSeq++;

        // Copy all messages from original session to new session
        // Build a map of old message_id => new message_id for tool_calls copying
        $messages = $this->getMessages($id);
        $oldToNewMessageId = [];
        foreach ($messages as $msg) {
            $oldMessageId = (int) $msg['id'];
            $newMessageId = $this->addMessage($newId, [
                'role' => $msg['role'],
                'content' => $msg['content'],
                'tool_calls' => $msg['tool_calls'],
                'tool_results' => $msg['tool_results'],
                'model' => $msg['model'],
                'tokens_used' => $msg['tokens_used'],
            ]);
            $oldToNewMessageId[$oldMessageId] = $newMessageId;
        }

        // Copy tool_calls records for each message, remapping message_id to the new one
        if (!empty($oldToNewMessageId)) {
            $placeholders = implode(',', array_fill(0, count($oldToNewMessageId), '?'));
            $stmt = $this->pdo->prepare("
                SELECT * FROM tool_calls
                WHERE session_id = ? AND message_id IN ({$placeholders})
            ");
            $stmt->execute(array_merge([$id], array_keys($oldToNewMessageId)));
            $toolCalls = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($toolCalls as $tc) {
                $newMessageId = $oldToNewMessageId[(int) $tc['message_id']];
                $insertStmt = $this->pdo->prepare('
                    INSERT INTO tool_calls (session_id, message_id, tool_name, tool_args, tool_result, duration_ms, success)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $insertStmt->execute([
                    $newId,
                    $newMessageId,
                    $tc['tool_name'],
                    $tc['tool_args'],
                    $tc['tool_result'],
                    $tc['duration_ms'],
                    $tc['success'],
                ]);
            }
        }

        return $newId;
    }

    public function updateSession(string $id): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE sessions SET updated_at = CURRENT_TIMESTAMP WHERE id = ?
        ');
        $stmt->execute([$id]);
        $this->sessionWriteSeq++;
    }

    public function deleteSession(string $id): void
    {
        $this->pdo->prepare('DELETE FROM tool_calls WHERE session_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM messages WHERE session_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
        $this->sessionWriteSeq++;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(int $limit = 20): array
    {
        $stamp = $this->sessionListStamp();
        $cached = $this->sessionListCache[$limit] ?? null;
        if ($cached !== null && $cached['stamp'] === $stamp) {
            return $cached['rows'];
        }

        // Tiebreak on rowid (insertion order): CURRENT_TIMESTAMP has only
        // second resolution, so sessions created milliseconds apart share an
        // updated_at and would otherwise sort non-deterministically.
        //
        // The ORDER BY is served by idx_sessions_updated_at scanned in
        // reverse — see initSchema() for why that index is declared ASC.
        // The text lives in a const so the plan test can EXPLAIN this exact
        // statement instead of a copy of it.
        $stmt = $this->pdo->prepare(self::LIST_SESSIONS_SQL);
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->sessionListQueries++;

        $this->sessionListCache[$limit] = ['stamp' => $stamp, 'rows' => $rows];

        return $rows;
    }

    /**
     * How many times {@see listSessions()} has actually reached SQLite on
     * this instance, as opposed to being answered from the memo.
     *
     * The whole point of that memo is a negative — a query that does NOT run
     * once per rendered frame — and a negative is not observable from the
     * returned rows, which are identical either way. This counter is what
     * makes it assertable, and it doubles as a cheap way to see the render
     * loop's real query rate when profiling.
     */
    public function sessionListQueries(): int
    {
        return $this->sessionListQueries;
    }

    /**
     * Cache validity token for {@see listSessions()}.
     *
     * `renderSessionTabStrip()` calls `listSessions()` once per rendered
     * frame — up to 60×/sec — for a list that only changes when a session is
     * created, renamed, touched, forked, or pruned. Re-running the query
     * every frame was the audit's "per-frame SQL query" finding; the index
     * makes it cheap, but not running it at all is cheaper still.
     *
     * Two sources of staleness have to be covered, hence two halves:
     * `$sessionWriteSeq` catches writes through this connection (which
     * `data_version` deliberately ignores), and `PRAGMA data_version` catches
     * writes through any OTHER connection to the same file — including the
     * second `PDO` handles tests open and a second sugar-crush process. The
     * pragma reads the database header only, so it is O(1) and does not touch
     * the sessions table.
     */
    private function sessionListStamp(): string
    {
        $stmt = $this->pdo->query('PRAGMA data_version');
        $dataVersion = (string) $stmt->fetchColumn();
        // Explicit, because this runs on the render path: a cursor left open
        // here holds a read transaction for as long as the statement lives,
        // and SQLite skips its automatic WAL checkpoint at any COMMIT taken
        // while a reader is open.
        $stmt->closeCursor();

        return $this->sessionWriteSeq . ':' . $dataVersion;
    }

    public function addMessage(string $sessionId, array $message): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO messages (session_id, role, content, tool_calls, tool_results, model, tokens_used)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $sessionId,
            $message['role'],
            $message['content'],
            isset($message['tool_calls']) ? json_encode($message['tool_calls']) : null,
            isset($message['tool_results']) ? json_encode($message['tool_results']) : null,
            $message['model'] ?? null,
            $message['tokens_used'] ?? null,
        ]);

        $this->updateSession($sessionId);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(string $sessionId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM messages WHERE session_id = ? ORDER BY created_at ASC
        ');
        $stmt->execute([$sessionId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($msg) {
            $msg['tool_calls'] = $msg['tool_calls'] ? json_decode($msg['tool_calls'], true) : null;
            $msg['tool_results'] = $msg['tool_results'] ? json_decode($msg['tool_results'], true) : null;
            return $msg;
        }, $messages);
    }

    public function addToolCall(string $sessionId, int $messageId, array $toolCall): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO tool_calls (session_id, message_id, tool_name, tool_args, tool_result, duration_ms, success)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $sessionId,
            $messageId,
            $toolCall['name'],
            json_encode($toolCall['arguments']),
            $toolCall['result'] ?? null,
            $toolCall['duration_ms'] ?? null,
            $toolCall['success'] ?? 1,
        ]);
    }

    /**
     * Drop sessions untouched for `$daysOld` days, with their messages and
     * tool calls, and return how many session rows went.
     *
     * Retention is OPT-IN: nothing calls this unless the user set
     * `SUGARCRUSH_SESSION_RETENTION_DAYS` — see
     * {@see \SugarCraft\Crush\Cli\Bootstrap::sessionStore()}. A destructive
     * default is not defensible here, because the session an unattended prune
     * destroys is exactly the one the user came back for: the row is unnamed
     * and old precisely because they stopped typing in it a month ago, and
     * `seedSession()` would have resumed it.
     *
     * **Named sessions are never pruned, whatever their age.** A name only
     * ever gets onto a row because the user typed `/rename` (or accepted an
     * auto-generated title), which is the one unambiguous signal in this
     * schema that a conversation was meant to be kept and found again later.
     * Age alone cannot distinguish "abandoned scratch session" from "the
     * bisect notes I come back to twice a year", so it is only allowed to
     * delete rows the user never named. That signal is WEAK, though — the
     * auto-title fires at most once per session, needs a working title
     * backend and fails silently, so an offline or key-less run leaves every
     * session unnamed forever. `$exemptSessionId` is the second guard: the
     * caller names the row it is about to resume and this will not touch it,
     * however old it is.
     *
     * The cutoff is `gmdate()`, not `date()`: `updated_at` is written by
     * SQLite's `CURRENT_TIMESTAMP`, which is UTC. A local-time cutoff east of
     * UTC deletes sessions up to 14 hours early.
     *
     * @param int         $daysOld         age threshold; `<= 0` is a no-op,
     *                                     and values are clamped to
     *                                     {@see MAX_RETENTION_DAYS} so a
     *                                     fat-fingered one cannot overflow
     *                                     into a future cutoff that matches
     *                                     every row
     * @param string|null $exemptSessionId a session that must survive
     *                                     regardless of age
     *
     * @see pruneReport() for what was deleted
     */
    public function pruneSessions(int $daysOld = 30, ?string $exemptSessionId = null): int
    {
        $this->pruneReport = [];

        if ($daysOld <= 0) {
            return 0;
        }

        $daysOld = min($daysOld, self::MAX_RETENTION_DAYS);
        $cutoffTs = strtotime("-{$daysOld} days");
        // Belt and braces: the clamp above already keeps the arithmetic in
        // range, so a cutoff at or after "now" can only mean the calculation
        // went wrong. Deleting the entire table is not an acceptable outcome
        // of a date bug.
        if ($cutoffTs === false || $cutoffTs >= time()) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', $cutoffTs);

        $sql = "
            SELECT s.id, s.name, s.updated_at,
                   (SELECT COUNT(*) FROM messages m WHERE m.session_id = s.id) AS messages
            FROM sessions s
            WHERE s.updated_at < ? AND (s.name IS NULL OR s.name = '')
        ";
        $params = [$cutoff];
        if ($exemptSessionId !== null) {
            $sql .= ' AND s.id <> ?';
            $params[] = $exemptSessionId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $victims = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        if ($victims === []) {
            return 0;
        }

        $ids = [];
        foreach ($victims as $victim) {
            $ids[] = (string) $victim['id'];
            $this->pruneReport[] = [
                'id' => (string) $victim['id'],
                'name' => $victim['name'] === null ? null : (string) $victim['name'],
                'updated_at' => (string) $victim['updated_at'],
                'messages' => (int) $victim['messages'],
            ];
        }

        // Chunked because SQLite caps bound parameters per statement and an
        // install that has never pruned can expire hundreds of rows at once.
        foreach (array_chunk($ids, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            foreach (['tool_calls', 'messages'] as $table) {
                $this->pdo
                    ->prepare("DELETE FROM {$table} WHERE session_id IN ({$placeholders})")
                    ->execute($chunk);
            }
            $this->pdo
                ->prepare("DELETE FROM sessions WHERE id IN ({$placeholders})")
                ->execute($chunk);
        }

        $this->sessionWriteSeq++;

        return count($ids);
    }

    /**
     * What the most recent {@see pruneSessions()} call deleted, oldest-first
     * as SQLite returned it.
     *
     * Retention deletes conversations. Returning only a count made the one
     * caller discard it and print nothing, so a user who enabled retention
     * had no way to learn WHICH sessions went.
     *
     * WHAT THIS USED TO SAY about the destination: "this is what
     * {@see \SugarCraft\Crush\Cli\Bootstrap::sessionStore()} reports on
     * stderr".
     * WHAT IS TRUE NOW: since round 42 the caller splits this report across two
     * channels. The COUNT goes to the launch-notice seam and reaches both the
     * transcript and stderr; the per-session rows THIS method returns are the
     * half that still goes to stderr alone.
     * WHY THAT STILL EARNS THE SHAPE BELOW: the split is the reason a full row
     * per session is worth returning at all. One transcript row per deleted
     * session would be a per-entry fan-out into a list the model is re-sent
     * every turn, so the transcript carries the aggregate and stderr carries
     * this — the complete, unclipped record, with the id, the last-used stamp
     * and the message count the user needs to recognise what went.
     *
     * @return array<int, array{id: string, name: ?string, updated_at: string, messages: int}>
     */
    public function pruneReport(): array
    {
        return $this->pruneReport;
    }

    /**
     * Expose the PDO connection for use by EnhancedSessionStore.
     *
     * This avoids fragile ReflectionClass access to the private $pdo property.
     * Any internal refactor of SessionStore that changes PDO access will now
     * be explicitly visible through this interface.
     *
     * @internal Writing through the returned handle bypasses this class's
     *           cache bookkeeping: `listSessions()` is memoised against
     *           `$sessionWriteSeq` + `PRAGMA data_version`, and neither moves
     *           for a write issued on THIS connection from outside this
     *           class, so such a write stays invisible until something else
     *           invalidates the memo. Same-connection writes belong on the
     *           public methods; {@see EnhancedSessionStore} only ever uses
     *           this handle for its own tables.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
