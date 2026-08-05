<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookDispatcher;
use SugarCraft\Crush\Hooks\HookDispatchResult;

/**
 * SQLite-backed task list for team coordination.
 *
 * Uses file-based locking (flock) to ensure safe concurrent access when
 * multiple teammate agents access the same database from different processes.
 * The table is auto-created on first construction (migration).
 *
 * Hooks wired:
 * - TaskCreated: dispatched before inserting a new task; block aborts the insert
 * - TaskCompleted: dispatched after a task completes; block with continueOnBlock
 *                  marks the completion as contested
 * - TeammateIdle: dispatched when a teammate has no more tasks to work on
 */
final class TaskList
{
    /** @var array<string, \SQLite3> cache of open connections, keyed by dbPath */
    private static array $connections = [];

    private readonly \SQLite3 $db;

    /**
     * @param string $dbPath Path to the SQLite database file
     * @param HookDispatcher|null $hookDispatcher Optional hook dispatcher for lifecycle events
     */
    public function __construct(
        private readonly string $dbPath,
        private readonly ?HookDispatcher $hookDispatcher = null,
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
                depends_on      TEXT    NOT NULL DEFAULT '[]',
                contested       INTEGER NOT NULL DEFAULT 0
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
     * Before inserting, dispatches the TaskCreated hook. If a hook blocks
     * (exit 2), the task is not inserted and a TaskBlockedException is thrown.
     *
     * @return string The task ID (same as $task->id)
     * @throws TaskBlockedException When a TaskCreated hook blocks the insertion
     */
    public function addTask(Task $task): string
    {
        // Dispatch TaskCreated hook — block aborts the insert
        if ($this->hookDispatcher !== null) {
            $context = $this->makeHookContext($task->teamId, $task->id, $task->title);
            $result = $this->hookDispatcher->dispatchTaskCreated($context);
            if ($result->isBlock()) {
                throw new TaskBlockedException($task->id, $result->message);
            }
        }

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
     *
     * After completing, dispatches the TaskCompleted hook. If a hook returns
     * a block with continueOnBlock (exit 2), the completion is marked as contested.
     */
    public function completeTask(string $taskId, string $result): void
    {
        // Fetch task first to get teamId for the hook context
        $task = $this->getTask($taskId);
        $teamId = $task?->teamId ?? '';

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

        // Dispatch TaskCompleted hook — block with continueOnBlock marks contested
        if ($this->hookDispatcher !== null && $teamId !== '') {
            $context = $this->makeHookContext($teamId, $taskId, '');
            $hookResult = $this->hookDispatcher->dispatchTaskCompleted($context);
            if ($hookResult->isBlock() && $hookResult->shouldContinueOnBlock()) {
                $this->markContested($taskId);
            }
        }
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

    /**
     * Mark a task's completion as contested.
     *
     * Called when a TaskCompleted hook returns a block with continueOnBlock,
     * indicating the completion is disputed but already happened.
     */
    private function markContested(string $taskId): void
    {
        $handle = $this->openForWrite();

        $stmt = $this->db->prepare('UPDATE tasks SET contested = 1 WHERE id = :id');
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        $this->closeForWrite($handle);
    }

    // -------------------------------------------------------------------------
    // Hook dispatch (called by TeamManager / Teammate when a teammate goes idle)
    // -------------------------------------------------------------------------

    /**
     * Dispatch the TeammateIdle hook for a given teammate.
     *
     * This is called by TeamManager or Teammate when a teammate has exhausted
     * all available tasks. The hook system can respond by assigning new work,
     * alerting the human, or other escalation.
     *
     * @param string $teamId   The team the teammate belongs to
     * @param string $teammateId The teammate who went idle
     * @return HookDispatchResult The result of the hook dispatch
     */
    public function dispatchTeammateIdle(string $teamId, string $teammateId): HookDispatchResult
    {
        if ($this->hookDispatcher === null) {
            return HookDispatchResult::allow(
                \SugarCraft\Crush\Hooks\HookEvent::TeammateIdle,
                $this->makeHookContext($teamId, $teammateId, ''),
                'no dispatcher',
            );
        }

        $context = $this->makeHookContext($teamId, $teammateId, '');

        return $this->hookDispatcher->dispatchTeammateIdle($context);
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
    // Claiming — atomic via per-task flock
    // -------------------------------------------------------------------------

    /**
     * Atomically claim a task for a teammate.
     *
     * Uses a per-task lock file to ensure no two teammates can claim the same
     * task simultaneously. A task can only be claimed if:
     *   1. It exists and is in 'pending' status
     *   2. All of its dependencies have been completed
     *   3. It is either unassigned or assigned to the claiming teammate
     *
     * @return bool true if the claim succeeded, false if the task is not claimable
     */
    public function claimTask(string $taskId, string $teammateId): bool
    {
        // Per-task lock file prevents concurrent claim attempts on the same task
        $lockPath = $this->lockPathFor($taskId);
        $lockFp = $this->acquireTaskLock($lockPath);

        try {
            $task = $this->getTaskWithoutLock($taskId);

            // Task must exist and be pending
            if ($task === null || $task->status !== TaskStatus::Pending) {
                return false;
            }

            // Task must be unassigned or assigned to this teammate
            if ($task->assignedTo !== null && $task->assignedTo !== $teammateId) {
                return false;
            }

            // All dependencies must be completed
            if (!$this->allDependenciesCompleted($task->dependsOn)) {
                return false;
            }

            // Claim the task — update status and assignee atomically
            $this->claimTaskInner($taskId, $teammateId);

            return true;
        } finally {
            $this->releaseTaskLock($lockFp, $lockPath);
        }
    }

    /**
     * Add a dependency to a task.
     *
     * The dependent task will not be claimable until the dependency is completed.
     *
     * @throws \SQLite3Exception When the task does not exist
     */
    public function addDependency(string $taskId, string $dependsOn): void
    {
        $handle = $this->openForWrite();

        // Fetch current dependencies
        $stmt = $this->db->prepare('SELECT depends_on FROM tasks WHERE id = :id');
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(\SQLITE3_ASSOC);
        $stmt->close();

        if ($row === false) {
            $this->closeForWrite($handle);
            throw new \SQLite3Exception("Task not found: {$taskId}");
        }

        $deps = json_decode($row['depends_on'], true, 512, JSON_THROW_ON_ERROR);
        if (!in_array($dependsOn, $deps, true)) {
            $deps[] = $dependsOn;
        }

        // Update the depends_on array
        $updateStmt = $this->db->prepare('UPDATE tasks SET depends_on = :depends_on WHERE id = :id');
        $updateStmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $updateStmt->bindValue(':depends_on', json_encode($deps, JSON_THROW_ON_ERROR), \SQLITE3_TEXT);
        $updateStmt->execute();
        $updateStmt->close();

        $this->closeForWrite($handle);
    }

    /**
     * Return all tasks that are unblocked and available for a teammate to claim.
     *
     * A task is unblocked when:
     *   - It is in 'pending' status
     *   - All of its dependencies have been completed
     *   - It is either unassigned or assigned to the given teammate
     *
     * @return Task[]
     */
    public function getUnblockedTasks(string $teammateId): array
    {
        $allPending = $this->getPendingTasks();

        return array_values(array_filter(
            $allPending,
            function (Task $task) use ($teammateId): bool {
                // Must be unassigned or assigned to this teammate
                if ($task->assignedTo !== null && $task->assignedTo !== $teammateId) {
                    return false;
                }

                // All dependencies must be completed
                return $this->allDependenciesCompleted($task->dependsOn);
            }
        ));
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
            isContested: (bool)($row['contested'] ?? false),
        );
    }

    // -------------------------------------------------------------------------
    // Claiming helpers
    // -------------------------------------------------------------------------

    /**
     * Get a task by ID without acquiring any locks.
     *
     * FOR INTERNAL USE ONLY — call only when already holding a task lock.
     */
    private function getTaskWithoutLock(string $taskId): ?Task
    {
        $stmt = $this->db->prepare('SELECT * FROM tasks WHERE id = :id');
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $result = $stmt->execute();

        $tasks = $this->rowsToTasks($result);
        $stmt->close();

        return $tasks[0] ?? null;
    }

    /**
     * Perform the actual claim update — caller must hold the task lock.
     */
    private function claimTaskInner(string $taskId, string $teammateId): void
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);

        $stmt = $this->db->prepare(
            <<<'SQL'
            UPDATE tasks
            SET status = :status, assigned_to = :assigned_to, claimed_at = :claimed_at
            WHERE id = :id
            SQL
        );
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $stmt->bindValue(':status', TaskStatus::InProgress->value, \SQLITE3_TEXT);
        $stmt->bindValue(':assigned_to', $teammateId, \SQLITE3_TEXT);
        $stmt->bindValue(':claimed_at', $now, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Check whether all given dependency task IDs have been completed.
     *
     * @param string[] $dependsOn
     */
    private function allDependenciesCompleted(array $dependsOn): bool
    {
        if (empty($dependsOn)) {
            return true;
        }

        foreach ($dependsOn as $depId) {
            $dep = $this->getTaskWithoutLock($depId);
            if ($dep === null || $dep->status !== TaskStatus::Completed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate the lock file path for a specific task.
     */
    private function lockPathFor(string $taskId): string
    {
        return \dirname($this->dbPath) . '/task_locks/' . $taskId . '.lock';
    }

    /**
     * Acquire an exclusive lock on a task's lock file.
     *
     * @throws \RuntimeException When the lock cannot be acquired
     */
    private function acquireTaskLock(string $lockPath): mixed
    {
        $dir = \dirname($lockPath);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0755, true);
        }

        $fp = \fopen($lockPath, 'a');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open lock file: {$lockPath}");
        }
        if (!\flock($fp, \LOCK_EX)) {
            \fclose($fp);
            throw new \RuntimeException("Cannot acquire task lock: {$lockPath}");
        }

        return $fp;
    }

    /**
     * Release the task lock and remove the lock file.
     */
    private function releaseTaskLock(mixed $fp, string $lockPath): void
    {
        \flock($fp, \LOCK_UN);
        \fclose($fp);
        if (\file_exists($lockPath)) {
            \unlink($lockPath);
        }
    }

    /**
     * Build a HookContext for task-scoped hook events.
     *
     * Uses the teamId as sessionId since TaskList operates at the team level.
     * The toolName is always 'TaskList' for task-scoped events.
     *
     * @param string $teamId   Used as sessionId in the context
     * @param string $taskId   Used as toolInput in the context
     * @param string $taskTitle Used as toolArgs['title'] in the context
     */
    private function makeHookContext(string $teamId, string $taskId, string $taskTitle): HookContext
    {
        return new HookContext(
            sessionId: $teamId,
            toolName: 'TaskList',
            toolArgs: ['title' => $taskTitle],
            toolInput: $taskId,
            toolOutput: '',
            model: '',
            provider: '',
            projectRoot: '',
        );
    }
}

/**
 * Thrown when a TaskCreated hook blocks the insertion of a new task.
 */
final class TaskBlockedException extends \RuntimeException
{
    public function __construct(
        public readonly string $taskId,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Task creation blocked: {$taskId}");
    }
}
