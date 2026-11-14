<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-`git` coverage for the merge sequencer legs (candy-git-012).
 *
 * A conflicted merge started by the app used to leave MERGE_HEAD with no
 * in-app exit; these legs prove the two the sequencer menu now drives:
 * `mergeAbort()` restores the pre-merge state, and `mergeContinue()` is the
 * plain merge commit (git 2.43 refuses arguments on `merge --continue` — the
 * continue leg is `commit --no-edit` reusing MERGE_MSG, so an integration
 * double on argv would prove nothing).
 */
final class MergeSequencerIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;
    private Git $git;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gitmerge_' . uniqid();
        mkdir($this->cwd, 0o777, true);
        exec('git init -q ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");
        file_put_contents($this->cwd . '/f.txt', "one\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add -A 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " commit -qm seed 2>/dev/null");

        // Diverge both sides of f.txt so merging topic into master conflicts.
        exec('git -C ' . escapeshellarg($this->cwd) . ' checkout -qb topic 2>/dev/null');
        file_put_contents($this->cwd . '/f.txt', "topic-change\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' commit -qam topic 2>/dev/null');
        // The seeded default branch is master on the fleet runner (same
        // premise as the remote-trio suite).
        exec('git -C ' . escapeshellarg($this->cwd) . ' checkout -q master 2>/dev/null');
        file_put_contents($this->cwd . '/f.txt', "master-change\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' commit -qam master-side 2>/dev/null');

        $this->git = new Git($this->cwd);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    /** Drive the app's own merge into the conflicted stop; returns pre-merge HEAD. */
    private function conflictedMerge(): string
    {
        $pre = trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' rev-parse HEAD'));
        try {
            $this->git->merge('topic');
            $this->fail('the seeded branches were made to diverge; merge must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('CONFLICT', $e->getMessage());
        }
        return $pre;
    }

    public function testConflictedMergeIsRecognisedAsAMergeStop(): void
    {
        $this->conflictedMerge();
        $this->assertSame('merge', $this->git->sequenceState());
    }

    public function testMergeContinueCommitsTheRecordedResolution(): void
    {
        $pre = $this->conflictedMerge();
        file_put_contents($this->cwd . '/f.txt', "resolved\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add f.txt 2>/dev/null');

        $this->git->mergeContinue();

        $state = trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' rev-list --parents -n1 HEAD'));
        $parents = preg_split('/\s+/', $state) ?: [];
        $this->assertCount(3, $parents, 'HEAD must be a merge commit with two parents');
        $this->assertSame($pre, $parents[1]);
        $this->assertSame('', $this->git->sequenceState(), 'continue consumes the stop');
        $this->assertSame("resolved\n", file_get_contents($this->cwd . '/f.txt'));
    }

    public function testMergeContinueBeforeResolutionIsRefused(): void
    {
        $this->conflictedMerge();
        try {
            $this->git->mergeContinue();
            $this->fail('git refuses to commit with unmerged paths');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unmerged', $e->getMessage());
        }
        $this->assertSame('merge', $this->git->sequenceState(), 'the refusal keeps the stop for retry');
    }

    public function testMergeAbortRestoresThePreMergeState(): void
    {
        $pre = $this->conflictedMerge();

        $this->git->mergeAbort();

        $this->assertSame('', $this->git->sequenceState());
        $this->assertSame($pre, trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' rev-parse HEAD')));
        $this->assertSame("master-change\n", file_get_contents($this->cwd . '/f.txt'), 'the worktree comes back');
    }

    public function testMergeAbortOutsideAStoppedMergeRefuses(): void
    {
        try {
            $this->git->mergeAbort();
            $this->fail('git exits nonzero when there is no merge to abort');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no merge to abort', $e->getMessage());
        }
    }
}
