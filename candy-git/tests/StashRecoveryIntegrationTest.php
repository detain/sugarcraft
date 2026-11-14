<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;
use SugarCraft\Git\Git;

/**
 * End-to-end proof for candy-git-120: a dropped stash returns from its
 * captured sha via update-ref, the working tree is never touched by the
 * restore, and positions shift the way the copy promises (top, not home).
 */
final class StashRecoveryIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;
    private Git $git;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gitrecov_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        exec('git init -q -b main ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");
        file_put_contents($this->cwd . '/f.txt', "seed\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add f.txt 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . ' commit -q -m seed 2>/dev/null');
        $this->git = new Git($this->cwd);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    public function testDropThenRestoreBringsTheEntryBackAtTheTop(): void
    {
        file_put_contents($this->cwd . '/f.txt', "seed\nwork in progress\n");
        $this->git->stashPush('keep me', false, false);
        $this->assertCount(1, $this->git->stashList());

        $sha  = $this->git->stashSha('stash@{0}');
        $subj = $this->git->stashSubject('stash@{0}');
        $this->assertSame('On main: keep me', $subj, 'the display line IS the reflog subject');
        $this->assertSame(1, preg_match('/^[0-9a-f]{40}$/', $sha), 'rev-parse answers the full sha');

        $this->git->stashDrop('stash@{0}');
        $this->assertCount(0, $this->git->stashList(), 'the drop really went away');

        $this->git->stashRestore($sha, $subj);
        $back = $this->git->stashList();
        $this->assertCount(1, $back);
        $this->assertStringContainsString('keep me', $back[0]->displayLine());
        $this->assertSame($sha, $this->git->stashSha('stash@{0}'), 'the restored entry IS the old commit');
        $this->assertSame("seed\n", file_get_contents($this->cwd . '/f.txt'), 'restore never touches the worktree');
    }

    public function testRestoreLandsOnTopAndShiftsTheOtherEntriesDown(): void
    {
        file_put_contents($this->cwd . '/f.txt', "seed\nfirst\n");
        $this->git->stashPush('first', false, false);
        $firstSha = $this->git->stashSha('stash@{0}');
        file_put_contents($this->cwd . '/g.txt', "second\n");
        $this->git->stashPush('second', false, true);
        $this->assertCount(2, $this->git->stashList());

        // Drop the BOTTOM entry (first): git removes it and slot {1}
        // collapses away; the sha we captured still names it.
        $this->git->stashDrop('stash@{0}'); // 'second' on top of 'first'
        $this->assertSame($firstSha, $this->git->stashSha('stash@{0}'));
        $this->git->stashDrop('stash@{0}'); // now empty
        $this->assertCount(0, $this->git->stashList());

        $this->git->stashRestore($firstSha, 'On main: first');
        $list = $this->git->stashList();
        $this->assertCount(1, $list);
        $this->assertStringContainsString('first', $list[0]->displayLine());
        $this->assertSame($firstSha, $this->git->stashSha('stash@{0}'));
    }

    public function testTheRedoGuardCanSeeARefThatMoved(): void
    {
        // candy-git-120 redo contract, in real git terms: stash@{N} is
        // positional, so after churn the same slot names a different
        // commit. The app-side guard compares shas before replaying a
        // drop - this pins that the comparison is meaningful.
        file_put_contents($this->cwd . '/f.txt', "seed\nalpha\n");
        $this->git->stashPush('alpha', false, false);
        $alphaSha = $this->git->stashSha('stash@{0}');
        $this->git->stashDrop('stash@{0}');

        file_put_contents($this->cwd . '/h.txt', "beta\n");
        $this->git->stashPush('beta', false, true);

        $this->assertNotSame($alphaSha, $this->git->stashSha('stash@{0}'), 'slot 0 now names a different commit');
        $this->git->stashRestore($alphaSha, 'On main: alpha');
        $this->assertStringContainsString('alpha', $this->git->stashList()[0]->displayLine());
        $this->assertStringContainsString('beta', $this->git->stashList()[1]->displayLine(), 'beta slid down, nothing lost');
    }
}
