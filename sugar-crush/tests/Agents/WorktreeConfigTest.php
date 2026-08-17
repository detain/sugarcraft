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

    /**
     * THE FILE THIS TEST USED TO WRITE was `<repo>/.sugar-crush/config.json`,
     * which `git ls-files` confirms is TRACKED. It backed the bytes up and
     * restored them in a `finally`, so an interrupted or killed run left a
     * tracked repository file mutated — and because the monorepo's own copy sets
     * `worktreeCleanupPeriodDays: 7`, the test also failed with "7 is identical
     * to 21" in every sandbox that did not carry that file. Three separate
     * sessions diagnosed that failure and one reviewer declined to run the suite
     * in-repo because of it.
     *
     * The fix is the one #90(a) got: the location is an injectable seam
     * ({@see WorktreeConfig::new()}'s `$configDir`), the production default is
     * untouched, and the test drives a temporary tree OUTSIDE the checkout.
     */
    public function testNewLoadsConfigFromFile(): void
    {
        $dir = $this->temporaryTree([
            'worktreeCleanupPeriodDays' => 21,
            'worktreeIncludeFile' => '.test-worktreeinclude',
        ]);

        $config = WorktreeConfig::new(configDir: $dir);

        $this->assertSame(21, $config->worktreeCleanupPeriodDays);
        $this->assertSame('.test-worktreeinclude', $config->worktreeIncludeFile);
    }

    /**
     * The production default is still the directory containing the package —
     * pinned so making the path injectable cannot quietly become making it
     * unset.
     */
    public function testTheProductionDefaultIsStillThePackagesOwnParentDirectory(): void
    {
        $this->assertSame(
            realpath(\dirname(__DIR__, 3)),
            realpath(WorktreeConfig::defaultConfigDir()),
        );
    }

    /**
     * NOT A TIDINESS RULE. `worktreeIncludeFile` names a file whose every line
     * {@see \SugarCraft\Crush\Agents\WorktreeManager} turns into a copy pattern,
     * so a committed `.sugar-crush -> /elsewhere` used to choose that value from
     * outside the tree entirely. The directory must resolve STRICTLY inside the
     * tree it was reached from.
     */
    public function testAConfigDirectorySymlinkedOutOfTheTreeIsRefused(): void
    {
        $outside = $this->temporaryTree(['worktreeCleanupPeriodDays' => 21]);
        $tree = $this->makeTempDir();
        symlink($outside . '/.sugar-crush', $tree . '/.sugar-crush');

        $config = WorktreeConfig::new(configDir: $tree);

        $this->assertSame(7, $config->worktreeCleanupPeriodDays, 'the default, not the outside file\'s value');
    }

    /**
     * The second boundary, which the first cannot stand in for: the directory
     * is where it should be and `config.json` INSIDE it is the symlink out.
     */
    public function testAConfigFileSymlinkedOutOfTheDirectoryIsRefused(): void
    {
        $outside = $this->temporaryTree(['worktreeIncludeFile' => '.attacker-chosen']);
        $tree = $this->makeTempDir();
        mkdir($tree . '/.sugar-crush', 0o700, true);
        symlink($outside . '/.sugar-crush/config.json', $tree . '/.sugar-crush/config.json');

        $config = WorktreeConfig::new(configDir: $tree);

        $this->assertSame('.worktreeinclude', $config->worktreeIncludeFile);
    }

    /** No config file at all is the defaults, not a throw. */
    public function testAnAbsentConfigFileLeavesTheDefaultsInPlace(): void
    {
        $config = WorktreeConfig::new(configDir: $this->makeTempDir());

        $this->assertSame(7, $config->worktreeCleanupPeriodDays);
        $this->assertSame('.worktreeinclude', $config->worktreeIncludeFile);
    }

    /** Malformed JSON is the defaults too — the file is optional either way. */
    public function testMalformedJsonLeavesTheDefaultsInPlace(): void
    {
        $tree = $this->makeTempDir();
        mkdir($tree . '/.sugar-crush', 0o700, true);
        file_put_contents($tree . '/.sugar-crush/config.json', '{not json');

        $config = WorktreeConfig::new(configDir: $tree);

        $this->assertSame(7, $config->worktreeCleanupPeriodDays);
    }

    /** Explicit arguments still win over the file's values. */
    public function testAnExplicitArgumentOverridesTheFile(): void
    {
        $dir = $this->temporaryTree(['worktreeCleanupPeriodDays' => 21]);

        $this->assertSame(
            3,
            WorktreeConfig::new(worktreeCleanupPeriodDays: 3, configDir: $dir)->worktreeCleanupPeriodDays,
        );
    }

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeTree($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /** A directory OUTSIDE the checkout, removed in tearDown. */
    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/sugarcrush_worktree_config_' . uniqid('', true);
        mkdir($dir, 0o700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * A temp directory holding `.sugar-crush/config.json` with $values.
     *
     * @param array<string, mixed> $values
     */
    private function temporaryTree(array $values): string
    {
        $dir = $this->makeTempDir();
        mkdir($dir . '/.sugar-crush', 0o700, true);
        file_put_contents($dir . '/.sugar-crush/config.json', (string) json_encode($values));

        return $dir;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Links are unlinked, never descended into: these fixtures deliberately
        // contain a symlink to another fixture, and following it would delete
        // the sibling's contents through it.
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_string($entry)) {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                @unlink($path);

                continue;
            }

            $this->removeTree($path);
        }

        @rmdir($dir);
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
