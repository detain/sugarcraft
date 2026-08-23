<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Task;
use SugarCraft\Crush\Agents\TaskList;
use SugarCraft\Crush\Agents\TaskStatus;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * Tests for TaskList — SQLite-backed task list with schema init and core CRUD.
 */
final class TaskListTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private string $dbPath;

    protected function setUp(): void
    {
        // Use a unique in-memory database per test — ':memory:' is per-connection
        // so we use a file-based tmp db that gets cleaned up automatically.
        $this->dbPath = \sys_get_temp_dir() . '/tasklist_test_' . \uniqid((string) getmypid(), true) . '.sqlite3';
    }

    protected function tearDown(): void
    {
        // FIRST, and the ordering is the whole point: the claimants below
        // hammer $this->dbPath in a retry loop, so an abort at the per-test
        // time limit (which is pcntl_alarm() and fires in this process only)
        // must stop them before the unlink underneath them.
        $this->reapTrackedForkedChildren();

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
    // getTasksByStatus
    // -------------------------------------------------------------------------

    public function testGetTasksByStatusReturnsTasksWithMatchingStatus(): void
    {
        $list = new TaskList($this->dbPath);

        $list->addTask($this->makeTask('task-pending-1', 'team-st', 'Pending 1'));
        $list->addTask($this->makeTask('task-pending-2', 'team-st', 'Pending 2'));
        $list->addTask($this->makeTask('task-inprogress', 'team-st', 'InProgress', status: TaskStatus::InProgress));
        $list->addTask($this->makeTask('task-completed', 'team-st', 'Completed', status: TaskStatus::Completed));

        $pending = $list->getTasksByStatus(TaskStatus::Pending);
        $inProgress = $list->getTasksByStatus(TaskStatus::InProgress);
        $completed = $list->getTasksByStatus(TaskStatus::Completed);
        $failed = $list->getTasksByStatus(TaskStatus::Failed);

        $this->assertCount(2, $pending);
        $this->assertCount(1, $inProgress);
        $this->assertCount(1, $completed);
        $this->assertCount(0, $failed);

        $pendingIds = array_map(fn(Task $t) => $t->id, $pending);
        $this->assertContains('task-pending-1', $pendingIds);
        $this->assertContains('task-pending-2', $pendingIds);
    }

    public function testGetTasksByStatusReturnsEmptyArrayWhenNoneMatch(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('some-task', 'team-st', 'Some task'));

        $result = $list->getTasksByStatus(TaskStatus::Failed);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testGetTasksByStatusFiltersCorrectlyAcrossStatuses(): void
    {
        $list = new TaskList($this->dbPath);

        $list->addTask($this->makeTask('p1', 'team-multi', 'Pending 1'));
        $list->addTask($this->makeTask('p2', 'team-multi', 'Pending 2', status: TaskStatus::Pending));
        $list->addTask($this->makeTask('c1', 'team-multi', 'Completed 1', status: TaskStatus::Completed));

        $pending = $list->getTasksByStatus(TaskStatus::Pending);
        $this->assertCount(2, $pending);

        $completed = $list->getTasksByStatus(TaskStatus::Completed);
        $this->assertCount(1, $completed);
        $this->assertSame('c1', $completed[0]->id);
    }

    // -------------------------------------------------------------------------
    // getTasksForTeammate
    // -------------------------------------------------------------------------

    public function testGetTasksForTeammateReturnsTasksAssignedToTeammate(): void
    {
        $list = new TaskList($this->dbPath);

        $list->addTask($this->makeTask('task-a', 'team-tm', 'Task A', [], TaskStatus::Pending, 'teammate-x'));
        $list->addTask($this->makeTask('task-b', 'team-tm', 'Task B', [], TaskStatus::Pending, 'teammate-x'));
        $list->addTask($this->makeTask('task-c', 'team-tm', 'Task C', [], TaskStatus::Pending, 'teammate-y'));
        $list->addTask($this->makeTask('task-unassigned', 'team-tm', 'Unassigned'));

        $tasksForX = $list->getTasksForTeammate('teammate-x');
        $tasksForY = $list->getTasksForTeammate('teammate-y');
        $tasksForZ = $list->getTasksForTeammate('teammate-z');

        $this->assertCount(2, $tasksForX);
        $this->assertCount(1, $tasksForY);
        $this->assertCount(0, $tasksForZ);

        $idsForX = array_map(fn(Task $t) => $t->id, $tasksForX);
        $this->assertContains('task-a', $idsForX);
        $this->assertContains('task-b', $idsForX);
    }

    public function testGetTasksForTeammateReturnsEmptyArrayWhenNoMatches(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('some-task', 'team-empty', 'Some task'));

        $result = $list->getTasksForTeammate('nonexistent-teammate');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testGetTasksForTeammateExcludesCompletedTasks(): void
    {
        $list = new TaskList($this->dbPath);

        $list->addTask($this->makeTask('active-task', 'team-tm', 'Active', [], TaskStatus::InProgress, 'teammate-m'));
        $list->addTask($this->makeTask('done-task', 'team-tm', 'Done', [], TaskStatus::Completed, 'teammate-m'));

        $tasks = $list->getTasksForTeammate('teammate-m');

        $this->assertCount(2, $tasks);
        $ids = array_map(fn(Task $t) => $t->id, $tasks);
        $this->assertContains('active-task', $ids);
        $this->assertContains('done-task', $ids);
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
    // claimTask
    // -------------------------------------------------------------------------

    public function testClaimTaskReturnsTrueWhenTaskIsClaimable(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('claimable', 'team-claim', 'Claim me'));

        $result = $list->claimTask('claimable', 'teammate-a');

        $this->assertTrue($result);
        $task = $list->getTask('claimable');
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertSame('teammate-a', $task->assignedTo);
        $this->assertNotNull($task->claimedAt);
    }

    public function testClaimTaskReturnsFalseWhenAlreadyClaimed(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('already-claimed', 'team-claim', 'Claim me'));

        // First claim succeeds
        $first = $list->claimTask('already-claimed', 'teammate-a');
        $this->assertTrue($first);

        // Second claim fails
        $second = $list->claimTask('already-claimed', 'teammate-b');
        $this->assertFalse($second);
    }

    public function testClaimTaskReturnsFalseWhenTaskDoesNotExist(): void
    {
        $list = new TaskList($this->dbPath);

        $result = $list->claimTask('non-existent', 'teammate-a');

        $this->assertFalse($result);
    }

    public function testClaimTaskReturnsFalseWhenTaskIsNotPending(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('in-progress', 'team-claim', 'Already started', status: TaskStatus::InProgress));

        $result = $list->claimTask('in-progress', 'teammate-a');

        $this->assertFalse($result);
    }

    public function testClaimTaskReturnsFalseWhenAssignedToAnotherTeammate(): void
    {
        $list = new TaskList($this->dbPath);
        $task = new Task(
            id: 'assigned-task',
            teamId: 'team-claim',
            title: 'Already assigned',
            description: 'Desc',
            prompt: 'Do it.',
            assignedTo: 'teammate-x', // Already assigned to someone else
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
        );
        $list->addTask($task);

        $result = $list->claimTask('assigned-task', 'teammate-a');

        $this->assertFalse($result);
    }

    public function testClaimTaskReturnsTrueForTaskAssignedToSameTeammate(): void
    {
        $list = new TaskList($this->dbPath);
        $task = new Task(
            id: 'my-task',
            teamId: 'team-claim',
            title: 'My task',
            description: 'Desc',
            prompt: 'Do it.',
            assignedTo: 'teammate-a', // Assigned to same teammate
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
        );
        $list->addTask($task);

        $result = $list->claimTask('my-task', 'teammate-a');

        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // claimTask with dependencies
    // -------------------------------------------------------------------------

    public function testClaimTaskWithDepsBlockedWhenDepNotCompleted(): void
    {
        $list = new TaskList($this->dbPath);
        // Task depends on 'dep-task' which is not completed
        $list->addTask($this->makeTask('blocked-task', 'team-deps', 'Blocked', ['dep-task']));

        $result = $list->claimTask('blocked-task', 'teammate-a');

        $this->assertFalse($result);
        $task = $list->getTask('blocked-task');
        $this->assertSame(TaskStatus::Pending, $task->status);
    }

    public function testClaimTaskWithDepsSucceedsWhenDepCompleted(): void
    {
        $list = new TaskList($this->dbPath);
        // Add the dependency task and complete it
        $list->addTask($this->makeTask('dep-task', 'team-deps', 'Dependency'));
        $list->completeTask('dep-task', 'done');

        // Now add the dependent task
        $list->addTask($this->makeTask('ready-task', 'team-deps', 'Ready now', ['dep-task']));

        $result = $list->claimTask('ready-task', 'teammate-a');

        $this->assertTrue($result);
        $task = $list->getTask('ready-task');
        $this->assertSame(TaskStatus::InProgress, $task->status);
    }

    public function testClaimTaskWithMultipleDepsAllMustBeCompleted(): void
    {
        $list = new TaskList($this->dbPath);
        // Add two dependency tasks
        $list->addTask($this->makeTask('dep-1', 'team-deps', 'Dep 1'));
        $list->addTask($this->makeTask('dep-2', 'team-deps', 'Dep 2'));
        // Complete only one
        $list->completeTask('dep-1', 'done');

        // Task depends on both
        $list->addTask($this->makeTask('waiting-task', 'team-deps', 'Waiting', ['dep-1', 'dep-2']));

        $result = $list->claimTask('waiting-task', 'teammate-a');

        $this->assertFalse($result);

        // Complete the second dependency
        $list->completeTask('dep-2', 'done');

        $result = $list->claimTask('waiting-task', 'teammate-a');

        $this->assertTrue($result);
    }

    // -------------------------------------------------------------------------
    // addDependency
    // -------------------------------------------------------------------------

    public function testAddDependencyAppendsToExistingDependencies(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('task-with-dep', 'team-add', 'Has dep', ['existing-dep']));

        $list->addDependency('task-with-dep', 'new-dep');

        $task = $list->getTask('task-with-dep');
        $this->assertSame(['existing-dep', 'new-dep'], $task->dependsOn);
    }

    public function testAddDependencyDoesNotDuplicate(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('task-no-dup', 'team-add', 'No dup', ['some-dep']));

        $list->addDependency('task-no-dup', 'some-dep'); // Add same dep again

        $task = $list->getTask('task-no-dup');
        $this->assertSame(['some-dep'], $task->dependsOn);
    }

    public function testAddDependencyThrowsWhenTaskNotFound(): void
    {
        $list = new TaskList($this->dbPath);

        $this->expectException(\SQLite3Exception::class);
        $list->addDependency('non-existent-task', 'some-dep');
    }

    // -------------------------------------------------------------------------
    // getUnblockedTasks
    // -------------------------------------------------------------------------

    public function testGetUnblockedTasksReturnsUnblockedTasks(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('unblocked-1', 'team-ub', 'First'));
        $list->addTask($this->makeTask('unblocked-2', 'team-ub', 'Second'));

        $unblocked = $list->getUnblockedTasks('teammate-a');

        $this->assertCount(2, $unblocked);
    }

    public function testGetUnblockedTasksExcludesTaskAssignedToOther(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('others-task', 'team-ub', 'Others', [], TaskStatus::Pending, 'teammate-x'));

        $unblocked = $list->getUnblockedTasks('teammate-a');

        $this->assertCount(0, $unblocked);
    }

    public function testGetUnblockedTasksExcludesTaskWithIncompleteDeps(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('blocked', 'team-ub', 'Blocked', ['missing-dep']));

        $unblocked = $list->getUnblockedTasks('teammate-a');

        $this->assertCount(0, $unblocked);
    }

    public function testGetUnblockedTasksIncludesTaskWithCompletedDeps(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('dep', 'team-ub', 'Dep'));
        $list->completeTask('dep', 'done');
        $list->addTask($this->makeTask('now-unblocked', 'team-ub', 'Now free', ['dep']));

        $unblocked = $list->getUnblockedTasks('teammate-a');

        $this->assertCount(1, $unblocked);
        $this->assertSame('now-unblocked', $unblocked[0]->id);
    }

    public function testGetUnblockedTasksFiltersByTeammateAssignment(): void
    {
        $list = new TaskList($this->dbPath);
        // Unassigned task - visible to all
        $list->addTask($this->makeTask('unassigned', 'team-ub', 'Unassigned'));
        // Task assigned to teammate-a - visible only to teammate-a
        $list->addTask($this->makeTask('mine', 'team-ub', 'Mine', [], TaskStatus::Pending, 'teammate-a'));
        // Task assigned to teammate-b - not visible to teammate-a
        $list->addTask($this->makeTask('theirs', 'team-ub', 'Theirs', [], TaskStatus::Pending, 'teammate-b'));

        $unblocked = $list->getUnblockedTasks('teammate-a');

        $this->assertCount(2, $unblocked);
        $ids = array_map(fn(Task $t) => $t->id, $unblocked);
        $this->assertContains('unassigned', $ids);
        $this->assertContains('mine', $ids);
        $this->assertNotContains('theirs', $ids);
    }

    // -------------------------------------------------------------------------
    // Sequential double-claim rejection (business logic only — NOT a
    // concurrency/TOCTOU regression test; see testConcurrentForkedClaimAllowsExactlyOneWinner
    // below for the real multi-process race coverage).
    // -------------------------------------------------------------------------

    /**
     * Two sequential claimTask() calls, one after another in a single
     * process, against separate TaskList instances sharing the same
     * database. This only proves that claimTask() correctly rejects a
     * claim on a task that has already transitioned out of Pending by the
     * time it runs — it never has two claims in flight at once, so it
     * cannot observe (and must not be read as coverage for) the
     * flock()-based mutual-exclusion / TOCTOU behaviour that
     * testConcurrentForkedClaimAllowsExactlyOneWinner exercises with real
     * forked OS processes below.
     */
    public function testSecondSequentialClaimIsRejectedAfterFirstSucceeds(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('race-task', 'team-race', 'Race condition'));

        $first = $list->claimTask('race-task', 'teammate-a');
        $this->assertTrue($first);

        // A second, independent TaskList instance against the same database
        // — still called strictly after the first call returns, so this is
        // sequential, not concurrent.
        $list2 = new TaskList($this->dbPath);
        $second = $list2->claimTask('race-task', 'teammate-b');

        $this->assertFalse($second);

        // Verify the task is still assigned to the first claimer
        $task = $list->getTask('race-task');
        $this->assertSame('teammate-a', $task->assignedTo);
        $this->assertSame(TaskStatus::InProgress, $task->status);
    }

    // -------------------------------------------------------------------------
    // Real concurrent claim regression (R1: releaseTaskLock() TOCTOU)
    // -------------------------------------------------------------------------

    /**
     * Real multi-process race: fork N children that all race to claim the
     * SAME task, in true OS processes with independent SQLite3 connections
     * and independent file descriptors — the only way to actually exercise
     * flock()-based mutual exclusion. A single-process, sequential test
     * (like testSecondSequentialClaimIsRejectedAfterFirstSucceeds above)
     * can never observe a TOCTOU race because nothing genuinely runs at
     * the same time.
     *
     * Regression coverage for R1: the old releaseTaskLock() called
     * flock(LOCK_UN) and then unlinked the per-task lock file. Every claim
     * attempt that BAILS OUT (task blocked by an unmet dependency, wrong
     * assignee, etc.) still goes through that unlock-then-unlink cycle even
     * though it wrote nothing — so while a task is blocked, many bailout
     * cycles unlink-and-recreate the lock file in quick succession. If that
     * churn is happening at the exact moment the blocking dependency
     * completes, a contender that was blocked on the (now unlinked, but
     * still valid as a lock) old inode can be woken up and proceed with its
     * claim at the same time as a brand new contender who fopen()'d the
     * freshly recreated inode — two processes now both believe they hold
     * the exclusive per-task lock, both observe "pending", and both write a
     * successful claim.
     *
     * To reproduce that window deterministically, this test seeds a task
     * with an unmet dependency, starts many children hammering claimTask()
     * for it in a tight retry loop (maximizing unlock/unlink churn), then
     * completes the dependency from a separate process partway through —
     * exactly the "still-being-decided outcome" scenario the bug exposed.
     * Confirmed against the pre-fix code: this design reliably produced
     * 3-5 simultaneous winners per run; with the fix it is reliably 1.
     */
    public function testConcurrentForkedClaimAllowsExactlyOneWinner(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl extension not available.');
        }

        $childCount = 40;
        $resultDir = $this->dbPath . '.results';
        \mkdir($resultDir, 0755, true);

        $list = new TaskList($this->dbPath);
        $list->addTask($this->makeTask('dep-fork', 'team-fork-race', 'Dependency'));
        $list->addTask($this->makeTask('race-task-fork', 'team-fork-race', 'Fork race', ['dep-fork']));

        // Forget the cached SQLite3 connection before forking, so every
        // child below opens its OWN fresh connection instead of racing on a
        // fork-inherited (copy-on-write) one — SQLite3 connections are not
        // documented as fork-safe, and the point of this test is to exercise
        // the real multi-process contract (independent process, independent
        // connection, independent file descriptors).
        $this->resetTaskListConnectionCache();

        $pids = [];
        for ($i = 0; $i < $childCount; $i++) {
            $pid = $this->forkTracked();
            $this->assertNotSame(-1, $pid, 'pcntl_fork() must succeed.');

            if ($pid === 0) {
                // Child: retry in a tight loop for up to 2s so a good number
                // of claim attempts land right around the dependency
                // completing (below), maximizing unlock/unlink churn at the
                // exact moment the task transitions from blocked to claimable.
                $childList = new TaskList($this->dbPath);
                $deadline = \microtime(true) + 2.0;
                $claimed = false;
                while (\microtime(true) < $deadline) {
                    if ($childList->claimTask('race-task-fork', "teammate-{$i}")) {
                        $claimed = true;
                        break;
                    }
                }
                if ($claimed) {
                    \file_put_contents($resultDir . "/{$i}.won", "teammate-{$i}");
                }

                // Not a plain exit(): that runs PHP's shutdown sequence in
                // every one of these children, over a COPY of this process's
                // object graph - each inherited destructor and each
                // register_shutdown_function callback, N extra times. (NOT
                // PHPUnit's after-test hooks: an exiting child never returns
                // into the runner, so those fire only in the parent. See
                // {@see \SugarCraft\Crush\Tests\Support\ForkedChildExitConventionTest}
                // for the probe that separates the two shapes.)
                ForkedChild::exitNow(0);
            }

            $pids[] = $pid;
        }

        // Completer: satisfies the dependency shortly after the claimants
        // start racing, so the task transitions from blocked to claimable
        // while many of them are mid-retry-loop.
        $completerPid = $this->forkTracked();
        $this->assertNotSame(-1, $completerPid, 'pcntl_fork() must succeed.');
        if ($completerPid === 0) {
            \usleep(50_000);
            $completerList = new TaskList($this->dbPath);
            $completerList->completeTask('dep-fork', 'done');
            ForkedChild::exitNow(0);
        }
        $pids[] = $completerPid;

        foreach ($pids as $pid) {
            \pcntl_waitpid($pid, $status);
        }

        $winners = \glob($resultDir . '/*.won');
        $this->assertCount(
            1,
            $winners,
            "Expected exactly one winner among {$childCount} concurrent claimants, got: "
                . implode(', ', $winners)
        );

        $task = $list->getTask('race-task-fork');
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertNotNull($task->assignedTo);

        foreach ($winners as $winnerFile) {
            \unlink($winnerFile);
        }
        \rmdir($resultDir);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Clear TaskList's static connection cache via reflection.
     *
     * Used only by testConcurrentForkedClaimAllowsExactlyOneWinner: existing
     * TaskList instances keep their own SQLite3 handle regardless (it is
     * captured as an instance property at construction time), so this only
     * affects connections opened by `new TaskList(...)` calls made after it
     * runs — specifically, the ones inside the forked children.
     */
    private function resetTaskListConnectionCache(): void
    {
        $property = new \ReflectionProperty(TaskList::class, 'connections');
        $property->setValue(null, []);
    }

    /**
     * Create a Task with sensible defaults for testing.
     */
    private function makeTask(
        string $id,
        string $teamId,
        string $title,
        array $dependsOn = [],
        TaskStatus $status = TaskStatus::Pending,
        ?string $assignedTo = null,
    ): Task {
        return new Task(
            id: $id,
            teamId: $teamId,
            title: $title,
            description: "Description for {$title}",
            prompt: "Prompt for {$title}",
            assignedTo: $assignedTo,
            status: $status,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            dependsOn: $dependsOn,
        );
    }
}
