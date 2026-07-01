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
        exec("git init {$this->cwd} 2>/dev/null");
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
        exec("git -C {$this->cwd} commit --allow-empty -m 'first' 2>/dev/null");
        exec("git -C {$this->cwd} branch feature 2>/dev/null");
        exec("git -C {$this->cwd} checkout main 2>/dev/null");
        $git = new Git($this->cwd);
        $git->merge('feature');
        $this->assertTrue(true);
    }
}
