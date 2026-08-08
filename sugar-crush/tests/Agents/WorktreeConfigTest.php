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
            isolationMode: WorktreeIsolationMode::Worktree,
        );

        $this->assertSame('/tmp/worktrees/', $config->basePath);
        $this->assertFalse($config->autoCleanup);
        $this->assertSame(WorktreeIsolationMode::Worktree, $config->isolationMode);
    }

    // -------------------------------------------------------------------------
    // isolationMode guard — Branch/Path are defined on the enum but have no
    // WorktreeManager implementation; setting either must fail loudly instead
    // of being silently ignored (the original bug: any mode was accepted but
    // WorktreeManager::createWorktree() only ever did full-worktree behavior).
    // -------------------------------------------------------------------------

    public function testConstructorThrowsForBranchIsolationMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WorktreeIsolationMode::Branch is not implemented');

        new WorktreeConfig(isolationMode: WorktreeIsolationMode::Branch);
    }

    public function testConstructorThrowsForPathIsolationMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WorktreeIsolationMode::Path is not implemented');

        new WorktreeConfig(isolationMode: WorktreeIsolationMode::Path);
    }

    public function testConstructorAllowsWorktreeIsolationMode(): void
    {
        // Sanity check: the one implemented mode must never trigger the guard.
        $config = new WorktreeConfig(isolationMode: WorktreeIsolationMode::Worktree);
        $this->assertSame(WorktreeIsolationMode::Worktree, $config->isolationMode);
    }

    public function testNewFactoryThrowsForUnimplementedIsolationMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WorktreeConfig::new(isolationMode: WorktreeIsolationMode::Branch);
    }

    // -------------------------------------------------------------------------
    // ::new() factory — config file loading
    // -------------------------------------------------------------------------

    public function testNewLoadsConfigFromFile(): void
    {
        // The ::new() factory resolves config.json relative to the repo root
        // using __DIR__ . '/../../../.sugar-crush/config.json' from src/Agents/.
        // This test writes a temp config to that exact path, calls ::new(),
        // then restores the original so other tests are unaffected.
        $configPath = __DIR__ . '/../../../.sugar-crush/config.json';
        $backup = file_exists($configPath) ? file_get_contents($configPath) : null;

        try {
            file_put_contents(
                $configPath,
                json_encode([
                    'worktreeCleanupPeriodDays' => 21,
                    'worktreeIncludeFile' => '.test-worktreeinclude',
                ]),
            );

            $config = WorktreeConfig::new();

            $this->assertSame(21, $config->worktreeCleanupPeriodDays);
            $this->assertSame('.test-worktreeinclude', $config->worktreeIncludeFile);
        } finally {
            if ($backup !== null) {
                file_put_contents($configPath, $backup);
            } else {
                unlink($configPath);
            }
        }
    }

    public function testConstructorWorktreeCleanupPeriodDays(): void
    {
        $config = new WorktreeConfig(worktreeCleanupPeriodDays: 14);
        $this->assertSame(14, $config->worktreeCleanupPeriodDays);
    }

    public function testConstructorWorktreeIncludeFile(): void
    {
        $config = new WorktreeConfig(worktreeIncludeFile: '.custom-worktreeinclude');
        $this->assertSame('.custom-worktreeinclude', $config->worktreeIncludeFile);
    }

    public function testConstructorBothCleanupPeriodAndIncludeFile(): void
    {
        $config = new WorktreeConfig(
            worktreeCleanupPeriodDays: 30,
            worktreeIncludeFile: '.my-worktreeinclude',
        );

        $this->assertSame(30, $config->worktreeCleanupPeriodDays);
        $this->assertSame('.my-worktreeinclude', $config->worktreeIncludeFile);
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
            isolationMode: WorktreeIsolationMode::Worktree,
        );
        $modified = $original->withBasePath('/new/path/');

        $this->assertSame('.sugar-crush/worktrees/', $original->basePath);
        $this->assertSame('/new/path/', $modified->basePath);
        $this->assertFalse($modified->autoCleanup);
        $this->assertSame(WorktreeIsolationMode::Worktree, $modified->isolationMode);
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
            isolationMode: WorktreeIsolationMode::Worktree,
        );
        $modified = $original->withAutoCleanup(false);

        $this->assertTrue($original->autoCleanup);
        $this->assertFalse($modified->autoCleanup);
        $this->assertSame('/custom/', $modified->basePath);
        $this->assertSame(WorktreeIsolationMode::Worktree, $modified->isolationMode);
    }

    // -------------------------------------------------------------------------
    // withIsolationMode()
    // -------------------------------------------------------------------------

    public function testWithIsolationModeThrowsForBranch(): void
    {
        $original = new WorktreeConfig(isolationMode: WorktreeIsolationMode::Worktree);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WorktreeIsolationMode::Branch is not implemented');

        $original->withIsolationMode(WorktreeIsolationMode::Branch);
    }

    public function testWithIsolationModeThrowsForPath(): void
    {
        $original = new WorktreeConfig(isolationMode: WorktreeIsolationMode::Worktree);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WorktreeIsolationMode::Path is not implemented');

        $original->withIsolationMode(WorktreeIsolationMode::Path);
    }

    public function testWithIsolationModeThrowsLeavesOriginalUnchanged(): void
    {
        $original = new WorktreeConfig(isolationMode: WorktreeIsolationMode::Worktree);

        try {
            $original->withIsolationMode(WorktreeIsolationMode::Branch);
            $this->fail('Expected withIsolationMode(Branch) to throw.');
        } catch (\InvalidArgumentException) {
            // expected — fall through to verify $original was untouched
        }

        $this->assertSame(WorktreeIsolationMode::Worktree, $original->isolationMode);
    }

    public function testWithIsolationModeWorktree(): void
    {
        $original = new WorktreeConfig(isolationMode: WorktreeIsolationMode::Worktree);
        $modified = $original->withIsolationMode(WorktreeIsolationMode::Worktree);

        $this->assertSame(WorktreeIsolationMode::Worktree, $original->isolationMode);
        $this->assertSame(WorktreeIsolationMode::Worktree, $modified->isolationMode);
        $this->assertNotSame($original, $modified, 'withIsolationMode() must still return a new instance');
    }

    public function testWithIsolationModePreservesOtherFields(): void
    {
        $original = new WorktreeConfig(
            basePath: '/worktrees/',
            autoCleanup: false,
            isolationMode: WorktreeIsolationMode::Worktree,
        );
        $modified = $original->withIsolationMode(WorktreeIsolationMode::Worktree);

        $this->assertSame(WorktreeIsolationMode::Worktree, $modified->isolationMode);
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
        $this->assertNotSame($original, $original->withIsolationMode(WorktreeIsolationMode::Worktree));
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
