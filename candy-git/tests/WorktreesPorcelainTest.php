<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Git\WorktreeEntry;
use SugarCraft\Git\Worktrees;
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
        $this->assertFalse($entries[0]->detached);
        $this->assertSame('/home/user/project-feature', $entries[1]->path);
        $this->assertSame('feature/widget', $entries[1]->branch);
        $this->assertFalse($entries[1]->detached);
        $this->assertSame('/home/user/project-detached', $entries[2]->path);
        $this->assertSame('', $entries[2]->branch);
        $this->assertTrue($entries[2]->detached, 'the detached record surfaces its state where the dead HEAD column used to be parsed (candy-git-082)');
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
        $this->assertSame('', $entries[0]->branch);
        $this->assertFalse($entries[0]->detached, 'bare is parsed-and-discarded: the row must not choke the parser (candy-git-082)');
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
