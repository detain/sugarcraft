<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Task;
use SugarCraft\Crush\Agents\TaskStatus;

/**
 * Tests for Task - data transfer object representing a team task.
 *
 * NOTE: PHP requires optional parameters to appear after all required parameters.
 * The Task constructor has `createdAt` (required) before optional params like
 * `claimedAt`, `completedAt`, and `dependsOn`. This means tests must explicitly
 * pass `assignedTo`, `status`, `result`, `error` even when using default values.
 */
final class TaskTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction and property access
    // -------------------------------------------------------------------------

    public function testConstructionWithAllFields(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $claimedAt = new \DateTimeImmutable('2026-01-01T10:05:00Z');
        $completedAt = new \DateTimeImmutable('2026-01-01T10:30:00Z');
        $dependsOn = ['task-1', 'task-2'];

        $task = new Task(
            id: 'task-42',
            teamId: 'team-alpha',
            title: 'Implement feature X',
            description: 'Build the new feature according to spec',
            prompt: 'You are a developer. Implement feature X.',
            assignedTo: 'teammate-7',
            status: TaskStatus::InProgress,
            result: null,
            error: null,
            createdAt: $createdAt,
            claimedAt: $claimedAt,
            completedAt: $completedAt,
            dependsOn: $dependsOn,
        );

        $this->assertSame('task-42', $task->id);
        $this->assertSame('team-alpha', $task->teamId);
        $this->assertSame('Implement feature X', $task->title);
        $this->assertSame('Build the new feature according to spec', $task->description);
        $this->assertSame('You are a developer. Implement feature X.', $task->prompt);
        $this->assertSame('teammate-7', $task->assignedTo);
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertNull($task->result);
        $this->assertNull($task->error);
        $this->assertSame($createdAt, $task->createdAt);
        $this->assertSame($claimedAt, $task->claimedAt);
        $this->assertSame($completedAt, $task->completedAt);
        $this->assertSame($dependsOn, $task->dependsOn);
    }

    public function testConstructionWithDefaultStatusAndNoAssignee(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');

        // PHP requires optional params after required ones; pass the optional
        // params that precede createdAt explicitly even when using defaults.
        $task = new Task(
            id: 'task-99',
            teamId: 'team-beta',
            title: 'Simple task',
            description: 'A simple task description',
            prompt: 'Do the thing.',
            assignedTo: null,           // explicit default
            status: TaskStatus::Pending, // explicit default
            result: null,               // explicit default
            error: null,                // explicit default
            createdAt: $createdAt,
        );

        $this->assertSame('task-99', $task->id);
        $this->assertSame('team-beta', $task->teamId);
        $this->assertSame('Simple task', $task->title);
        $this->assertSame('A simple task description', $task->description);
        $this->assertSame('Do the thing.', $task->prompt);
        $this->assertNull($task->assignedTo);
        $this->assertSame(TaskStatus::Pending, $task->status);
        $this->assertNull($task->result);
        $this->assertNull($task->error);
        $this->assertSame($createdAt, $task->createdAt);
        $this->assertNull($task->claimedAt);
        $this->assertNull($task->completedAt);
        $this->assertSame([], $task->dependsOn);
    }

    public function testConstructionWithAllStatusValues(): void
    {
        $createdAt = new \DateTimeImmutable();

        $statuses = [
            TaskStatus::Pending,
            TaskStatus::InProgress,
            TaskStatus::Completed,
            TaskStatus::Failed,
            TaskStatus::Blocked,
        ];

        foreach ($statuses as $status) {
            $task = new Task(
                id: 'task-status-test',
                teamId: 'team-gamma',
                title: 'Status test',
                description: 'Testing all statuses',
                prompt: 'Test prompt',
                assignedTo: null,
                status: $status,
                result: null,
                error: null,
                createdAt: $createdAt,
            );

            $this->assertSame($status, $task->status);
        }
    }

    public function testConstructionWithResult(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $completedAt = new \DateTimeImmutable('2026-01-01T10:30:00Z');

        $task = new Task(
            id: 'task-completed',
            teamId: 'team-delta',
            title: 'Completed task',
            description: 'A completed task',
            prompt: 'Do work.',
            assignedTo: 'teammate-2',
            status: TaskStatus::Completed,
            result: 'Feature implemented successfully',
            error: null,
            createdAt: $createdAt,
            completedAt: $completedAt,
        );

        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame('Feature implemented successfully', $task->result);
        $this->assertNull($task->error);
        $this->assertSame($completedAt, $task->completedAt);
    }

    public function testConstructionWithError(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $completedAt = new \DateTimeImmutable('2026-01-01T10:30:00Z');

        $task = new Task(
            id: 'task-failed',
            teamId: 'team-epsilon',
            title: 'Failed task',
            description: 'A failed task',
            prompt: 'Do work.',
            assignedTo: null,
            status: TaskStatus::Failed,
            result: null,
            error: 'Something went wrong: connection timeout',
            createdAt: $createdAt,
            completedAt: $completedAt,
        );

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertNull($task->result);
        $this->assertSame('Something went wrong: connection timeout', $task->error);
    }

    public function testDependsOnAcceptsEmptyArray(): void
    {
        $task = new Task(
            id: 'task-no-deps',
            teamId: 'team-zeta',
            title: 'No dependencies',
            description: 'This task has no dependencies',
            prompt: 'Just do it.',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            dependsOn: [],
        );

        $this->assertSame([], $task->dependsOn);
    }

    public function testDependsOnAcceptsMultipleDependencies(): void
    {
        $dependencies = ['task-a', 'task-b', 'task-c', 'task-d'];

        $task = new Task(
            id: 'task-multi-deps',
            teamId: 'team-eta',
            title: 'Multi dependency task',
            description: 'This task depends on multiple others',
            prompt: 'Wait for all dependencies.',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            dependsOn: $dependencies,
        );

        $this->assertCount(4, $task->dependsOn);
        $this->assertSame($dependencies, $task->dependsOn);
    }

    public function testPropertiesAreReadonly(): void
    {
        $createdAt = new \DateTimeImmutable();

        $task1 = new Task(
            id: 'task-readonly',
            teamId: 'team-theta',
            title: 'Readonly test',
            description: 'Verify properties are readonly',
            prompt: 'Check immutability.',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: $createdAt,
        );

        $task2 = new Task(
            id: 'different-id',
            teamId: 'team-theta',
            title: 'Readonly test',
            description: 'Verify properties are readonly',
            prompt: 'Check immutability.',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: $createdAt,
        );

        // Verify original task is unchanged
        $this->assertSame('task-readonly', $task1->id);
        $this->assertSame('team-theta', $task1->teamId);
        // Verify two instances with different ids are different objects
        $this->assertNotSame($task1, $task2);
        $this->assertSame('different-id', $task2->id);
    }

    public function testConstructionWithClaimedButNotCompleted(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $claimedAt = new \DateTimeImmutable('2026-01-01T10:05:00Z');

        $task = new Task(
            id: 'task-claimed',
            teamId: 'team-iota',
            title: 'Claimed task',
            description: 'A task that is claimed but not done',
            prompt: 'Work in progress.',
            assignedTo: 'teammate-3',
            status: TaskStatus::InProgress,
            result: null,
            error: null,
            createdAt: $createdAt,
            claimedAt: $claimedAt,
        );

        $this->assertSame('teammate-3', $task->assignedTo);
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertSame($claimedAt, $task->claimedAt);
        $this->assertNull($task->completedAt);
        $this->assertNull($task->result);
        $this->assertNull($task->error);
    }
}
