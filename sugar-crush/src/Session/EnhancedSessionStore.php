<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

use PDO;

/**
 * SQLite session persistence with enhanced metadata tracking.
 *
 * Wraps SessionStore to add columns for session summary, tasks,
 * modified files, and agent states to enable meaningful session
 * resumption and context replay.
 */
final class EnhancedSessionStore
{
    private PDO $pdo;
    private SessionStore $inner;

    public function __construct(string $dbPath)
    {
        $previousUmask = umask(0077);
        try {
            $this->pdo = new PDO("sqlite:$dbPath");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');
            $this->initSchema();
            $this->initEnhancedSchema();
        } finally {
            umask($previousUmask);
        }

        if (is_file($dbPath)) {
            @chmod($dbPath, 0600);
        }

        $this->inner = new SessionStore($dbPath);
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
                metadata TEXT
            )
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
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        ');

        // Index for efficient checkpoint lookup by session and index
        $this->pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_checkpoints_session_index
            ON checkpoints(session_id, "index" DESC)
        ');
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
            lastActivity: new \DateTimeImmutable($row['last_activity']),
        );
    }

    /**
     * Save enhanced metadata for a session.
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
            $meta->lastActivity->format('Y-m-d H:i:s'),
        ]);
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
     * Save a checkpoint snapshot for the session.
     *
     * @param string $sessionId The session ID
     * @param array $chatState The chat state to snapshot (messages, input buffer, agent context)
     * @return int The checkpoint index that was assigned
     */
    public function saveCheckpoint(string $sessionId, array $chatState): int
    {
        // Get the next index for this session
        $stmt = $this->pdo->prepare('
            SELECT COALESCE(MAX("index"), -1) + 1 FROM checkpoints WHERE session_id = ?
        ');
        $stmt->execute([$sessionId]);
        $nextIndex = (int) $stmt->fetchColumn();

        // Insert the new checkpoint
        $insertStmt = $this->pdo->prepare('
            INSERT INTO checkpoints (session_id, "index", state_data, created_at)
            VALUES (?, ?, ?, ?)
        ');
        $insertStmt->execute([
            $sessionId,
            $nextIndex,
            json_encode($chatState),
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        // Enforce the 100 checkpoint limit: delete oldest checkpoints if over limit
        $this->pruneOldCheckpoints($sessionId, self::MAX_CHECKPOINTS_PER_SESSION);

        return $nextIndex;
    }

    /**
     * Retrieve a specific checkpoint by index.
     *
     * @param string $sessionId The session ID
     * @param int $index The checkpoint index
     * @return array|null The checkpoint state data, or null if not found
     */
    public function getCheckpoint(string $sessionId, int $index): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT state_data FROM checkpoints WHERE session_id = ? AND "index" = ?
        ');
        $stmt->execute([$sessionId, $index]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return json_decode($row['state_data'], true);
    }

    /**
     * List recent checkpoints for a session.
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
            WHERE session_id = ?
            ORDER BY "index" DESC
            LIMIT ?
        ');
        $stmt->execute([$sessionId, $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return [
                'index' => (int) $row['index'],
                'created_at' => $row['created_at'],
                'state_data' => json_decode($row['state_data'], true),
            ];
        }, $rows);
    }

    /**
     * Restore a checkpoint and return its state data.
     *
     * @param string $sessionId The session ID
     * @param int $index The checkpoint index to restore
     * @return array|null The restored state data, or null if not found
     */
    public function restoreCheckpoint(string $sessionId, int $index): ?array
    {
        // First verify the checkpoint exists
        $state = $this->getCheckpoint($sessionId, $index);
        if ($state === null) {
            return null;
        }

        // Delete all checkpoints with index >= the restored index (they are now invalid)
        $deleteStmt = $this->pdo->prepare('
            DELETE FROM checkpoints WHERE session_id = ? AND "index" >= ?
        ');
        $deleteStmt->execute([$sessionId, $index]);

        return $state;
    }

    /**
     * Prune old checkpoints to keep the count under the limit.
     */
    private function pruneOldCheckpoints(string $sessionId, int $maxCheckpoints): void
    {
        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM checkpoints WHERE session_id = ?');
        $countStmt->execute([$sessionId]);
        $count = (int) $countStmt->fetchColumn();

        if ($count <= $maxCheckpoints) {
            return;
        }

        // Delete oldest checkpoints to bring count down to maxCheckpoints
        $deleteCount = $count - $maxCheckpoints;
        $deleteStmt = $this->pdo->prepare('
            DELETE FROM checkpoints
            WHERE session_id = ? AND id IN (
                SELECT id FROM checkpoints WHERE session_id = ?
                ORDER BY "index" ASC
                LIMIT ?
            )
        ');
        $deleteStmt->execute([$sessionId, $sessionId, $deleteCount]);
    }

    // =======================================================================
    // Delegated methods from SessionStore
    // =======================================================================

    public function createSession(string $id, string $provider, string $model, ?string $systemPrompt = null): void
    {
        $this->inner->createSession($id, $provider, $model, $systemPrompt);
    }

    public function getSession(string $id): ?array
    {
        return $this->inner->getSession($id);
    }

    public function updateSession(string $id): void
    {
        $this->inner->updateSession($id);
    }

    public function deleteSession(string $id): void
    {
        $this->inner->deleteSession($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(int $limit = 20): array
    {
        return $this->inner->listSessions($limit);
    }

    public function addMessage(string $sessionId, array $message): int
    {
        return $this->inner->addMessage($sessionId, $message);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(string $sessionId): array
    {
        return $this->inner->getMessages($sessionId);
    }

    public function addToolCall(string $sessionId, int $messageId, array $toolCall): void
    {
        $this->inner->addToolCall($sessionId, $messageId, $toolCall);
    }

    public function pruneSessions(int $daysOld = 30): int
    {
        return $this->inner->pruneSessions($daysOld);
    }
}
