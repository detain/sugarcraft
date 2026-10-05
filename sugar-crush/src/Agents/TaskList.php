<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookDispatcher;
use SugarCraft\Crush\Hooks\HookDispatchResult;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Support\ProcessTree;
use SugarCraft\Crush\Support\TimedFileLock;

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
 *
 * Concurrency (roadmap 4.6-1, 4.6-2):
 * - Every write bumps the row's `revision`, and {@see claimTask()},
 *   {@see releaseTask()}, {@see completeTask()} and {@see failTask()} are
 *   compare-and-swap on it: the UPDATE only lands
 *   when the row is still at the revision the decision was made on, so a
 *   complete/fail/status write from another process between the read and the
 *   write turns the claim into a refusal instead of being silently undone.
 *   Callers that read first can pass the {@see revision()} they saw.
 * - Dependencies stay acyclic: {@see addTask()} and {@see addDependency()}
 *   refuse an edge that would close a loop (a loop is a set of tasks none of
 *   which can ever be claimed).
 * - Two inserts of one id do not reach the primary key: the id is checked
 *   under the write lock, {@see addTaskIfAbsent()} reports a taken one.
 * - Each process opens its own SQLite connection ({@see db()}), so a list
 *   built before a fork is safe to use after it.
 * - A claim records the claiming process (pid + kernel start time), and
 *   {@see releaseOrphanedClaims()} puts the tasks of a claimant that died
 *   mid-task back to pending.
 */
final class TaskList
{
    /**
     * Open connections, keyed by process id and dbPath — see {@see db()}.
     *
     * @var array<string, \SQLite3>
     */
    private static array $connections = [];

    /**
     * @param string $dbPath Path to the SQLite database file
     * @param HookDispatcher|null $hookDispatcher Optional hook dispatcher for lifecycle events
     * @param string|null $projectRoot Directory the task-scoped hooks run in.
     *        {@see HookContext::$projectRoot} becomes the `proc_open()` cwd in
     *        {@see \SugarCraft\Crush\Hooks\ScriptHook::execute()}, so this is a
     *        root-resolving position even though no `getcwd()` appears at the
     *        site (crush_code.md Phase 0 item 6). It is nullable because the
     *        only construction path — {@see Team}, keyed on
     *        `~/.sugar-crush/teams/{teamId}/` — holds no project root of its
     *        own yet: teams are a dormant seam, not wired to `--root` through
     *        {@see \SugarCraft\Crush\Cli\Bootstrap}. This parameter is the
     *        injection point for when they are; until then the process
     *        directory is the honest answer, and it beats the hardcoded `''`
     *        that used to sit in makeHookContext().
     */
    public function __construct(
        private readonly string $dbPath,
        private readonly ?HookDispatcher $hookDispatcher = null,
        private readonly ?string $projectRoot = null,
    ) {
        $this->migrate();
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    private function migrate(): void
    {
        $this->db()->exec(
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
                contested       INTEGER NOT NULL DEFAULT 0,
                revision        INTEGER NOT NULL DEFAULT 0,
                claim_pid       INTEGER,
                claim_started   INTEGER
            );
            SQL
        );

        // A database created before these columns existed gets them added in
        // place; CREATE TABLE IF NOT EXISTS leaves an existing table alone.
        $present = [];
        $info = $this->db()->query('PRAGMA table_info(tasks)');
        while ($info !== false && ($column = $info->fetchArray(\SQLITE3_ASSOC)) !== false) {
            $present[(string) $column['name']] = true;
        }
        foreach ([
            'revision' => 'INTEGER NOT NULL DEFAULT 0',
            'claim_pid' => 'INTEGER',
            'claim_started' => 'INTEGER',
        ] as $name => $type) {
            if (!isset($present[$name])) {
                $this->db()->exec("ALTER TABLE tasks ADD COLUMN {$name} {$type}");
            }
        }
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
     * @throws \InvalidArgumentException When $task's dependencies would close a
     *         cycle (an id may be depended on before it exists, so a later task
     *         can complete a loop the earlier ones started), or when a task
     *         with $task's id already exists — see {@see addTaskIfAbsent()}
     */
    public function addTask(Task $task): string
    {
        if (!$this->addTaskIfAbsent($task)) {
            throw new \InvalidArgumentException(sprintf('A task with id "%s" already exists.', $task->id));
        }

        return $task->id;
    }

    /**
     * {@see addTask()}, reporting a taken id as false instead of throwing.
     *
     * Two processes that pick the next free id at the same moment pick the
     * same one, and the second insert used to reach SQLite's primary key: a
     * PHP warning ("UNIQUE constraint failed") out of `execute()` and a
     * silently dropped task. The id is now checked under the write lock every
     * insert takes, so the loser learns it here and can pick again.
     *
     * The TaskCreated hook runs before the insert, outside the lock (a hook
     * may re-enter this list); an id already taken when this is called is
     * refused before the hook runs, so only a genuine race fires it for an id
     * that is then not inserted.
     *
     * @return bool true when $task was inserted, false when its id was taken
     * @throws TaskBlockedException When a TaskCreated hook blocks the insertion
     * @throws \InvalidArgumentException When $task's dependencies would close a cycle
     */
    public function addTaskIfAbsent(Task $task): bool
    {
        if ($this->revision($task->id) !== null) {
            return false;
        }

        // Dispatch TaskCreated hook — block aborts the insert
        if ($this->hookDispatcher !== null) {
            $context = $this->makeHookContext($task->teamId, $task->id, $task->title);
            $result = $this->hookDispatcher->dispatchTaskCreated($context);
            if ($result->isBlock()) {
                throw new TaskBlockedException($task->id, $result->message);
            }
        }

        $handle = $this->openForWrite();

        try {
            if ($this->revision($task->id) !== null) {
                return false;
            }

            foreach ($task->dependsOn as $dependency) {
                $this->refuseCycle($task->id, (string) $dependency);
            }

            $stmt = $this->db()->prepare(
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
        } finally {
            $this->closeForWrite($handle);
        }

        return true;
    }

    /**
     * Update a task's status.
     *
     * @throws \SQLite3Exception When the task does not exist.
     */
    public function updateTaskStatus(string $taskId, TaskStatus $status): void
    {
        $handle = $this->openForWrite();

        $stmt = $this->db()->prepare(
            'UPDATE tasks SET status = :status, revision = revision + 1 WHERE id = :id'
        );
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $stmt->bindValue(':status', $status->value, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();

        $this->assertTaskFound($taskId, $handle);

        $this->closeForWrite($handle);
    }

    /**
     * Mark a task as completed and store its result.
     *
     * Compare-and-swap on the task's revision, like {@see claimTask()}: the
     * UPDATE lands only if the row is still at the revision read under the
     * write lock — and at $expectedRevision, when the caller passes the
     * {@see revision()} it decided on. A claim takes the per-task lock rather
     * than this one, so without the compare a claim, release or orphan sweep
     * landing between the read and the write would be overwritten by a
     * completion decided on the row before it.
     *
     * After completing, dispatches the TaskCompleted hook. If a hook returns
     * a block with continueOnBlock (exit 2), the completion is marked as contested.
     *
     * @return bool true when this call completed the task; false when the row
     *         moved first (nothing is written and no hook fires)
     * @throws \SQLite3Exception When the task does not exist.
     */
    public function completeTask(string $taskId, string $result, ?int $expectedRevision = null): bool
    {
        $handle = $this->openForWrite();

        try {
            $task = $this->getTaskWithoutLock($taskId);
            if ($task === null) {
                throw new \SQLite3Exception("Task not found: {$taskId}");
            }

            $landed = $this->finishAt($taskId, TaskStatus::Completed, 'result', $result, $expectedRevision);
        } finally {
            // Release before dispatching: flock() is per open-file-description, so a
            // hook path that re-enters TaskList (markContested) would fopen() the same
            // file again and block on the lock this very process still holds.
            $this->closeForWrite($handle);
        }

        // Dispatch TaskCompleted hook (post-action) — block with continueOnBlock marks contested
        if ($landed && $this->hookDispatcher !== null && $task->teamId !== '') {
            $context = $this->makeHookContext($task->teamId, $taskId, $task->title);
            $hookResult = $this->hookDispatcher->dispatchTaskCompleted($context);
            if ($hookResult->isBlock() && $hookResult->shouldContinueOnBlock()) {
                $this->markContested($taskId);
            }
        }

        return $landed;
    }

    /**
     * Mark a task as failed and store its error message.
     *
     * The same revision compare-and-swap as {@see completeTask()}.
     *
     * @return bool true when this call failed the task; false when the row moved first
     * @throws \SQLite3Exception When the task does not exist.
     */
    public function failTask(string $taskId, string $error, ?int $expectedRevision = null): bool
    {
        $handle = $this->openForWrite();

        try {
            if ($this->revision($taskId) === null) {
                throw new \SQLite3Exception("Task not found: {$taskId}");
            }

            return $this->finishAt($taskId, TaskStatus::Failed, 'error', $error, $expectedRevision);
        } finally {
            $this->closeForWrite($handle);
        }
    }

    /**
     * Write a terminal status iff the row is still at the revision read here
     * (and at $expectedRevision when given). Caller holds the write lock.
     *
     * @param 'result'|'error' $column
     */
    private function finishAt(string $taskId, TaskStatus $status, string $column, string $text, ?int $expectedRevision): bool
    {
        $revision = $this->revision($taskId);
        if ($revision === null || ($expectedRevision !== null && $revision !== $expectedRevision)) {
            return false;
        }

        $stmt = $this->db()->prepare(
            "UPDATE tasks SET status = :status, {$column} = :text, completed_at = :completed_at,"
            . ' revision = revision + 1 WHERE id = :id AND revision = :revision'
        );
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $stmt->bindValue(':status', $status->value, \SQLITE3_TEXT);
        $stmt->bindValue(':text', $text, \SQLITE3_TEXT);
        $stmt->bindValue(':completed_at', (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM), \SQLITE3_TEXT);
        $stmt->bindValue(':revision', $revision, \SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();

        return $this->db()->changes() === 1;
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

        $stmt = $this->db()->prepare('UPDATE tasks SET contested = 1, revision = revision + 1 WHERE id = :id');
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
        $result = $this->db()->query(
            "SELECT * FROM tasks WHERE status = 'pending' ORDER BY created_at ASC"
        );

        return $this->rowsToTasks($result);
    }

    /**
     * Fetch a single task by ID.
     */
    public function getTask(string $taskId): ?Task
    {
        $stmt = $this->db()->prepare('SELECT * FROM tasks WHERE id = :id');
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $result = $stmt->execute();

        $tasks = $this->rowsToTasks($result);
        $stmt->close();

        return $tasks[0] ?? null;
    }

    /**
     * Fetch all tasks with a given status.
     *
     * @return Task[]
     */
    public function getTasksByStatus(TaskStatus $status): array
    {
        $stmt = $this->db()->prepare('SELECT * FROM tasks WHERE status = :status ORDER BY created_at ASC');
        $stmt->bindValue(':status', $status->value, \SQLITE3_TEXT);
        $result = $stmt->execute();

        $tasks = $this->rowsToTasks($result);
        $stmt->close();

        return $tasks;
    }

    /**
     * Fetch all tasks assigned to a specific teammate.
     *
     * @return Task[]
     */
    public function getTasksForTeammate(string $teammateId): array
    {
        $stmt = $this->db()->prepare('SELECT * FROM tasks WHERE assigned_to = :assigned_to ORDER BY created_at ASC');
        $stmt->bindValue(':assigned_to', $teammateId, \SQLITE3_TEXT);
        $result = $stmt->execute();

        $tasks = $this->rowsToTasks($result);
        $stmt->close();

        return $tasks;
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
     *   4. Its revision is still the one read here — and $expectedRevision,
     *      when the caller passes the {@see revision()} it decided on
     *
     * The per-task lock only serialises CLAIMS; complete/fail/status writes
     * take the database lock instead. The revision compare-and-swap is what
     * keeps one of those landing between the read and the write from being
     * overwritten by a stale claim.
     *
     * The claim records $ownerPid (default: this process) with its kernel
     * start time, so {@see releaseOrphanedClaims()} can tell a claimant that
     * died from one still working.
     *
     * @return bool true if the claim succeeded, false if the task is not claimable
     */
    public function claimTask(string $taskId, string $teammateId, ?int $expectedRevision = null, ?int $ownerPid = null): bool
    {
        // Per-task lock file prevents concurrent claim attempts on the same task
        $lockPath = $this->lockPathFor($taskId);
        $lockFp = $this->acquireTaskLock($lockPath);

        try {
            $task = $this->getTaskWithoutLock($taskId);
            $revision = $this->revision($taskId);

            if ($revision === null || ($expectedRevision !== null && $revision !== $expectedRevision)) {
                return false;
            }

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

            // Claim the task — update status and assignee atomically, and only
            // if nothing moved the row since it was read.
            return $this->claimTaskInner($taskId, $teammateId, $revision, $ownerPid ?? (int) \getmypid());
        } finally {
            $this->releaseTaskLock($lockFp);
        }
    }

    /**
     * Release a teammate's claim: put an in-progress, theirs-assigned task
     * back to pending and unassigned.
     *
     * The inverse half of {@see claimTask()}, under the same per-task lock,
     * and deliberately as narrow as possible: it succeeds only for exactly
     * the state a claim left behind (Pending ← InProgress, and only for the
     * teammate the task still names). E136 gives Team::claimTask()'s rollback
     * a path that cannot touch a task some other teammate has since moved.
     *
     * Compare-and-swap on the row's revision like {@see claimTask()}: a
     * write that lands between the read and the release wins, and this call
     * reports false.
     *
     * @return bool true if this call released the claim; false when the task
     *         is gone, no longer in progress, no longer theirs, or no longer at
     *         $expectedRevision / the revision read here — the bookkeeping was
     *         someone else's to change by then.
     */
    public function releaseTask(string $taskId, string $teammateId, ?int $expectedRevision = null): bool
    {
        $lockPath = $this->lockPathFor($taskId);
        $lockFp = $this->acquireTaskLock($lockPath);

        try {
            $task = $this->getTaskWithoutLock($taskId);
            $revision = $this->revision($taskId);

            if ($task === null
                || $revision === null
                || ($expectedRevision !== null && $revision !== $expectedRevision)
                || $task->status !== TaskStatus::InProgress
                || $task->assignedTo !== $teammateId
            ) {
                return false;
            }

            return $this->releaseClaimAt($taskId, $revision);
        } finally {
            $this->releaseTaskLock($lockFp);
        }
    }

    /**
     * Put every in-progress task whose claiming process has died back to
     * pending and unassigned (crash recovery), and return their ids.
     *
     * A claim is orphaned when the pid it recorded is gone, is a zombie, or
     * now belongs to a different process (its kernel start time no longer
     * matches — a recycled pid is not the claimant come back). Claims made
     * before claimants were recorded carry no pid and are left alone: there
     * is nothing to judge them by. Each release is the same compare-and-swap
     * as {@see releaseTask()}, so a task that finishes while this runs keeps
     * its result.
     *
     * $isAlive is the liveness probe, `fn(int $pid, ?int $startTime): bool`;
     * null uses procfs + signal 0.
     *
     * @param (callable(int, ?int): bool)|null $isAlive
     * @return list<string>
     */
    public function releaseOrphanedClaims(?callable $isAlive = null): array
    {
        $isAlive ??= self::processAlive(...);

        $stmt = $this->db()->prepare(
            'SELECT id, revision, claim_pid, claim_started FROM tasks WHERE status = :status AND claim_pid IS NOT NULL'
        );
        $stmt->bindValue(':status', TaskStatus::InProgress->value, \SQLITE3_TEXT);
        $result = $stmt->execute();
        $candidates = [];
        while ($result !== false && ($row = $result->fetchArray(\SQLITE3_ASSOC)) !== false) {
            $candidates[] = $row;
        }
        $stmt->close();

        $released = [];
        foreach ($candidates as $row) {
            $started = $row['claim_started'] === null ? null : (int) $row['claim_started'];
            if ($isAlive((int) $row['claim_pid'], $started)) {
                continue;
            }

            $id = (string) $row['id'];
            $lockFp = $this->acquireTaskLock($this->lockPathFor($id));
            try {
                if ($this->releaseClaimAt($id, (int) $row['revision'])) {
                    $released[] = $id;
                }
            } finally {
                $this->releaseTaskLock($lockFp);
            }
        }

        return $released;
    }

    /**
     * The task's current revision — bumped by every write — or null when the
     * task does not exist. Pass it back to {@see claimTask()},
     * {@see releaseTask()}, {@see completeTask()} or {@see failTask()} to make
     * them conditional on nothing having changed since it was read.
     */
    public function revision(string $taskId): ?int
    {
        $stmt = $this->db()->prepare('SELECT revision FROM tasks WHERE id = :id');
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result === false ? false : $result->fetchArray(\SQLITE3_ASSOC);
        $stmt->close();

        return \is_array($row) ? (int) $row['revision'] : null;
    }

    /**
     * Set an in-progress task back to pending/unassigned iff it is still at
     * $revision. Caller holds the task lock.
     */
    private function releaseClaimAt(string $taskId, int $revision): bool
    {
        $handle = $this->openForWrite();

        try {
            $stmt = $this->db()->prepare(
                <<<'SQL'
                UPDATE tasks
                SET status = :pending, assigned_to = NULL, claimed_at = NULL,
                    claim_pid = NULL, claim_started = NULL, revision = revision + 1
                WHERE id = :id AND revision = :revision AND status = :in_progress
                SQL
            );
            $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
            $stmt->bindValue(':pending', TaskStatus::Pending->value, \SQLITE3_TEXT);
            $stmt->bindValue(':in_progress', TaskStatus::InProgress->value, \SQLITE3_TEXT);
            $stmt->bindValue(':revision', $revision, \SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->close();

            return $this->db()->changes() === 1;
        } finally {
            $this->closeForWrite($handle);
        }
    }

    /**
     * Add a dependency to a task.
     *
     * The dependent task will not be claimable until the dependency is completed.
     * $dependsOn may name a task that does not exist yet.
     *
     * @throws \SQLite3Exception When the task does not exist
     * @throws \InvalidArgumentException When the edge would close a cycle
     *         (including a task depending on itself): every task on a cycle
     *         waits on another, so none of them could ever be claimed
     */
    public function addDependency(string $taskId, string $dependsOn): void
    {
        $handle = $this->openForWrite();

        // Fetch current dependencies
        $stmt = $this->db()->prepare('SELECT depends_on FROM tasks WHERE id = :id');
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(\SQLITE3_ASSOC);
        $stmt->close();

        if ($row === false) {
            $this->closeForWrite($handle);
            throw new \SQLite3Exception("Task not found: {$taskId}");
        }

        try {
            $this->refuseCycle($taskId, $dependsOn);
        } catch (\InvalidArgumentException $cycle) {
            $this->closeForWrite($handle);

            throw $cycle;
        }

        $deps = json_decode($row['depends_on'], true, 512, JSON_THROW_ON_ERROR);
        if (!in_array($dependsOn, $deps, true)) {
            $deps[] = $dependsOn;
        }

        // Update the depends_on array
        $updateStmt = $this->db()->prepare('UPDATE tasks SET depends_on = :depends_on, revision = revision + 1 WHERE id = :id');
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
     * This process's connection to the task database.
     *
     * Resolved per call rather than held on the instance, because a TaskList
     * built in one process can be used in another: the `Team` tool runs in a
     * turn's forked child, and a fork inherits every connection its parent
     * had open. SQLite forbids carrying an open connection across fork() —
     * the child's handle shares the parent's file descriptors and lock state
     * (https://www.sqlite.org/howtocorrupt.html §2.6) — so the first call in
     * a new process opens a connection of its own.
     */
    private function db(): \SQLite3
    {
        return self::getConnection($this->dbPath);
    }

    /**
     * The connection for $dbPath in THIS process, opened on first use.
     *
     * Keyed by pid as well as path: an entry a forked child inherited from its
     * parent is never reused, and never closed either — closing it in the
     * child would run SQLite's close path on descriptors the parent is still
     * using. It just stays unused.
     */
    private static function getConnection(string $dbPath): \SQLite3
    {
        $key = (int) \getmypid() . "\0" . $dbPath;
        if (!isset(self::$connections[$key])) {
            // Ensure parent directory exists
            $dir = \dirname($dbPath);
            if (!\is_dir($dir)) {
                \mkdir($dir, 0755, true);
            }

            self::$connections[$key] = new \SQLite3($dbPath);
            self::$connections[$key]->busyTimeout(5000);
        }

        return self::$connections[$key];
    }

    /** Acquire an exclusive (write) lock on the database file. */
    private function openForWrite(): mixed
    {
        $fp = \fopen($this->dbPath, 'a');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open database file: {$this->dbPath}");
        }
        try {
            $this->flockTimed($fp, \LOCK_EX, $this->dbPath);
        } catch (\RuntimeException $failure) {
            \fclose($fp);

            throw $failure;
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
     * Verify that a task was found (rows affected > 0), releasing the write
     * lock and throwing if not.
     *
     * @throws \SQLite3Exception When no rows were updated (task not found)
     */
    private function assertTaskFound(string $taskId, mixed $fp): void
    {
        if ($this->db()->changes() === 0) {
            $this->closeForWrite($fp);
            throw new \SQLite3Exception("Task not found: {$taskId}");
        }
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
            isContested: (bool) (int) ($row['contested'] ?? 0),
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
        $stmt = $this->db()->prepare('SELECT * FROM tasks WHERE id = :id');
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $result = $stmt->execute();

        $tasks = $this->rowsToTasks($result);
        $stmt->close();

        return $tasks[0] ?? null;
    }

    /**
     * Perform the actual claim update — caller must hold the task lock.
     *
     * @return bool false when the row is no longer at $revision (lost the CAS)
     */
    private function claimTaskInner(string $taskId, string $teammateId, int $revision, int $ownerPid): bool
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);
        $started = BackgroundSupervisor::procStartTime($ownerPid);

        $stmt = $this->db()->prepare(
            <<<'SQL'
            UPDATE tasks
            SET status = :status, assigned_to = :assigned_to, claimed_at = :claimed_at,
                claim_pid = :claim_pid, claim_started = :claim_started, revision = revision + 1
            WHERE id = :id AND revision = :revision
            SQL
        );
        $stmt->bindValue(':id', $taskId, \SQLITE3_TEXT);
        $stmt->bindValue(':status', TaskStatus::InProgress->value, \SQLITE3_TEXT);
        $stmt->bindValue(':assigned_to', $teammateId, \SQLITE3_TEXT);
        $stmt->bindValue(':claimed_at', $now, \SQLITE3_TEXT);
        $stmt->bindValue(':claim_pid', $ownerPid, \SQLITE3_INTEGER);
        $stmt->bindValue(':claim_started', $started, $started === null ? \SQLITE3_NULL : \SQLITE3_INTEGER);
        $stmt->bindValue(':revision', $revision, \SQLITE3_INTEGER);
        $stmt->execute();
        $stmt->close();

        return $this->db()->changes() === 1;
    }

    /**
     * Throw when adding the edge $taskId → $dependsOn would close a cycle,
     * i.e. when $taskId is already reachable from $dependsOn (or is it).
     *
     * Walks the stored depends_on lists breadth-first; ids with no row yet
     * end their branch. Caller holds the database write lock, so the graph
     * cannot change under the walk.
     *
     * @throws \InvalidArgumentException naming the loop
     */
    private function refuseCycle(string $taskId, string $dependsOn): void
    {
        $edges = [];
        $result = $this->db()->query('SELECT id, depends_on FROM tasks');
        while ($result !== false && ($row = $result->fetchArray(\SQLITE3_ASSOC)) !== false) {
            $deps = json_decode((string) $row['depends_on'], true);
            $edges[(string) $row['id']] = \is_array($deps) ? array_map('strval', $deps) : [];
        }

        // $via[x] = the task x was reached from, to print the loop.
        $via = [$dependsOn => null];
        $queue = [$dependsOn];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === $taskId) {
                $chain = [];
                for ($node = $current; $node !== null; $node = $via[$node]) {
                    $chain[] = $node;
                }
                $path = [$taskId, ...array_reverse($chain)];

                throw new \InvalidArgumentException(sprintf(
                    'Task dependency would create a cycle: %s',
                    implode(' -> ', $path),
                ));
            }
            foreach ($edges[$current] ?? [] as $next) {
                if (!\array_key_exists($next, $via)) {
                    $via[$next] = $current;
                    $queue[] = $next;
                }
            }
        }
    }

    /**
     * The default {@see releaseOrphanedClaims()} probe: is $pid alive and,
     * when a start time was recorded, still the same process?
     *
     * A zombie counts as dead (it will do no more work). When procfs cannot
     * answer the start time, signal 0 decides, so an unreadable /proc errs
     * toward "alive" — a claim held a little longer, never one stolen from a
     * claimant still working.
     */
    private static function processAlive(int $pid, ?int $startTime): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $stat = ProcessTree::stat($pid);
        if ($stat !== null && ($stat['state'] === 'Z' || $stat['state'] === 'X')) {
            return false;
        }

        if ($startTime !== null) {
            $observed = BackgroundSupervisor::procStartTime($pid);
            if ($observed !== null) {
                return $observed === $startTime;
            }
        }

        if (!\function_exists('posix_kill')) {
            return $stat !== null;
        }

        return \posix_kill($pid, 0) || \posix_get_last_error() === 1; // EPERM: alive, another uid
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
        $hash = \hash('sha256', $taskId);
        return \dirname($this->dbPath) . '/task_locks/' . $hash . '.lock';
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
            @\mkdir($dir, 0755, true);
        }

        $fp = \fopen($lockPath, 'a');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open lock file: {$lockPath}");
        }
        try {
            $this->flockTimed($fp, \LOCK_EX, $lockPath);
        } catch (\RuntimeException $failure) {
            \fclose($fp);

            throw $failure;
        }

        return $fp;
    }

    /**
     * How long a flock() here may block before it fails diagnosably (E137).
     *
     * A bare flock() parks the calling process — and through it the TUI or
     * the pool worker — for an unbounded wait on a lock another process may
     * never release. Every site that used to call flock() blocking now calls
     * this instead: LOCK_NB polled every 10ms against a deadline, default
     * 5.0s to match the SQLite busyTimeout {@see getConnection()} already
     * sets on the same handle, so the two lock layers wait the same amount
     * for the same reason. A timeout throws with the contended PATH in the
     * message — a hang that names itself is debuggable; a silent one is not.
     */
    private function flockTimed(mixed $fp, int $flags, string $what): void
    {
        // E679: one bounded-lock implementation package-wide; the message,
        // the poll and the throw-on-timeout doctrine (E137) moved to
        // {@see TimedFileLock::acquire()} unchanged. The per-instance budget
        // and its test seam stay here.
        TimedFileLock::acquire($fp, $flags, $what, $this->lockWaitSeconds);
    }

    /** Default bounded-lock wait; matches the SQLite busyTimeout in ms (§ E137). */
    private const DEFAULT_LOCK_WAIT_SECONDS = 5.0;

    private float $lockWaitSeconds = self::DEFAULT_LOCK_WAIT_SECONDS;

    /** Test seam: shrink the E137 wait so a timeout is fast to prove. */
    public function setLockWaitSecondsForTesting(float $seconds): void
    {
        $this->lockWaitSeconds = $seconds;
    }

    /**
     * Release the task lock, closing this process's handle to it.
     *
     * The lock file is deliberately NEVER unlinked. Deleting it here would
     * open a TOCTOU window: a contender already blocked in flock() on the
     * (now-unlinked) inode would be woken up and granted the lock on that
     * dead inode, while a fresh contender's fopen() on the same path would
     * create a brand-new inode and acquire an uncontended lock on it
     * immediately — letting two claimants run claimTask()'s critical
     * section at the same time. Keeping the lock file resident for the
     * whole process lifetime means the path always resolves to the same
     * inode, so flock() alone is a correct mutual-exclusion primitive.
     */
    private function releaseTaskLock(mixed $fp): void
    {
        \flock($fp, \LOCK_UN);
        \fclose($fp);
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
            // Same shape as Runtime::projectRoot(): the configured root when
            // there is one, the process directory otherwise. A hardcoded ''
            // here made a denying ScriptHook fail open, because proc_open()
            // cannot start a process in a directory that does not exist.
            projectRoot: $this->projectRoot ?? (getcwd() ?: ''),
        );
    }
}
