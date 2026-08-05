<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\WorktreeConfig;
use SugarCraft\Crush\Agents\WorktreeIsolationMode;

/**
 * Tests for WorktreeConfig - worktree isolation configuration.
 */
final class WorktreeConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Default values
    // -------------------------------------------------------------------------

    public function testDefaultBasePath(): void
    {
        $config = new WorktreeConfig();
        $this->assertSame('.sugar-crush/worktrees/', $config->basePath);
    }

    public function testDefaultAutoCleanup(): void
    {
        $config = new WorktreeConfig();
        $this->assertTrue($config->autoCleanup);
    }

    public function testDefaultIsolationMode(): void
    {
        $config = new WorktreeConfig();
        $this->assertSame(WorktreeIsolationMode::Worktree, $config->isolationMode);
    }

    // -------------------------------------------------------------------------
    // Custom values via constructor
    // -------------------------------------------------------------------------

    public function testCustomValues(): void
    {
        $config = new WorktreeConfig(
            basePath: '/tmp/worktrees/',
            autoCleanup: false,
            isolationMode: WorktreeIsolationMode::Branch,
        );

        $this->assertSame('/tmp/worktrees/', $config->basePath);
        $this->assertFalse($config->autoCleanup);
        $this->assertSame(WorktreeIsolationMode::Branch, $config->isolationMode);
    }

    public function testCustomIsolationModePath(): void
    {
        $config = new WorktreeConfig(isolationMode: WorktreeIsolationMode::Path);
        $this->assertSame(WorktreeIsolationMode::Path, $config->isolationMode);
    }

    // -------------------------------------------------------------------------
    // withBasePath()
    // -------------------------------------------------------------------------

    public function testWithBasePath(): void
    {
        $original = new WorktreeConfig(basePath: '.sugar-crush/worktrees/');
        $modified = $original->withBasePath('/var/worktrees/');

        $this->assertSame('.sugar-crush/worktrees/', $original->basePath);
        $this->assertSame('/var/worktrees/', $modified->basePath);
    }

    public function testWithBasePathPreservesOtherFields(): void
    {
        $original = new WorktreeConfig(
            basePath: '.sugar-crush/worktrees/',
            autoCleanup: false,
            isolationMode: WorktreeIsolationMode::Branch,
        );
        $modified = $original->withBasePath('/new/path/');

        $this->assertSame('.sugar-crush/worktrees/', $original->basePath);
        $this->assertSame('/new/path/', $modified->basePath);
        $this->assertFalse($modified->autoCleanup);
        $this->assertSame(WorktreeIsolationMode::Branch, $modified->isolationMode);
    }

    // -------------------------------------------------------------------------
    // withAutoCleanup()
    // -------------------------------------------------------------------------

    public function testWithAutoCleanupTrue(): void
    {
        $original = new WorktreeConfig(autoCleanup: false);
        $modified = $original->withAutoCleanup(true);

        $this->assertFalse($original->autoCleanup);
        $this->assertTrue($modified->autoCleanup);
    }

    public function testWithAutoCleanupFalse(): void
    {
        $original = new WorktreeConfig(autoCleanup: true);
        $modified = $original->withAutoCleanup(false);

        $this->assertTrue($original->autoCleanup);
        $this->assertFalse($modified->autoCleanup);
    }

    public function testWithAutoCleanupPreservesOtherFields(): void
    {
        $original = new WorktreeConfig(
            basePath: '/custom/',
            autoCleanup: true,
            isolationMode: WorktreeIsolationMode::Path,
        );
        $modified = $original->withAutoCleanup(false);

        $this->assertTrue($original->autoCleanup);
        $this->assertFalse($modified->autoCleanup);
        $this->assertSame('/custom/', $modified->basePath);
        $this->assertSame(WorktreeIsolationMode::Path, $modified->isolationMode);
    }

    // -------------------------------------------------------------------------
    // withIsolationMode()
    // -------------------------------------------------------------------------

    public function testWithIsolationModeBranch(): void
    {
        $original = new WorktreeConfig(isolationMode: WorktreeIsolationMode::Worktree);
        $modified = $original->withIsolationMode(WorktreeIsolationMode::Branch);

        $this->assertSame(WorktreeIsolationMode::Worktree, $original->isolationMode);
        $this->assertSame(WorktreeIsolationMode::Branch, $modified->isolationMode);
    }

    public function testWithIsolationModePath(): void
    {
        $original = new WorktreeConfig(isolationMode: WorktreeIsolationMode::Worktree);
        $modified = $original->withIsolationMode(WorktreeIsolationMode::Path);

        $this->assertSame(WorktreeIsolationMode::Worktree, $original->isolationMode);
        $this->assertSame(WorktreeIsolationMode::Path, $modified->isolationMode);
    }

    public function testWithIsolationModeWorktree(): void
    {
        $original = new WorktreeConfig(isolationMode: WorktreeIsolationMode::Branch);
        $modified = $original->withIsolationMode(WorktreeIsolationMode::Worktree);

        $this->assertSame(WorktreeIsolationMode::Branch, $original->isolationMode);
        $this->assertSame(WorktreeIsolationMode::Worktree, $modified->isolationMode);
    }

    public function testWithIsolationModePreservesOtherFields(): void
    {
        $original = new WorktreeConfig(
            basePath: '/worktrees/',
            autoCleanup: false,
            isolationMode: WorktreeIsolationMode::Worktree,
        );
        $modified = $original->withIsolationMode(WorktreeIsolationMode::Path);

        $this->assertSame(WorktreeIsolationMode::Worktree, $original->isolationMode);
        $this->assertSame(WorktreeIsolationMode::Path, $modified->isolationMode);
        $this->assertSame('/worktrees/', $modified->basePath);
        $this->assertFalse($modified->autoCleanup);
    }

    // -------------------------------------------------------------------------
    // Immutability - with*() returns new instance
    // -------------------------------------------------------------------------

    public function testWithMethodsReturnNewInstance(): void
    {
        $original = new WorktreeConfig();

        $this->assertNotSame($original, $original->withBasePath('/new/'));
        $this->assertNotSame($original, $original->withAutoCleanup(false));
        $this->assertNotSame($original, $original->withIsolationMode(WorktreeIsolationMode::Branch));
    }

    // -------------------------------------------------------------------------
    // Empty string values
    // -------------------------------------------------------------------------

    public function testEmptyBasePath(): void
    {
        $config = new WorktreeConfig(basePath: '');
        $this->assertSame('', $config->basePath);
    }

    // -------------------------------------------------------------------------
    // Boundary values
    // -------------------------------------------------------------------------

    public function testLargeBasePath(): void
    {
        $longPath = str_repeat('/a', 100);
        $config = new WorktreeConfig(basePath: $longPath);
        $this->assertSame($longPath, $config->basePath);
    }
}
