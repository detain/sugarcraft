<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\WorktreeIsolationMode;

/**
 * Tests for WorktreeIsolationMode enum.
 */
final class WorktreeIsolationModeTest extends TestCase
{
    public function testWorktreeCase(): void
    {
        $this->assertSame('worktree', WorktreeIsolationMode::Worktree->value);
    }

    public function testBranchCase(): void
    {
        $this->assertSame('branch', WorktreeIsolationMode::Branch->value);
    }

    public function testPathCase(): void
    {
        $this->assertSame('path', WorktreeIsolationMode::Path->value);
    }

    public function testAllCases(): void
    {
        $cases = WorktreeIsolationMode::cases();
        $this->assertCount(3, $cases);
        $this->assertContainsOnly(WorktreeIsolationMode::class, $cases);
    }

    public function testFromValueWorktree(): void
    {
        $case = WorktreeIsolationMode::from('worktree');
        $this->assertSame(WorktreeIsolationMode::Worktree, $case);
    }

    public function testFromValueBranch(): void
    {
        $case = WorktreeIsolationMode::from('branch');
        $this->assertSame(WorktreeIsolationMode::Branch, $case);
    }

    public function testFromValuePath(): void
    {
        $case = WorktreeIsolationMode::from('path');
        $this->assertSame(WorktreeIsolationMode::Path, $case);
    }

    public function testTryFromInvalidValue(): void
    {
        $case = WorktreeIsolationMode::tryFrom('invalid');
        $this->assertNull($case);
    }
}
