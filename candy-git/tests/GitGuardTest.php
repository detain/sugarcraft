<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Git\Git;
use SugarCraft\Git\GitDriver;
use SugarCraft\Git\Platform;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;
use PHPUnit\Framework\TestCase;

final class GitGuardTest extends TestCase
{
    use RecursiveDirCleanup;
    private string $cwd;

    protected function setUp(): void
    {
        $this->cwd = sys_get_temp_dir() . '/gitguard_' . uniqid();
        mkdir($this->cwd);
        // Hermetic: pin the initial branch (runners may default to main OR
        // master) so branch-name-dependent tests are deterministic.
        exec('git init -b main ' . escapeshellarg($this->cwd) . '' . self::nullSink());
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    public function testCheckoutRejectsLeadingDashBranch(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->checkout('-f');
    }

    /** Host-native stderr sink for fixture exec lines: ' 2>/dev/null' / ' 2>NUL' (F12b). */
    private static function nullSink(): string
    {
        return ' 2>' . Platform::new()->nullDevice();
    }

    public function testMergeRejectsLeadingDashBranch(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->merge('--force');
    }

    public function testCreateBranchRejectsLeadingDashName(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->createBranch('-evil');
    }

    public function testDeleteBranchRejectsLeadingDashName(): void
    {
        // A9: deleteBranch was the one ref-taking method that skipped the
        // guard, so 'git branch -d -sneaky' reached git as flag soup.
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->deleteBranch('-d');
    }

    public function testGuardRefusalTravelsTheSameLaneAsGitFailures(): void
    {
        // K4 structural claim: guard refusals must be catchable by exactly
        // the handler the App uses for `git` failures — a probe through
        // RuntimeException proves no foreign exception type escapes.
        $git = new Git($this->cwd);
        try {
            $git->checkout('-f');
            $this->fail('expected a RuntimeException refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("cannot start with '-'", $e->getMessage());
        }
    }

    public function testCherryPickRejectsLeadingDashRef(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->cherryPick('--abort');
    }

    public function testStashApplyRejectsLeadingDashRef(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->stashApply('--invalid');
    }

    /** CG-18: the details surface refuses dash refs before git ever sees them. */
    public function testCommitDetailsRejectsLeadingDashRef(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->commitDetails('--invalid');
    }

    public function testRevertRejectsLeadingDashRef(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->revert('--invalid');
    }

    public function testResetHardRejectsLeadingDashRef(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->resetHard('--invalid');
    }

    /** CG-16: pop composes apply/drop, and apply's guard runs before any argv. */
    public function testStashPopRejectsLeadingDashRef(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->stashPop('--drop');
    }

    // -- candy-git-035: the untracked file lanes keep the same refusals ----

    public function testUntrackedDiffRejectsEscapingAndAbsolutePaths(): void
    {
        $git = new Git($this->cwd);
        foreach (['../outside.txt', '/etc/passwd', ''] as $bad) {
            try {
                $git->untrackedDiff($bad);
                $this->fail('rejected nothing for ' . var_export($bad, true));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('unsafe', $e->getMessage());
            }
        }
    }

    public function testDeleteUntrackedRejectsEscapingAndAbsolutePaths(): void
    {
        $git = new Git($this->cwd);
        foreach (['../victim.txt', '/etc/hostname', ''] as $bad) {
            try {
                $git->deleteUntracked($bad);
                $this->fail('deleted nothing for ' . var_export($bad, true));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('unsafe', $e->getMessage());
            }
        }
    }

    public function testUntrackedDiffShapesMatchGitAndHonourTheExecutableBit(): void
    {
        file_put_contents($this->cwd . '/plain.txt', "aaa\nbbb");
        $git   = new Git($this->cwd);
        $lines = $git->untrackedDiff('plain.txt');
        $this->assertContains('new file mode 100644', $lines);
        $this->assertContains('@@ -0,0 +1,2 @@', $lines);
        $this->assertContains('+aaa', $lines);
        $this->assertContains('+bbb', $lines);
        $this->assertContains('\\ No newline at end of file', $lines);

        chmod($this->cwd . '/plain.txt', 0o755);
        $this->assertContains('new file mode 100755', $git->untrackedDiff('plain.txt'));
    }

    public function testUntrackedDiffOfAMissingFileThrows(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->untrackedDiff('gone.txt');
    }

    public function testWorktreeAddRejectsLeadingDashPath(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->worktreeAdd('-/fake/path', 'main');
    }

    public function testWorktreeAddRejectsLeadingDashBranch(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->worktreeAdd('/fake/path', '-evil');
    }

    public function testWorktreeRemoveRejectsLeadingDashPath(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->worktreeRemove('-fake');
    }

    /**
     * Real e2e (audit #1): createBranch leaves HEAD on `testbranch` (it runs
     * `checkout -b`), so a working `checkout('main')` is observable as the HEAD
     * branch name flipping back. The pre-fix `checkout -- main` parsed `main`
     * as a pathspec and threw — this pin goes red on any reintroduction.
     */
    public function testCheckoutAcceptsNormalBranch(): void
    {
        $repo = escapeshellarg($this->cwd);
        exec('git -C ' . $repo . " -c user.email=test@example.com -c user.name=Test commit --allow-empty -m 'first'" . self::nullSink());
        $git = new Git($this->cwd);
        $git->createBranch('testbranch');
        $this->assertSame('testbranch', trim((string) shell_exec('git -C ' . $repo . ' rev-parse --abbrev-ref HEAD')));

        $git->checkout('main');
        $this->assertSame('main', trim((string) shell_exec('git -C ' . $repo . ' rev-parse --abbrev-ref HEAD')));

        $git->checkout('testbranch');
        $this->assertSame('testbranch', trim((string) shell_exec('git -C ' . $repo . ' rev-parse --abbrev-ref HEAD')));
    }

    public function testMergeAcceptsNormalBranch(): void
    {
        // Inline identity: CI runners have no global user.name/user.email.
        exec('git -C ' . escapeshellarg($this->cwd) . " -c user.email=test@example.com -c user.name=Test commit --allow-empty -m 'first'" . self::nullSink());
        exec('git -C ' . escapeshellarg($this->cwd) . ' branch feature' . self::nullSink());
        exec('git -C ' . escapeshellarg($this->cwd) . ' checkout main' . self::nullSink());
        $git = new Git($this->cwd);
        $git->merge('feature');
        $this->assertTrue(true);
    }

    public function testWorktreeAddRejectsDotDotPath(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->worktreeAdd('../evil', 'main');
    }

    public function testWorktreeAddRejectsEmbeddedTraversal(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->worktreeAdd('foo/../../etc', 'main');
    }

    public function testWorktreeAddRejectsBackslashTraversal(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->worktreeAdd('foo\\..\\bar', 'main');
    }

    public function testWorktreeAddResolvesLegitRelativePath(): void
    {
        // A legit relative path is anchored to the realpath'd repo root and the
        // worktree is actually created there — proving the guard resolves rather
        // than rejects safe input. CG-24: the branch is now CREATED (-b), so the
        // call names a fresh branch instead of pre-existing feature.
        exec('git -C ' . escapeshellarg($this->cwd) . " -c user.email=test@example.com -c user.name=Test commit --allow-empty -m 'first'" . self::nullSink());
        $git = new Git($this->cwd);
        $git->worktreeAdd('wt-sub', 'wtbranch');
        $this->assertDirectoryExists(realpath($this->cwd) . '/wt-sub');
        $heads = trim((string) shell_exec('git -C ' . escapeshellarg(realpath($this->cwd) . '/wt-sub') . ' rev-parse --abbrev-ref HEAD'));
        $this->assertSame('wtbranch', $heads, 'the new worktree sits on the created branch, not detached HEAD');
    }

    public function testWorktreeAddWithoutABranchNameUsesTheGitBasenameDefault(): void
    {
        // CG-24 empty-branch leg: git names the new branch from the path
        // basename - a real branch, where the retired 'HEAD' pass-through
        // produced a detached checkout.
        exec('git -C ' . escapeshellarg($this->cwd) . " -c user.email=test@example.com -c user.name=Test commit --allow-empty -m 'first'" . self::nullSink());
        $git = new Git($this->cwd);
        $git->worktreeAdd('wt-auto', '');
        $head = trim((string) shell_exec('git -C ' . escapeshellarg(realpath($this->cwd) . '/wt-auto') . ' rev-parse --abbrev-ref HEAD'));
        $this->assertSame('wt-auto', $head, 'the worktree sits on a basename-named branch, not detached HEAD');
    }

    public function testWorktreeAddRefusesToShadowAnExistingBranch(): void
    {
        // CG-24: the branch is created with -b, so a name that already exists
        // fails loudly in git instead of silently sharing a checked-out branch.
        exec('git -C ' . escapeshellarg($this->cwd) . " -c user.email=test@example.com -c user.name=Test commit --allow-empty -m 'first'" . self::nullSink());
        exec('git -C ' . escapeshellarg($this->cwd) . ' branch taken' . self::nullSink());
        $git = new Git($this->cwd);
        $this->expectException(\RuntimeException::class);
        $git->worktreeAdd('wt-shadow', 'taken');
    }
}
