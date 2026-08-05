<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * SQLite-backed task list for team coordination.
 *
 * Uses file-based locking (flock) to ensure safe concurrent access when
 * multiple teammate agents access the same database from different processes.
 * The table is auto-created on first construction (migration).
 */
final class TaskList
{
    /** @var array<string, \SQLite3> cache of open connections, keyed by dbPath */
    private static array $connections = [];

    private readonly \SQLite3 $db;

    public function __construct(
        private readonly string $dbPath,
    ) {
        $this->db = $this->getConnection($dbPath);
        $this->migrate();
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    private function migrate(): void
    {
        $this->db->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS tasks (
                id              TEXT    NOT NULL PRIMARY KEY,
                team_id         TEXT    NOT NULL,
                title           TEXT    NOT NULL,
                description     TEXT    NOT NULL,
                prompt          TEXT    NOT NULL,
                assigned_to     TEXT,
                status          TEXT    NOT NULL DEFAULT 'pending',
                result          TEXT,
                error           TEXT,
                created_at      TEXT    NOT NULL,
                claimed_at      TEXT,
                completed_at    TEXT,
                depends_on      TEXT    NOT NULL DEFAULT '[]'
            );
            SQL
        );
    }

    // -------------------------------------------------------------------------
    // CRUD — write operations guard with exclusive flock
    // -------------------------------------------------------------------------

    /**
     * Add a new task to the list.
     *
     * @return string The task ID (same as $task->id)
     */
    public function addTask(Task $task): string
    {
        $handle = $this->openForWrite();

        $stmt = $this->db->prepare(
            <<<'SQL'
            INSERT INTO tasks (id, team_id, title, description, prompt, assigned_to,
                               status, result, error, created_at, claimed_at,
                               completed_at, depends_on)
            VALUES (:id, :team_id, :title, :description, :prompt, :assigned_to,
                    :status, :result, :error, :created_at, :claimed_at,
                    :completed_at, :depends_on)
            SQL
        );

        $stmt->bindValue(':id', $task->id, \SQLITE3_TEXT);
        $stmt->bindValue(':team_id', $task->teamId, \SQLITE3_TEXT);
        $stmt->bindValue(':title', $task->title, \SQLITE3_TEXT);
        $stmt->bindValue(':description', $task->description, \SQLITE3_TEXT);
        $stmt->bindValue(':prompt', $task->prompt, \SQLITE3_TEXT);
        $stmt->bindValue(':assigned_to', $task->assignedTo, \SQLITE3_TEXT);
        $stmt->bindValue(':status', $task->status->value, \SQLITE3_TEXT);
        $stmt->bindValue(':result', $task->result, \SQLITE3_TEXT);
        $stmt->bindValue(':error', $task->error, \SQLITE3_TEXT);
        $stmt->bindValue(':created_at', $task->createdAt->format(\DateTimeImmutable::ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':claimed_at', $task->claimedAt?->format(\DateTimeImmutable::ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':completed_at', $task->completedAt?->format(\DateTimeImmutable::ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':depends_on', json_encode($task->dependsOn, JSON_THROW_ON_ERROR), \SQLITE3_TEXT);

        $stmt->execute();
        $stmt->close();

        $this->closeForWrite($handle);

        return $task->id;
    }

    /**
     * Update a task's status.
     *
     * @throws \SQLite3Exception When the task does not exist.
     */
    public function updateTaskStatus(string $taskId, TaskStatus $status): void
    {
        $handle = $this->openForWrite();

        $stmt = $this->db->prepare(
            'UPDATE tasks SET status = :status WHERE id = :id'
        );
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $stmt->bindValue(':status', $status->value, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        $this->closeForWrite($handle);
    }

    /**
     * Mark a task as completed and store its result.
     */
    public function completeTask(string $taskId, string $result): void
    {
        $handle = $this->openForWrite();

        $stmt = $this->db->prepare(
            <<<'SQL'
            UPDATE tasks
            SET status = :status, result = :result, completed_at = :completed_at
            WHERE id = :id
            SQL
        );
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $stmt->bindValue(':status', TaskStatus::Completed->value, \SQLITE3_TEXT);
        $stmt->bindValue(':result', $result, \SQLITE3_TEXT);
        $stmt->bindValue(':completed_at', (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM), \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        $this->closeForWrite($handle);
    }

    /**
     * Mark a task as failed and store its error message.
     */
    public function failTask(string $taskId, string $error): void
    {
        $handle = $this->openForWrite();

        $stmt = $this->db->prepare(
            <<<'SQL'
            UPDATE tasks
            SET status = :status, error = :error, completed_at = :completed_at
            WHERE id = :id
            SQL
        );
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $stmt->bindValue(':status', TaskStatus::Failed->value, \SQLITE3_TEXT);
        $stmt->bindValue(':error', $error, \SQLITE3_TEXT);
        $stmt->bindValue(':completed_at', (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM), \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        $this->closeForWrite($handle);
    }

    // -------------------------------------------------------------------------
    // Read operations — no locking required for simple reads
    // -------------------------------------------------------------------------

    /**
     * Fetch all pending tasks.
     *
     * @return Task[]
     */
    public function getPendingTasks(): array
    {
        $result = $this->db->query(
            "SELECT * FROM tasks WHERE status = 'pending' ORDER BY created_at ASC"
        );

        return $this->rowsToTasks($result);
    }

    /**
     * Fetch a single task by ID.
     */
    public function getTask(string $taskId): ?Task
    {
        $stmt = $this->db->prepare('SELECT * FROM tasks WHERE id = :id');
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $result = $stmt->execute();

        $tasks = $this->rowsToTasks($result);
        $stmt->close();

        return $tasks[0] ?? null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return \SQLite3[]
     */
    private static function getConnection(string $dbPath): \SQLite3
    {
        if (!isset(self::$connections[$dbPath])) {
            // Ensure parent directory exists
            $dir = \dirname($dbPath);
            if (!\is_dir($dir)) {
                \mkdir($dir, 0755, true);
            }

            self::$connections[$dbPath] = new \SQLite3($dbPath);
            self::$connections[$dbPath]->busyTimeout(5000);
        }

        return self::$connections[$dbPath];
    }

    /** Acquire an exclusive (write) lock on the database file. */
    private function openForWrite(): mixed
    {
        $fp = \fopen($this->dbPath, 'a');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open database file: {$this->dbPath}");
        }
        if (!\flock($fp, \LOCK_EX)) {
            \fclose($fp);
            throw new \RuntimeException("Cannot acquire lock on database file: {$this->dbPath}");
        }

        return $fp;
    }

    /** Release the exclusive lock and close the file handle. */
    private function closeForWrite(mixed $fp): void
    {
        \flock($fp, \LOCK_UN);
        \fclose($fp);
    }

    /**
     * Convert SQLite result rows into Task objects.
     *
     * @param \SQLite3Result $result
     * @return Task[]
     */
    private function rowsToTasks(\SQLite3Result $result): array
    {
        $tasks = [];
        while ($row = $result->fetchArray(\SQLITE3_ASSOC)) {
            $tasks[] = $this->rowToTask($row);
        }

        return $tasks;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToTask(array $row): Task
    {
        return new Task(
            id: $row['id'],
            teamId: $row['team_id'],
            title: $row['title'],
            description: $row['description'],
            prompt: $row['prompt'],
            assignedTo: $row['assigned_to'],
            status: TaskStatus::from($row['status']),
            result: $row['result'],
            error: $row['error'],
            createdAt: new \DateTimeImmutable($row['created_at']),
            claimedAt: $row['claimed_at'] !== null ? new \DateTimeImmutable($row['claimed_at']) : null,
            completedAt: $row['completed_at'] !== null ? new \DateTimeImmutable($row['completed_at']) : null,
            dependsOn: json_decode($row['depends_on'] ?? '[]', true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
