<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;

final class InstructionFileLoaderTest extends TestCase
{
    private string $tmpDir;
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/sugar_crush_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
        $this->repoRoot = $this->tmpDir . '/repo';
        mkdir($this->repoRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testLoadForPathReturnsContentForNestedFile(): void
    {
        // Create a lib directory with a CLAUDE.md inside it
        $libDir = $this->repoRoot . '/sugar-crush';
        mkdir($libDir, 0777, true);
        $nestedFile = $libDir . '/CLAUDE.md';
        $nestedContent = "# Sugar-Crush instructions\n\nDo the thing.";
        file_put_contents($nestedFile, $nestedContent);

        $loader = new InstructionFileLoader($this->repoRoot);
        $sessionCache = [];

        // Touch a file inside the lib
        $result = $loader->loadForPath($libDir . '/src/SomeFile.php', $sessionCache);

        $this->assertSame($nestedContent, $result);
    }

    public function testLoadForPathReturnsNullWhenNoNestedFile(): void
    {
        // Create a lib directory WITHOUT a CLAUDE.md or AGENTS.md
        $libDir = $this->repoRoot . '/candy-shine';
        mkdir($libDir, 0777, true);
        mkdir($libDir . '/src', 0777, true);

        $loader = new InstructionFileLoader($this->repoRoot);
        $sessionCache = [];

        $result = $loader->loadForPath($libDir . '/src/SomeFile.php', $sessionCache);

        $this->assertNull($result);
    }

    public function testLoadForPathReturnsContentOncePerSession(): void
    {
        // Create a lib directory with a CLAUDE.md
        $libDir = $this->repoRoot . '/sugar-crush';
        mkdir($libDir, 0777, true);
        $nestedFile = $libDir . '/CLAUDE.md';
        $nestedContent = "# Instructions";
        file_put_contents($nestedFile, $nestedContent);

        $loader = new InstructionFileLoader($this->repoRoot);
        $sessionCache = [];

        // First call should return content
        $result1 = $loader->loadForPath($libDir . '/src/SomeFile.php', $sessionCache);
        $this->assertSame($nestedContent, $result1);

        // Second call (different file, same lib) should return null (already loaded this session)
        $result2 = $loader->loadForPath($libDir . '/src/OtherFile.php', $sessionCache);
        $this->assertNull($result2);
    }

    public function testLoadForPathFindsLibDirectory(): void
    {
        // Create a deeply nested structure: repo/sugar-crush/src/deep/nested/structure/
        $libDir = $this->repoRoot . '/sugar-crush';
        $deepDir = $libDir . '/src/deep/nested/structure';
        mkdir($deepDir, 0777, true);

        // CLAUDE.md in the lib root
        $nestedFile = $libDir . '/CLAUDE.md';
        $nestedContent = "# Deep lib instructions";
        file_put_contents($nestedFile, $nestedContent);

        $loader = new InstructionFileLoader($this->repoRoot);
        $sessionCache = [];

        // Touch a very deeply nested file
        $result = $loader->loadForPath($deepDir . '/SomeFile.php', $sessionCache);

        $this->assertSame($nestedContent, $result);
    }

    public function testLoadForPathPrefersClaudeMdOverAgentsMd(): void
    {
        $libDir = $this->repoRoot . '/sugar-crush';
        mkdir($libDir, 0777, true);
        file_put_contents($libDir . '/CLAUDE.md', "# CLAUDE.md content");
        file_put_contents($libDir . '/AGENTS.md', "# AGENTS.md content");

        $loader = new InstructionFileLoader($this->repoRoot);
        $sessionCache = [];

        $result = $loader->loadForPath($libDir . '/src/File.php', $sessionCache);

        $this->assertSame("# CLAUDE.md content", $result);
    }

    public function testLoadForPathFallsBackToAgentsMd(): void
    {
        $libDir = $this->repoRoot . '/sugar-crush';
        mkdir($libDir, 0777, true);
        file_put_contents($libDir . '/AGENTS.md', "# AGENTS.md content");

        $loader = new InstructionFileLoader($this->repoRoot);
        $sessionCache = [];

        $result = $loader->loadForPath($libDir . '/src/File.php', $sessionCache);

        $this->assertSame("# AGENTS.md content", $result);
    }

    public function testLoadForPathStopsAtRepoRoot(): void
    {
        // Put a CLAUDE.md at the repo root itself (should NOT be found by loadForPath)
        file_put_contents($this->repoRoot . '/CLAUDE.md', "# Root CLAUDE.md");

        // Create a lib with no nested file
        $libDir = $this->repoRoot . '/some-lib';
        mkdir($libDir, 0777, true);

        $loader = new InstructionFileLoader($this->repoRoot);
        $sessionCache = [];

        // Touch a file in the lib
        $result = $loader->loadForPath($libDir . '/src/File.php', $sessionCache);

        // Should be null since there's no CLAUDE.md/AGENTS.md between lib and root
        // (and root's CLAUDE.md is handled by loadRoot(), not loadForPath)
        $this->assertNull($result);
    }

    public function testLoadForPathNonExistentFile(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot);
        $sessionCache = [];

        $result = $loader->loadForPath('/non/existent/path/File.php', $sessionCache);

        $this->assertNull($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // loadRoot() tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testLoadRootReturnsBothFilesWhenBothExist(): void
    {
        file_put_contents($this->repoRoot . '/CLAUDE.md', "# Root CLAUDE");
        file_put_contents($this->repoRoot . '/AGENTS.md', "# Root AGENTS");

        $loader = new InstructionFileLoader($this->repoRoot);
        $result = $loader->loadRoot();

        $this->assertCount(2, $result);
        $this->assertContains("# Root CLAUDE", $result);
        $this->assertContains("# Root AGENTS", $result);
    }

    public function testLoadRootReturnsOnlyClaudaMdWhenAgentsMdMissing(): void
    {
        file_put_contents($this->repoRoot . '/CLAUDE.md', "# Root CLAUDE only");

        $loader = new InstructionFileLoader($this->repoRoot);
        $result = $loader->loadRoot();

        $this->assertCount(1, $result);
        $this->assertSame("# Root CLAUDE only", $result[0]);
    }

    public function testLoadRootReturnsOnlyAgentsMdWhenClaudaMdMissing(): void
    {
        file_put_contents($this->repoRoot . '/AGENTS.md', "# Root AGENTS only");

        $loader = new InstructionFileLoader($this->repoRoot);
        $result = $loader->loadRoot();

        $this->assertCount(1, $result);
        $this->assertSame("# Root AGENTS only", $result[0]);
    }

    public function testLoadRootReturnsEmptyArrayWhenNeitherFileExists(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot);
        $result = $loader->loadRoot();

        $this->assertSame([], $result);
    }

    public function testLoadRootReturnsEmptyArrayWhenRootDirDoesNotExist(): void
    {
        $loader = new InstructionFileLoader('/non/existent/root');
        $result = $loader->loadRoot();

        $this->assertSame([], $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // loadForced() tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testLoadForcedReturnsMatchedFilesForRelativeGlobPattern(): void
    {
        // Create some nested files that match a glob pattern
        $candyCoreDir = $this->repoRoot . '/candy-core';
        $candyShineDir = $this->repoRoot . '/candy-shine';
        mkdir($candyCoreDir, 0777, true);
        mkdir($candyShineDir, 0777, true);
        file_put_contents($candyCoreDir . '/CALIBER_LEARNINGS.md', "# candy-core learnings");
        file_put_contents($candyShineDir . '/CALIBER_LEARNINGS.md', "# candy-shine learnings");

        $loader = new InstructionFileLoader($this->repoRoot, ['candy-*/CALIBER_LEARNINGS.md']);
        $result = $loader->loadForced();

        $this->assertCount(2, $result);
        $this->assertContains("# candy-core learnings", $result);
        $this->assertContains("# candy-shine learnings", $result);
    }

    public function testLoadForcedReturnsMatchedFilesForAbsoluteGlobPattern(): void
    {
        $candyDir = $this->repoRoot . '/candy-core';
        mkdir($candyDir, 0777, true);
        file_put_contents($candyDir . '/SPEC.md', "# candy-core SPEC");

        $absolutePattern = $candyDir . '/*.md';
        $loader = new InstructionFileLoader($this->repoRoot, [$absolutePattern]);
        $result = $loader->loadForced();

        $this->assertCount(1, $result);
        $this->assertSame("# candy-core SPEC", $result[0]);
    }

    public function testLoadForcedSkipsNonFileMatches(): void
    {
        // Create a directory that matches the glob but isn't a file
        $candyDir = $this->repoRoot . '/candy-core';
        mkdir($candyDir, 0777, true);
        mkdir($candyDir . '/src', 0777, true);

        $loader = new InstructionFileLoader($this->repoRoot, ['candy-core/src']);
        $result = $loader->loadForced();

        $this->assertSame([], $result);
    }

    public function testLoadForcedReturnsEmptyArrayWhenNoPatternsProvided(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot, []);
        $result = $loader->loadForced();

        $this->assertSame([], $result);
    }

    public function testLoadForcedReturnsEmptyArrayWhenPatternMatchesNothing(): void
    {
        $loader = new InstructionFileLoader($this->repoRoot, ['nonexistent-lib-*/README.md']);
        $result = $loader->loadForced();

        $this->assertSame([], $result);
    }

    public function testLoadForcedHandlesMultiplePatterns(): void
    {
        file_put_contents($this->repoRoot . '/CLAUDE.md', "# Root CLAUDE");
        file_put_contents($this->repoRoot . '/AGENTS.md', "# Root AGENTS");

        $loader = new InstructionFileLoader($this->repoRoot, [
            'CLAUDE.md',
            'AGENTS.md',
        ]);
        $result = $loader->loadForced();

        $this->assertCount(2, $result);
        $this->assertContains("# Root CLAUDE", $result);
        $this->assertContains("# Root AGENTS", $result);
    }

    public function testLoadForcedDeduplicatesFilesAcrossPatterns(): void
    {
        file_put_contents($this->repoRoot . '/CLAUDE.md', "# Root CLAUDE");

        // Both patterns match the same file
        $loader = new InstructionFileLoader($this->repoRoot, [
            'CLAUDE.md',
            './CLAUDE.md',
        ]);
        $result = $loader->loadForced();

        // Should appear once, not twice
        $this->assertCount(1, $result);
        $this->assertSame("# Root CLAUDE", $result[0]);
    }
}
