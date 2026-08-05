<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\TaskStatus;

/**
 * Tests for TaskStatus enum - lifecycle states for tasks within a team.
 */
final class TaskStatusTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Case existence
    // -------------------------------------------------------------------------

    public function testAllCasesExist(): void
    {
        $this->assertSame('pending', TaskStatus::Pending->value);
        $this->assertSame('in_progress', TaskStatus::InProgress->value);
        $this->assertSame('completed', TaskStatus::Completed->value);
        $this->assertSame('failed', TaskStatus::Failed->value);
        $this->assertSame('blocked', TaskStatus::Blocked->value);
    }

    public function testCaseCount(): void
    {
        $cases = TaskStatus::cases();
        $this->assertCount(5, $cases);
    }

    // -------------------------------------------------------------------------
    // TaskStatus::from() - valid values
    // -------------------------------------------------------------------------

    public function testFromPending(): void
    {
        $this->assertSame(TaskStatus::Pending, TaskStatus::from('pending'));
    }

    public function testFromInProgress(): void
    {
        $this->assertSame(TaskStatus::InProgress, TaskStatus::from('in_progress'));
    }

    public function testFromCompleted(): void
    {
        $this->assertSame(TaskStatus::Completed, TaskStatus::from('completed'));
    }

    public function testFromFailed(): void
    {
        $this->assertSame(TaskStatus::Failed, TaskStatus::from('failed'));
    }

    public function testFromBlocked(): void
    {
        $this->assertSame(TaskStatus::Blocked, TaskStatus::from('blocked'));
    }

    // -------------------------------------------------------------------------
    // TaskStatus::from() - invalid value throws
    // -------------------------------------------------------------------------

    public function testFromInvalidThrows(): void
    {
        $this->expectException(\ValueError::class);
        TaskStatus::from('invalid');
    }

    // -------------------------------------------------------------------------
    // TaskStatus::tryFrom() - valid values
    // -------------------------------------------------------------------------

    public function testTryFromPending(): void
    {
        $this->assertSame(TaskStatus::Pending, TaskStatus::tryFrom('pending'));
    }

    public function testTryFromInProgress(): void
    {
        $this->assertSame(TaskStatus::InProgress, TaskStatus::tryFrom('in_progress'));
    }

    public function testTryFromCompleted(): void
    {
        $this->assertSame(TaskStatus::Completed, TaskStatus::tryFrom('completed'));
    }

    public function testTryFromFailed(): void
    {
        $this->assertSame(TaskStatus::Failed, TaskStatus::tryFrom('failed'));
    }

    public function testTryFromBlocked(): void
    {
        $this->assertSame(TaskStatus::Blocked, TaskStatus::tryFrom('blocked'));
    }

    // -------------------------------------------------------------------------
    // TaskStatus::tryFrom() - invalid values return null
    // -------------------------------------------------------------------------

    public function testTryFromInvalidReturnsNull(): void
    {
        $this->assertNull(TaskStatus::tryFrom('invalid'));
    }

    public function testTryFromEmptyStringReturnsNull(): void
    {
        $this->assertNull(TaskStatus::tryFrom(''));
    }

    public function testTryFromCaseMismatchReturnsNull(): void
    {
        // Enum::from() is case-sensitive
        $this->assertNull(TaskStatus::tryFrom('PENDING'));
        $this->assertNull(TaskStatus::tryFrom('InProgress'));
    }
}
