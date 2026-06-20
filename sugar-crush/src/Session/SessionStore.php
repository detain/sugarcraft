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
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $this->pdo = new PDO("sqlite:$dbPath");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        // Foreign keys are OFF by default in SQLite and must be enabled per
        // connection. Without this the FK/ON DELETE CASCADE clauses below are
        // inert and orphaned rows can accumulate.
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->initSchema();
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

    public function createSession(string $id, string $provider, string $model, ?string $systemPrompt = null): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO sessions (id, provider, model, system_prompt)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$id, $provider, $model, $systemPrompt]);
    }

    public function getSession(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateSession(string $id): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE sessions SET updated_at = CURRENT_TIMESTAMP WHERE id = ?
        ');
        $stmt->execute([$id]);
    }

    public function deleteSession(string $id): void
    {
        $this->pdo->prepare('DELETE FROM tool_calls WHERE session_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM messages WHERE session_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(int $limit = 20): array
    {
        // Tiebreak on rowid (insertion order): CURRENT_TIMESTAMP has only
        // second resolution, so sessions created milliseconds apart share an
        // updated_at and would otherwise sort non-deterministically.
        $stmt = $this->pdo->prepare('
            SELECT * FROM sessions
            ORDER BY updated_at DESC, rowid DESC
            LIMIT ?
        ');
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    public function pruneSessions(int $daysOld = 30): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-$daysOld days"));

        $stmt = $this->pdo->prepare('
            DELETE FROM tool_calls
            WHERE session_id IN (SELECT id FROM sessions WHERE updated_at < ?)
        ');
        $stmt->execute([$cutoff]);

        $stmt = $this->pdo->prepare('
            DELETE FROM messages
            WHERE session_id IN (SELECT id FROM sessions WHERE updated_at < ?)
        ');
        $stmt->execute([$cutoff]);

        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE updated_at < ?');
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }
}
