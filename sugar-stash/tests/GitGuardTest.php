<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\Git;
use SugarCraft\Stash\GitDriver;
use SugarCraft\Stash\Tests\Concerns\RecursiveDirCleanup;
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
        exec("git init -b main {$this->cwd} 2>/dev/null");
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cwd);
    }

    public function testCheckoutRejectsLeadingDashBranch(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->checkout('-f');
    }

    public function testMergeRejectsLeadingDashBranch(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->merge('--force');
    }

    public function testCreateBranchRejectsLeadingDashName(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->createBranch('-evil');
    }

    public function testCherryPickRejectsLeadingDashRef(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->cherryPick('--abort');
    }

    public function testStashApplyRejectsLeadingDashRef(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->stashApply('--invalid');
    }

    public function testWorktreeAddRejectsLeadingDashPath(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->worktreeAdd('-/fake/path', 'main');
    }

    public function testWorktreeAddRejectsLeadingDashBranch(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->worktreeAdd('/fake/path', '-evil');
    }

    public function testWorktreeRemoveRejectsLeadingDashPath(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->worktreeRemove('-fake');
    }

    public function testCheckoutAcceptsNormalBranch(): void
    {
        $git = new Git($this->cwd);
        $git->createBranch('testbranch');
        $this->assertTrue(true);
    }

    public function testMergeAcceptsNormalBranch(): void
    {
        // Inline identity: CI runners have no global user.name/user.email.
        exec("git -C {$this->cwd} -c user.email=test@example.com -c user.name=Test commit --allow-empty -m 'first' 2>/dev/null");
        exec("git -C {$this->cwd} branch feature 2>/dev/null");
        exec("git -C {$this->cwd} checkout main 2>/dev/null");
        $git = new Git($this->cwd);
        $git->merge('feature');
        $this->assertTrue(true);
    }

    public function testWorktreeAddRejectsDotDotPath(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->worktreeAdd('../evil', 'main');
    }

    public function testWorktreeAddRejectsEmbeddedTraversal(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->worktreeAdd('foo/../../etc', 'main');
    }

    public function testWorktreeAddRejectsBackslashTraversal(): void
    {
        $git = new Git($this->cwd);
        $this->expectException(\InvalidArgumentException::class);
        $git->worktreeAdd('foo\\..\\bar', 'main');
    }

    public function testWorktreeAddResolvesLegitRelativePath(): void
    {
        // A legit relative path is anchored to the realpath'd repo root and the
        // worktree is actually created there — proving the guard resolves rather
        // than rejects safe input.
        exec("git -C {$this->cwd} -c user.email=test@example.com -c user.name=Test commit --allow-empty -m 'first' 2>/dev/null");
        exec("git -C {$this->cwd} branch feature 2>/dev/null");
        $git = new Git($this->cwd);
        $git->worktreeAdd('wt-sub', 'feature');
        $this->assertDirectoryExists(realpath($this->cwd) . '/wt-sub');
    }
}
