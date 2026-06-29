<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\WorktreeEntry;
use SugarCraft\Stash\Worktrees;
use PHPUnit\Framework\TestCase;

final class WorktreesPorcelainTest extends TestCase
{
    public function testParsesPorcelainRecords(): void
    {
        $lines = [
            'worktree /home/user/project',
            'HEAD a1b2c3d',
            'branch refs/heads/main',
            '',
            'worktree /home/user/project-feature',
            'HEAD e4f5g6h',
            'branch refs/heads/feature/widget',
            '',
            'worktree /home/user/project-detached',
            'HEAD i7j8k9l',
            'detached',
            '',
        ];
        $entries = Worktrees::fromGitOutput($lines);
        $this->assertCount(3, $entries);
        $this->assertSame('/home/user/project', $entries[0]->path);
        $this->assertSame('main', $entries[0]->branch);
        $this->assertSame('a1b2c3d', $entries[0]->HEAD);
        $this->assertFalse($entries[0]->isBare);
        $this->assertSame('/home/user/project-feature', $entries[1]->path);
        $this->assertSame('feature/widget', $entries[1]->branch);
        $this->assertSame('e4f5g6h', $entries[1]->HEAD);
        $this->assertFalse($entries[1]->isBare);
        $this->assertSame('/home/user/project-detached', $entries[2]->path);
        $this->assertSame('', $entries[2]->branch);
        $this->assertSame('i7j8k9l', $entries[2]->HEAD);
        $this->assertFalse($entries[2]->isBare);
    }

    public function testParsesBareWorktree(): void
    {
        $lines = [
            'worktree /srv/git/bare-repo',
            'HEAD abc1234',
            'bare',
            '',
        ];
        $entries = Worktrees::fromGitOutput($lines);
        $this->assertCount(1, $entries);
        $this->assertSame('/srv/git/bare-repo', $entries[0]->path);
        $this->assertSame('abc1234', $entries[0]->HEAD);
        $this->assertTrue($entries[0]->isBare);
    }

    public function testHandlesEmptyOutput(): void
    {
        $entries = Worktrees::fromGitOutput([]);
        $this->assertCount(0, $entries);
    }

    public function testHandlesNoTrailingBlankLine(): void
    {
        $lines = [
            'worktree /path/final',
            'HEAD fedcba9',
            'branch refs/heads/main',
        ];
        $entries = Worktrees::fromGitOutput($lines);
        $this->assertCount(1, $entries);
        $this->assertSame('/path/final', $entries[0]->path);
        $this->assertSame('main', $entries[0]->branch);
    }

    public function testRefsHeadsStrippedFromBranchName(): void
    {
        $lines = [
            'worktree /path/one',
            'HEAD abc123',
            'branch refs/heads/feature/sub-feature',
            '',
        ];
        $entries = Worktrees::fromGitOutput($lines);
        $this->assertSame('feature/sub-feature', $entries[0]->branch);
    }
}
