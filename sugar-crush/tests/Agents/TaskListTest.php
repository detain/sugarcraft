<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Task;
use SugarCraft\Crush\Agents\TaskList;
use SugarCraft\Crush\Agents\TaskStatus;

/**
 * Tests for TaskList — SQLite-backed task list with schema init and core CRUD.
 */
final class TaskListTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        // Use a unique in-memory database per test — ':memory:' is per-connection
        // so we use a file-based tmp db that gets cleaned up automatically.
        $this->dbPath = \sys_get_temp_dir() . '/tasklist_test_' . \uniqid() . '.sqlite3';
    }

    protected function tearDown(): void
    {
        if (\file_exists($this->dbPath)) {
            \unlink($this->dbPath);
        }
    }

    // -------------------------------------------------------------------------
    // addTask
    // -------------------------------------------------------------------------

    public function testAddTaskReturnsTaskId(): void
    {
        $list = new TaskList($this->dbPath);
        $task = $this->makeTask('task-1', 'team-a', 'First task');

        $id = $list->addTask($task);

        $this->assertSame('task-1', $id);
    }

    public function testAddTaskPersistsTask(): void
    {
        $list = new TaskList($this->dbPath);
        $task = $this->makeTask('task-persist', 'team-a', 'Persist test');

        $list->addTask($task);

        $found = $list->getTask('task-persist');
        $this->assertNotNull($found);
        $this->assertSame('task-persist', $found->id);
        $this->assertSame('Persist test', $found->title);
        $this->assertSame(TaskStatus::Pending, $found->status);
    }

    public function testAddTaskWithNonPendingStatus(): void
    {
        $list = new TaskList($this->dbPath);
        $task = new Task(
            id: 'task-status',
            teamId: 'team-b',
            title: 'Status test',
            description: 'Testing status',
            prompt: 'Do it.',
            assignedTo: null,
            status: TaskStatus::InProgress,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
        );

        $list->addTask($task);

        $found = $list->getTask('task-status');
        $this->assertSame(TaskStatus::InProgress, $found->status);
    }

    public function testAddTaskWithDependencies(): void
    {
        $list = new TaskList($this->dbPath);
        $task = $this->makeTask('task-deps', 'team-c', 'With deps', ['dep-1', 'dep-2']);

        $list->addTask($task);

        $found = $list->getTask('task-deps');
        $this->assertSame(['dep-1', 'dep-2'], $found->dependsOn);
    }

    public function testAddTaskMultipleTasksCanBeAdded(): void
    {
        $list = new TaskList($this->dbPath);

        $list->addTask($this->makeTask('t1', 'team-x', 'One'));
        $list->addTask($this->makeTask('t2', 'team-x', 'Two'));
        $list->addTask($this->makeTask('t3', 'team-x', 'Three'));

        $pending = $list->getPendingTasks();
        $this->assertCount(3, $pending);
    }

    // -------------------------------------------------------------------------
    // getPendingTasks
    // -------------------------------------------------------------------------

    public function testGetPendingTasksReturnsOnlyPending(): void
    {
        $list = new TaskList($this->dbPath);

        $list->addTask($this->makeTask('t-pending', 'team-y', 'Pending'));
        $list->addTask($this->makeTask('t-inprogress', 'team-y', 'InProgress', status: TaskStatus::InProgress));
        $list->addTask($this->makeTask('t-completed', 'team-y', 'Completed', status: TaskStatus::Completed));
        $list->addTask($this->makeTask('t-failed', 'team-y', 'Failed', status: TaskStatus::Failed));

        $pending = $list->getPendingTasks();

        $this->assertCount(1, $pending);
        $this->assertSame('t-pending', $pending[0]->id);
    }

    public function testGetPendingTasksReturnsEmptyArrayWhenNone(): void
    {
        $list = new TaskList($this->dbPath);

        $result = $list->getPendingTasks();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // getTask
    // -------------------------------------------------------------------------

    public function testGetTaskReturnsTaskWhenExists(): void
    {
        $list = new TaskList($this->dbPath);
        $task = $this->makeTask('find-me', 'team-z', 'Find me');

        $list->addTask($task);

        $found = $list->getTask('find-me');

        $this->assertNotNull($found);
        $this->assertSame('find-me', $found->id);
        $this->assertSame('Find me', $found->title);
        $this->assertSame('team-z', $found->teamId);
    }

    public function testGetTaskReturnsNullWhenNotFound(): void
    {
        $list = new TaskList($this->dbPath);

        $found = $list->getTask('non-existent-id');

        $this->assertNull($found);
    }

    // -------------------------------------------------------------------------
    // updateTaskStatus
    // -------------------------------------------------------------------------

    public function testUpdateTaskStatusChangesStatus(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('update-me', 'team-w', 'Update me'));

        $list->updateTaskStatus('update-me', TaskStatus::InProgress);

        $found = $list->getTask('update-me');
        $this->assertSame(TaskStatus::InProgress, $found->status);
    }

    public function testUpdateTaskStatusToBlocked(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('block-me', 'team-w', 'Block me'));

        $list->updateTaskStatus('block-me', TaskStatus::Blocked);

        $found = $list->getTask('block-me');
        $this->assertSame(TaskStatus::Blocked, $found->status);
    }

    public function testUpdateTaskStatusDoesNotAffectOtherFields(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('keep-fields', 'team-v', 'Keep fields'));

        $list->updateTaskStatus('keep-fields', TaskStatus::InProgress);

        $found = $list->getTask('keep-fields');
        $this->assertSame('keep-fields', $found->id);
        $this->assertSame('team-v', $found->teamId);
        $this->assertSame('Keep fields', $found->title);
        $this->assertSame(TaskStatus::InProgress, $found->status);
        $this->assertNull($found->result);
        $this->assertNull($found->error);
    }

    // -------------------------------------------------------------------------
    // completeTask
    // -------------------------------------------------------------------------

    public function testCompleteTaskSetsStatusCompletedAndResult(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('complete-me', 'team-u', 'Complete me'));

        $list->completeTask('complete-me', "All done, here's the result");

        $found = $list->getTask('complete-me');
        $this->assertSame(TaskStatus::Completed, $found->status);
        $this->assertSame("All done, here's the result", $found->result);
        $this->assertNull($found->error);
        $this->assertNotNull($found->completedAt);
    }

    public function testCompleteTaskSetsCompletedAtTimestamp(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('complete-time', 'team-t', 'Complete time'));

        $before = new \DateTimeImmutable();
        $list->completeTask('complete-time', 'done');
        $after = new \DateTimeImmutable();

        $found = $list->getTask('complete-time');
        $this->assertNotNull($found->completedAt);
        $this->assertGreaterThanOrEqual($before->format('U'), $found->completedAt->format('U'));
        $this->assertLessThanOrEqual($after->format('U'), $found->completedAt->format('U'));
    }

    public function testCompleteTaskRemovesFromPendingTasks(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('done-pending', 'team-s', 'Done pending'));

        $list->completeTask('done-pending', 'done');

        $pending = $list->getPendingTasks();
        $this->assertCount(0, $pending);
    }

    // -------------------------------------------------------------------------
    // failTask
    // -------------------------------------------------------------------------

    public function testFailTaskSetsStatusFailedAndError(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('fail-me', 'team-r', 'Fail me'));

        $list->failTask('fail-me', 'Connection timeout after 30s');

        $found = $list->getTask('fail-me');
        $this->assertSame(TaskStatus::Failed, $found->status);
        $this->assertNull($found->result);
        $this->assertSame('Connection timeout after 30s', $found->error);
        $this->assertNotNull($found->completedAt);
    }

    public function testFailTaskSetsCompletedAtTimestamp(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('fail-time', 'team-q', 'Fail time'));

        $before = new \DateTimeImmutable();
        $list->failTask('fail-time', 'error message');
        $after = new \DateTimeImmutable();

        $found = $list->getTask('fail-time');
        $this->assertNotNull($found->completedAt);
        $this->assertGreaterThanOrEqual($before->format('U'), $found->completedAt->format('U'));
        $this->assertLessThanOrEqual($after->format('U'), $found->completedAt->format('U'));
    }

    public function testFailTaskRemovesFromPendingTasks(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('fail-pending', 'team-p', 'Fail pending'));

        $list->failTask('fail-pending', 'something broke');

        $pending = $list->getPendingTasks();
        $this->assertCount(0, $pending);
    }

    // -------------------------------------------------------------------------
    // Ordering
    // -------------------------------------------------------------------------

    public function testGetPendingTasksOrdersByCreatedAtAscending(): void
    {
        $list = new TaskList($this->dbPath);

        $t1 = new Task(
            id: 'oldest',
            teamId: 'team-o',
            title: 'Oldest',
            description: 'Desc',
            prompt: 'Do it.',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable('2026-01-01T10:00:00Z'),
        );
        $t2 = new Task(
            id: 'newest',
            teamId: 'team-o',
            title: 'Newest',
            description: 'Desc',
            prompt: 'Do it.',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable('2026-01-01T11:00:00Z'),
        );
        $t3 = new Task(
            id: 'middle',
            teamId: 'team-o',
            title: 'Middle',
            description: 'Desc',
            prompt: 'Do it.',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable('2026-01-01T10:30:00Z'),
        );

        // Add out of order
        $list->addTask($t2);
        $list->addTask($t1);
        $list->addTask($t3);

        $pending = $list->getPendingTasks();

        $this->assertSame('oldest', $pending[0]->id);
        $this->assertSame('middle', $pending[1]->id);
        $this->assertSame('newest', $pending[2]->id);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Create a Task with sensible defaults for testing.
     */
    private function makeTask(
        string $id,
        string $teamId,
        string $title,
        array $dependsOn = [],
        TaskStatus $status = TaskStatus::Pending,
    ): Task {
        return new Task(
            id: $id,
            teamId: $teamId,
            title: $title,
            description: "Description for {$title}",
            prompt: "Prompt for {$title}",
            assignedTo: null,
            status: $status,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            dependsOn: $dependsOn,
        );
    }
}
