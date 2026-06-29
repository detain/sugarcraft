<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\WorktreeEntry;
use SugarCraft\Stash\Worktrees;
use PHPUnit\Framework\TestCase;

final class WorktreesTest extends TestCase
{
    public function testWorktreeEntryFromGitOutputParsesMultiLineRecords(): void
    {
        $lines = [
            'worktree /path/one',
            'HEAD abc1234',
            'branch refs/heads/main',
            '',
            'worktree /path/two',
            'HEAD def5678',
            'detached',
            '',
        ];
        $entries = WorktreeEntry::fromGitOutput($lines);
        $this->assertCount(2, $entries);
        $this->assertSame('/path/one', $entries[0]->path);
        $this->assertSame('main', $entries[0]->branch);
        $this->assertSame('abc1234', $entries[0]->HEAD);
        $this->assertFalse($entries[0]->isBare);
        $this->assertSame('/path/two', $entries[1]->path);
        $this->assertSame('', $entries[1]->branch);
        $this->assertSame('def5678', $entries[1]->HEAD);
        $this->assertFalse($entries[1]->isBare);
    }

    public function testWorktreeEntryFromGitOutputHandlesBare(): void
    {
        $lines = [
            'worktree /path/bare',
            'HEAD abc1234',
            'bare',
            '',
        ];
        $entries = WorktreeEntry::fromGitOutput($lines);
        $this->assertCount(1, $entries);
        $this->assertTrue($entries[0]->isBare);
    }

    public function testWorktreesFromGitOutputWithEmptyLines(): void
    {
        $worktrees = Worktrees::fromGitOutput([]);
        $this->assertCount(0, $worktrees);
    }

    public function testWithCursorNavigates(): void
    {
        $entries = [
            new WorktreeEntry('/path/one', 'main', false, 'abc1234'),
            new WorktreeEntry('/path/two', 'feature', false, 'def5678'),
        ];
        $wt = new Worktrees($entries, cursor: 0);

        $moved = $wt->withCursor(1);
        $this->assertSame(1, $moved->cursor);

        // Clamp at end
        $moved2 = $moved->withCursor(1);
        $this->assertSame(1, $moved2->cursor);

        // Clamp at beginning
        $moved3 = $wt->withCursor(-1);
        $this->assertSame(0, $moved3->cursor);
    }

    public function testStartAddingSetsFlag(): void
    {
        $wt = new Worktrees([]);
        $adding = $wt->startAdding();
        $this->assertTrue($adding->adding);
        $this->assertSame('', $adding->newPath);
        $this->assertSame('', $adding->newBranch);
    }

    public function testWithNewPathUpdatesPath(): void
    {
        $wt = new Worktrees([], adding: true);
        $updated = $wt->withNewPath('/new/path');
        $this->assertSame('/new/path', $updated->newPath);
    }

    public function testWithNewBranchUpdatesBranch(): void
    {
        $wt = new Worktrees([], adding: true);
        $updated = $wt->withNewBranch('feature');
        $this->assertSame('feature', $updated->newBranch);
    }

    public function testCancelAddingResets(): void
    {
        $wt = new Worktrees([], adding: true, newPath: '/path', newBranch: 'main');
        $cancelled = $wt->cancelAdding();
        $this->assertFalse($cancelled->adding);
        $this->assertSame('', $cancelled->newPath);
        $this->assertSame('', $cancelled->newBranch);
    }

    public function testStartRemovingSetsFlag(): void
    {
        $wt = new Worktrees([]);
        $removing = $wt->startRemoving();
        $this->assertTrue($removing->removing);
    }

    public function testCancelRemovingResets(): void
    {
        $wt = new Worktrees([], removing: true);
        $cancelled = $wt->cancelRemoving();
        $this->assertFalse($cancelled->removing);
    }

    public function testCurrentReturnsSelectedEntry(): void
    {
        $entries = [
            new WorktreeEntry('/path/one', 'main', false, 'abc1234'),
            new WorktreeEntry('/path/two', 'feature', false, 'def5678'),
        ];
        $wt = new Worktrees($entries, cursor: 0);
        $this->assertSame($entries[0], $wt->current());

        $wt2 = $wt->withCursor(1);
        $this->assertSame($entries[1], $wt2->current());
    }

    public function testEmptyWorktreesReturnsNullCurrent(): void
    {
        $wt = new Worktrees([]);
        $this->assertNull($wt->current());
        $this->assertSame(0, $wt->count());
    }
}
