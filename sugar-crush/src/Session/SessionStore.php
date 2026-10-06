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
    public const LIST_SESSIONS_SQL = "SELECT * FROM sessions WHERE kind IN ('main', 'branch') AND archived_at IS NULL ORDER BY updated_at DESC, rowid DESC LIMIT ?";

    /**
     * Columns added to `sessions` after its first release, with the DDL
     * {@see initSchema()} uses to add each one to an older database
     * (Appendix P §3.1). They are also in the CREATE TABLE, so a fresh
     * database never takes the ALTER path.
     *
     *  - `kind`: a {@see SessionKind}; only `main`/`branch` rows reach the
     *    default list, the tab strip and `--continue`.
     *  - `parent_id`, `parent_call_id`, `agent`, `status`: the link a branch,
     *    sub-agent or background row keeps to the session it came from.
     *  - `title_source`: a {@see TitleSource}; who set `name`.
     *  - `pinned`, `archived_at`: user curation. Pinned rows survive
     *    retention; archived rows leave the default list.
     *  - `cwd`, `git_branch`: where the session was opened.
     *  - `turns`, `last_preview`: what the picker shows in place of the
     *    system prompt every session shares.
     */
    private const MIGRATED_COLUMNS = [
        'name' => 'TEXT',
        'kind' => "TEXT NOT NULL DEFAULT 'main'",
        'parent_id' => 'TEXT',
        'parent_call_id' => 'TEXT',
        'agent' => 'TEXT',
        'status' => 'TEXT',
        'title_source' => 'TEXT',
        'pinned' => 'INTEGER NOT NULL DEFAULT 0',
        'archived_at' => 'DATETIME',
        'cwd' => 'TEXT',
        'git_branch' => 'TEXT',
        'turns' => 'INTEGER NOT NULL DEFAULT 0',
        'last_preview' => 'TEXT',
    ];

    /**
     * The values {@see markSubAgentStatus()} accepts for a child row's
     * `status` column.
     */
    public const CHILD_STATUSES = ['running', 'complete', 'failed', 'cancelled', 'interrupted'];

    /** Byte cap on `last_preview`; cut on a UTF-8 boundary. */
    public const PREVIEW_MAX_BYTES = 160;

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

    /** Open {@see immediateTransaction()} frames on this connection. */
    private int $transactionDepth = 0;

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
                metadata TEXT,
                kind TEXT NOT NULL DEFAULT \'main\',
                parent_id TEXT,
                parent_call_id TEXT,
                agent TEXT,
                status TEXT,
                title_source TEXT,
                pinned INTEGER NOT NULL DEFAULT 0,
                archived_at DATETIME,
                cwd TEXT,
                git_branch TEXT,
                turns INTEGER NOT NULL DEFAULT 0,
                last_preview TEXT
            )
        ');

        // Migrate existing tables that lack a later column (`name` arrived in
        // P6.S11, the rest with Appendix P's session model). Only missing
        // columns are added, since new databases already have them all from
        // the CREATE TABLE above, so this is idempotent on every open.
        $existingColumns = $this->pdo->query("PRAGMA table_info(sessions)")->fetchAll(\PDO::FETCH_ASSOC);
        $columnNames = array_column($existingColumns, 'name');
        foreach (self::MIGRATED_COLUMNS as $column => $definition) {
            if (!in_array($column, $columnNames, true)) {
                $this->pdo->exec("ALTER TABLE sessions ADD COLUMN {$column} {$definition}");
            }
        }

        // childrenOf()/childCount() and deleteSession()'s descendant walk all
        // look rows up by parent. There is deliberately NO index over
        // (kind, archived_at, ...): LIST_SESSIONS_SQL must stay served by the
        // reverse idx_sessions_updated_at scan below, and an index the
        // planner could prefer for its WHERE would bring the temp B-tree sort
        // back (SessionIndexAndRetentionTest EXPLAINs exactly that).
        $this->pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_sessions_parent_id
            ON sessions(parent_id)
        ');

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

    /**
     * @param ?string $cwd       the working directory the session was opened in
     * @param ?string $gitBranch the git branch checked out there, if any
     */
    public function createSession(
        string $id,
        string $provider,
        string $model,
        ?string $systemPrompt = null,
        ?string $name = null,
        ?string $cwd = null,
        ?string $gitBranch = null,
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO sessions (id, provider, model, system_prompt, name, cwd, git_branch)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$id, $provider, $model, $systemPrompt, $name, self::blankToNull($cwd), self::blankToNull($gitBranch)]);
        $this->sessionWriteSeq++;
    }

    /**
     * Open a session under $parentId — a Task sub-agent's transcript or a
     * background run — and return its id.
     *
     * The child inherits the parent's `cwd` and `git_branch`, and starts
     * `running`. SQLite cannot add a foreign key with `ALTER TABLE`, so the
     * parent is checked here, and {@see deleteSession()} walks the link in
     * code.
     *
     * @throws \InvalidArgumentException for a `main`/`branch` kind or a
     *                                   parent that does not exist
     */
    public function createChildSession(
        string $parentId,
        SessionKind $kind,
        ?string $agent,
        ?string $parentCallId,
        string $provider,
        string $model,
        ?string $name = null,
    ): string {
        if ($kind !== SessionKind::Subagent && $kind !== SessionKind::Background) {
            throw new \InvalidArgumentException("A child session is a subagent or background row, not '{$kind->value}'");
        }

        return $this->immediateTransaction(function () use ($parentId, $kind, $agent, $parentCallId, $provider, $model, $name): string {
            $parent = $this->getSession($parentId);
            if ($parent === null) {
                throw new \InvalidArgumentException("Session not found: {$parentId}");
            }

            $id = bin2hex(random_bytes(16));
            $this->pdo->prepare('
                INSERT INTO sessions (id, provider, model, name, kind, parent_id, parent_call_id, agent, status, cwd, git_branch)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $id,
                $provider,
                $model,
                self::blankToNull($name),
                $kind->value,
                $parentId,
                self::blankToNull($parentCallId),
                self::blankToNull($agent),
                'running',
                $parent['cwd'] ?? null,
                $parent['git_branch'] ?? null,
            ]);
            $this->sessionWriteSeq++;

            return $id;
        });
    }

    public function getSession(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * The session carrying $name, or null.
     *
     * Names are not unique in the schema, and databases written before
     * {@see forkSession()} stopped copying the parent's name verbatim already
     * hold duplicates (audit SES-2). Without an ORDER BY SQLite returned
     * whichever row its plan met first — in practice the PARENT — so
     * `--resume my-work` reopened the conversation the user had branched away
     * from. The most recently used row is the one a user typing the name
     * means; rowid breaks the one-second `updated_at` tie deterministically.
     */
    public function getSessionByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE name = ? ORDER BY updated_at DESC, rowid DESC LIMIT 1');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row ?: null;
    }

    /**
     * Name $id and record who named it; true when the row was renamed.
     *
     * A {@see TitleSource::User} rename always lands. A
     * {@see TitleSource::Auto} one — the auto-titler's — lands only on a row
     * that is still unnamed and was never named by the user: its request is
     * fire-and-forget and arrives after the first reply, so a `/rename`
     * typed while it was in flight used to be overwritten by the generated
     * title (audit B2). The guard lives in the UPDATE itself rather than a
     * read-then-write so a rename from a second process in between is
     * honoured too.
     */
    public function renameSession(string $id, string $name, TitleSource $source = TitleSource::User): bool
    {
        $sql = 'UPDATE sessions SET name = ?, title_source = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?';
        if ($source === TitleSource::Auto) {
            $sql .= " AND (name IS NULL OR name = '') AND (title_source IS NULL OR title_source = 'auto')";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$name, $source->value, $id]);
        $renamed = $stmt->rowCount() > 0;
        $this->sessionWriteSeq++;

        return $renamed;
    }

    /**
     * The auto-titler's write: {@see renameSession()} with
     * {@see TitleSource::Auto}.
     */
    public function renameSessionIfUnnamed(string $id, string $name): bool
    {
        return $this->renameSession($id, $name, TitleSource::Auto);
    }

    /**
     * Make $id unnamed again — name and {@see TitleSource} both back to NULL —
     * so the auto-titler may name it (roadmap P-A4); true when the row exists.
     *
     * The "back to auto" write a blank rename and `/rename --auto` need. A
     * blank USER title cannot stand in for it: {@see renameSession()} with
     * `''` would record `title_source = 'user'`, and the auto-titler's guarded
     * UPDATE refuses a user-named row for good. Recency is bumped like any
     * rename: the user just touched the session.
     */
    public function clearSessionName(string $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE sessions SET name = NULL, title_source = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$id]);
        $cleared = $stmt->rowCount() > 0;
        $this->sessionWriteSeq++;

        return $cleared;
    }

    /**
     * Pin or unpin $id; true when the row exists. Pinned rows list first when
     * a {@see SessionQuery} asks for it and are exempt from
     * {@see pruneSessions()}. Recency (`updated_at`) is left alone: pinning
     * is not using the session.
     */
    public function setPinned(string $id, bool $pinned): bool
    {
        return $this->updateFlag('UPDATE sessions SET pinned = ? WHERE id = ?', [$pinned ? 1 : 0, $id]);
    }

    /**
     * Soft-hide $id from the default list, the tab strip and `--continue`;
     * true when a live row was archived. Nothing is deleted.
     */
    public function archive(string $id): bool
    {
        return $this->updateFlag('UPDATE sessions SET archived_at = CURRENT_TIMESTAMP WHERE id = ? AND archived_at IS NULL', [$id]);
    }

    /** Undo {@see archive()}; true when an archived row came back. */
    public function unarchive(string $id): bool
    {
        return $this->updateFlag('UPDATE sessions SET archived_at = NULL WHERE id = ? AND archived_at IS NOT NULL', [$id]);
    }

    /**
     * Record a sub-agent or background child's outcome; true when such a row
     * was updated. A `main`/`branch` row has no status and is left alone.
     *
     * @param string $status one of {@see CHILD_STATUSES}
     *
     * @throws \InvalidArgumentException for any other status
     */
    public function markSubAgentStatus(string $id, string $status): bool
    {
        if (!in_array($status, self::CHILD_STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown session status '{$status}'; expected one of " . implode(', ', self::CHILD_STATUSES));
        }

        return $this->updateFlag(
            "UPDATE sessions SET status = ? WHERE id = ? AND kind IN ('subagent', 'background')",
            [$status, $id],
        );
    }

    /**
     * Count one user turn on $id and keep its prompt as the row's preview;
     * true when the row exists.
     *
     * The preview is what the session picker shows instead of the system
     * prompt every session shares (audit B3). Whitespace runs collapse to one
     * space and the text is cut to {@see PREVIEW_MAX_BYTES} on a UTF-8
     * boundary. It is stored unsanitized otherwise — it is the user's own
     * text — so it must be scrubbed before it is painted. `updated_at` is
     * left to the transcript save, which owns recency.
     */
    public function recordTurn(string $id, string $prompt): bool
    {
        $preview = trim(preg_replace('/\s+/u', ' ', $prompt) ?? $prompt);
        if (strlen($preview) > self::PREVIEW_MAX_BYTES) {
            $preview = mb_strcut($preview, 0, self::PREVIEW_MAX_BYTES, 'UTF-8');
        }

        return $this->updateFlag(
            'UPDATE sessions SET turns = turns + 1, last_preview = ? WHERE id = ?',
            [$preview === '' ? null : $preview, $id],
        );
    }

    /**
     * Rows matching $query, newest first (pinned first when asked).
     *
     * Unlike {@see listSessions()} this is not memoised: the tab strip's
     * per-frame read stays on that method, and this one serves the picker,
     * the CLI and child lookups, which run on a user action.
     *
     * @return list<SessionRow>
     */
    public function listSessionsFiltered(SessionQuery $query): array
    {
        [$where, $params] = self::queryConditions($query);
        $sql = 'SELECT * FROM sessions' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY ' . ($query->pinnedFirst ? 'pinned DESC, ' : '') . 'updated_at DESC, rowid DESC'
            . ' LIMIT ? OFFSET ?';
        $params[] = $query->limit;
        $params[] = $query->offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return array_map(SessionRow::fromArray(...), $rows);
    }

    /**
     * Every direct child of $id — branches, sub-agents and background runs,
     * archived or not — newest first.
     *
     * @return list<SessionRow>
     */
    public function childrenOf(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE parent_id = ? ORDER BY updated_at DESC, rowid DESC');
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return array_map(SessionRow::fromArray(...), $rows);
    }

    /**
     * Direct-child counts for $ids, one query for a whole picker page; an id
     * with no children maps to 0.
     *
     * @param list<string> $ids
     *
     * @return array<string, int>
     */
    public function childCount(array $ids): array
    {
        $counts = array_fill_keys(array_map('strval', $ids), 0);
        foreach (array_chunk(array_keys($counts), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare("SELECT parent_id, COUNT(*) FROM sessions WHERE parent_id IN ({$placeholders}) GROUP BY parent_id");
            $stmt->execute(array_map('strval', $chunk));
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$parent, $count]) {
                $counts[(string) $parent] = (int) $count;
            }
            $stmt->closeCursor();
        }

        return $counts;
    }

    /**
     * The WHERE terms and bound values for $query.
     *
     * @return array{0: list<string>, 1: list<mixed>}
     */
    private static function queryConditions(SessionQuery $query): array
    {
        $where = [];
        $params = [];
        if ($query->kinds !== []) {
            $where[] = 'kind IN (' . implode(',', array_fill(0, count($query->kinds), '?')) . ')';
            foreach ($query->kinds as $kind) {
                $params[] = $kind->value;
            }
        }
        if ($query->archivedOnly) {
            $where[] = 'archived_at IS NOT NULL';
        } elseif (!$query->includeArchived) {
            $where[] = 'archived_at IS NULL';
        }
        if ($query->parentId !== null) {
            $where[] = 'parent_id = ?';
            $params[] = $query->parentId;
        }
        if ($query->search !== null) {
            $like = '%' . addcslashes($query->search, '%_\\') . '%';
            $where[] = "(name LIKE ? ESCAPE '\\' OR last_preview LIKE ? ESCAPE '\\' OR id LIKE ? ESCAPE '\\')";
            array_push($params, $like, $like, $like);
        }

        return [$where, $params];
    }

    /**
     * Run a one-row UPDATE that every caller reports as "did it apply", and
     * invalidate the list memo.
     *
     * @param list<mixed> $params
     */
    private function updateFlag(string $sql, array $params): bool
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $this->sessionWriteSeq++;

        return $stmt->rowCount() > 0;
    }

    /**
     * The branch checked out in the git work tree containing $dir, or null
     * outside a repository or on a detached HEAD — what a new session records
     * as `git_branch` (audit B1: the picker's Ctrl+B filter compared against a
     * column nothing ever wrote).
     *
     * Read from `HEAD` rather than by running git: it is called on the launch
     * path, a file read cannot hang, and it spawns no child to account for.
     * The answer matches `git branch --show-current`, which is what the
     * picker compares it with. A linked worktree's `.git` FILE is followed to
     * its own gitdir, whose HEAD is that worktree's.
     */
    public static function gitBranchAt(string $dir): ?string
    {
        $dir = rtrim($dir, '/');
        for ($depth = 0; $dir !== '' && $depth < 64; $depth++) {
            $dotGit = $dir . '/.git';
            $gitDir = null;
            if (is_dir($dotGit)) {
                $gitDir = $dotGit;
            } elseif (is_file($dotGit)) {
                $pointer = @file_get_contents($dotGit, false, null, 0, 4096);
                if (\is_string($pointer) && preg_match('/^gitdir:\s*(.+?)\s*$/m', $pointer, $m) === 1) {
                    $gitDir = str_starts_with($m[1], '/') ? $m[1] : $dir . '/' . $m[1];
                }
            }
            if ($gitDir !== null) {
                $head = @file_get_contents($gitDir . '/HEAD', false, null, 0, 4096);
                if (!\is_string($head) || preg_match('#^ref:\s*refs/heads/(\S+)\s*$#', $head, $m) !== 1) {
                    return null;
                }

                return $m[1];
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * Fork a session by copying it with a new ID.
     *
     * The new session gets fresh timestamps and keeps provider, model and
     * system prompt. It does NOT keep the parent's name verbatim: two rows
     * sharing a name made `--resume <name>` ambiguous, and since named
     * sessions are exempt from {@see pruneSessions()} the duplicate could
     * never age out either (audit SES-2). A named parent yields
     * "<name> (branch)", then "<name> (branch 2)", … — see {@see branchName()}.
     *
     * This copies only this class's tables. The conversation itself lives in
     * {@see EnhancedSessionStore}'s transcript/checkpoint tables, which that
     * class's forkSession() copies inside the same transaction as this call.
     *
     * The fork records where it came from: `parent_id` names $id and `kind`
     * is $kind (`branch` for `/branch`), so the picker can group it under its
     * source. It carries the source's working directory, git branch, turn
     * count and last prompt, since it carries the conversation they describe.
     *
     * @param SessionKind $kind `Branch`, or `Background` for a copy sent off to
     *                          run as a background session
     *
     * @return string The new session ID
     *
     * @throws \InvalidArgumentException for a missing session or another kind
     */
    public function forkSession(string $id, SessionKind $kind = SessionKind::Branch): string
    {
        if ($kind !== SessionKind::Branch && $kind !== SessionKind::Background) {
            throw new \InvalidArgumentException("A fork is a branch or background session, not '{$kind->value}'");
        }

        return $this->immediateTransaction(fn (): string => $this->forkSessionRows($id, $kind));
    }

    private function forkSessionRows(string $id, SessionKind $kind): string
    {
        $session = $this->getSession($id);
        if ($session === null) {
            throw new \InvalidArgumentException("Session not found: {$id}");
        }

        $newId = bin2hex(random_bytes(16));
        $name = $this->branchName($session['name'] === null ? null : (string) $session['name']);

        // Insert new session with forked data (but new id and fresh timestamps)
        $stmt = $this->pdo->prepare('
            INSERT INTO sessions (id, provider, model, system_prompt, name, kind, parent_id, title_source, cwd, git_branch, turns, last_preview)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $newId,
            $session['provider'],
            $session['model'],
            $session['system_prompt'],
            $name,
            $kind->value,
            $id,
            // The "(branch)" name is derived from the parent's, so it keeps
            // the parent's provenance: an auto-titled parent's branch is still
            // fair game for nothing but a user rename, like any named row.
            $name === null ? null : ($session['title_source'] ?? null),
            $session['cwd'] ?? null,
            $session['git_branch'] ?? null,
            (int) ($session['turns'] ?? 0),
            $session['last_preview'] ?? null,
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

    /**
     * The name a fork of a session called $name gets: null for an unnamed
     * parent, otherwise the first free "<base> (branch)", "<base> (branch 2)",
     * … where <base> is $name with any branch suffix stripped, so branching a
     * branch reads "my-work (branch 2)" rather than nesting suffixes.
     */
    private function branchName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        $base = preg_replace('/ \(branch(?: \d+)?\)$/', '', $name) ?? $name;
        $taken = $this->pdo->prepare('SELECT 1 FROM sessions WHERE name = ? LIMIT 1');
        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? "{$base} (branch)" : "{$base} (branch {$n})";
            $taken->execute([$candidate]);
            $exists = $taken->fetchColumn() !== false;
            $taken->closeCursor();
            if (!$exists) {
                return $candidate;
            }
        }
    }

    /**
     * Run $work inside one `BEGIN IMMEDIATE` transaction on this connection,
     * or directly when one is already open (the outer caller owns it).
     *
     * IMMEDIATE rather than PDO's deferred `BEGIN`: every caller reads and
     * then writes (allocate a checkpoint index, then insert it; look a blob
     * up, then insert it). A deferred transaction takes the write lock only at
     * the first write, so two processes could both read the same
     * `MAX("index")` before either wrote it (audit SES-3), and the loser of a
     * read→write upgrade gets SQLITE_BUSY without the busy handler being
     * consulted. IMMEDIATE takes the write lock up front and waits on the
     * connection's busy timeout instead.
     *
     * It is also what makes a save cost one commit — one WAL fsync — instead
     * of one per autocommitted INSERT (audit 15b-21).
     *
     * @internal shared with {@see EnhancedSessionStore}, which writes its own
     *           tables through {@see getPdo()} and needs the same transaction
     *           to span a {@see forkSession()} call.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function immediateTransaction(\Closure $work): mixed
    {
        // Our own depth, not PDO::inTransaction() alone: a transaction opened
        // by a raw `BEGIN IMMEDIATE` is visible to inTransaction() only on
        // builds whose pdo_sqlite asks SQLite for its autocommit state. CI's
        // PHP does not, so a nested fork (EnhancedSessionStore::forkSession()
        // around SessionStore::forkSession()) issued a second BEGIN and died
        // "cannot start a transaction within a transaction", and the failure
        // path skipped its ROLLBACK. inTransaction() still covers a
        // beginTransaction() some other caller of getPdo() opened.
        if ($this->transactionDepth > 0 || $this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->transactionDepth++;
        try {
            $result = $work();
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            // A failed COMMIT can leave SQLite having already rolled back on
            // its own; swallow that ROLLBACK's "no transaction is active" so
            // the original error is the one that surfaces.
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\PDOException) {
            }
            throw $e;
        } finally {
            $this->transactionDepth--;
        }

        return $result;
    }

    public function updateSession(string $id): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE sessions SET updated_at = CURRENT_TIMESTAMP WHERE id = ?
        ');
        $stmt->execute([$id]);
        $this->sessionWriteSeq++;
    }

    /**
     * Delete $id with its messages and tool calls, and return every session
     * id that went (the enhanced tables follow by FK cascade).
     *
     * Children are handled in code inside the same transaction, because
     * SQLite cannot add a foreign key with `ALTER TABLE`:
     *  - `subagent` children always go with their parent, recursively: they
     *    are hidden from every default list and would otherwise be orphans no
     *    one can reach.
     *  - branch and background children are the user's own conversations, so
     *    they are DETACHED (`parent_id` cleared) and kept — unless
     *    $withChildren, which deletes every descendant.
     *
     * @return list<string> the deleted ids, $id first
     */
    public function deleteSession(string $id, bool $withChildren = false): array
    {
        return $this->immediateTransaction(fn (): array => $this->deleteSessionTrees([$id], $withChildren));
    }

    /**
     * Delete each root in $ids and its descendants as {@see deleteSession()}
     * describes; the caller holds the transaction.
     *
     * @param list<string> $ids
     *
     * @return list<string> the deleted ids, roots first
     */
    private function deleteSessionTrees(array $ids, bool $withChildren): array
    {
        $delete = [];
        $detach = [];
        $queue = [];
        foreach ($ids as $id) {
            $delete[$id] = true;
            $queue[] = $id;
        }

        $children = $this->pdo->prepare('SELECT id, kind FROM sessions WHERE parent_id = ?');
        while ($queue !== []) {
            $parent = array_shift($queue);
            $children->execute([$parent]);
            foreach ($children->fetchAll(PDO::FETCH_NUM) as [$childId, $kind]) {
                $childId = (string) $childId;
                if (isset($delete[$childId]) || isset($detach[$childId])) {
                    continue; // a cycle, or a root reached again
                }
                if ($withChildren || $kind === SessionKind::Subagent->value) {
                    $delete[$childId] = true;
                    $queue[] = $childId;
                } else {
                    $detach[$childId] = true;
                }
            }
        }
        $children->closeCursor();

        $deleted = array_map('strval', array_keys($delete));
        // Chunked because SQLite caps bound parameters per statement and an
        // install that has never pruned can expire hundreds of rows at once.
        foreach (array_chunk($deleted, 400) as $chunk) {
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
        foreach (array_chunk(array_map('strval', array_keys($detach)), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $this->pdo
                ->prepare("UPDATE sessions SET parent_id = NULL WHERE id IN ({$placeholders})")
                ->execute($chunk);
        }
        $this->sessionWriteSeq++;

        return $deleted;
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
        // `created_at` has one-second resolution, so messages added in the
        // same second — every row a forkSession() copies — tie on it; the
        // AUTOINCREMENT id is insertion order and breaks the tie (audit SES-6).
        $stmt = $this->pdo->prepare('
            SELECT * FROM messages WHERE session_id = ? ORDER BY created_at ASC, id ASC
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
     * **Pinned sessions are never pruned either**, named or not: pinning is
     * the explicit form of the keep signal. Sub-agent rows are never victims
     * in their own right — they go with their parent — and a pruned parent's
     * branch and background children are detached rather than deleted (see
     * {@see deleteSession()}).
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
              AND s.pinned = 0 AND s.kind <> 'subagent'
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

        $this->immediateTransaction(fn (): array => $this->deleteSessionTrees($ids, false));

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
     * session would be a per-entry fan-out that buries every other launch
     * notice under a list the user scrolls past (launch notices are UI-only
     * rows since audit 15b-03, so the cost is transcript clutter, not tokens),
     * so the transcript carries the aggregate and stderr carries this — the
     * complete, unclipped record, with the id, the last-used stamp and the
     * message count the user needs to recognise what went.
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
