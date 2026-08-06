<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\InstructionFileLoader;

final class InstructionFileLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/instruction_file_loader_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testLoadRootReturnsArray(): void
    {
        file_put_contents($this->tempDir . '/CLAUDE.md', '# Claude instructions');

        $loader = new InstructionFileLoader($this->tempDir);
        $result = $loader->loadRoot();

        $this->assertIsArray($result);
    }

    public function testLoadRootFindsBothFiles(): void
    {
        file_put_contents($this->tempDir . '/CLAUDE.md', '# Claude instructions');
        file_put_contents($this->tempDir . '/AGENTS.md', '# Agents instructions');

        $loader = new InstructionFileLoader($this->tempDir);
        $result = $loader->loadRoot();

        $this->assertCount(2, $result);
        $this->assertContains('# Claude instructions', $result);
        $this->assertContains('# Agents instructions', $result);
    }

    public function testLoadRootReturnsEmptyArrayWhenNoFiles(): void
    {
        $loader = new InstructionFileLoader($this->tempDir);
        $result = $loader->loadRoot();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testLoadRootSkipsMissingFiles(): void
    {
        file_put_contents($this->tempDir . '/CLAUDE.md', '# Claude instructions');

        $loader = new InstructionFileLoader($this->tempDir);
        $result = $loader->loadRoot();

        $this->assertCount(1, $result);
        $this->assertContains('# Claude instructions', $result);
    }

    public function testLoadForcedReturnsArray(): void
    {
        $loader = new InstructionFileLoader($this->tempDir, []);
        $result = $loader->loadForced();

        $this->assertIsArray($result);
    }

    public function testLoadForcedGlobsPatterns(): void
    {
        mkdir($this->tempDir . '/candy-shine', 0755, true);
        file_put_contents($this->tempDir . '/candy-shine/CALIBER_LEARNINGS.md', '# Candy Shine learnings');

        mkdir($this->tempDir . '/candy-core', 0755, true);
        file_put_contents($this->tempDir . '/candy-core/CALIBER_LEARNINGS.md', '# Candy Core learnings');

        $loader = new InstructionFileLoader($this->tempDir, ['candy-*/CALIBER_LEARNINGS.md']);
        $result = $loader->loadForced();

        $this->assertCount(2, $result);
        $this->assertContains('# Candy Shine learnings', $result);
        $this->assertContains('# Candy Core learnings', $result);
    }

    public function testLoadForcedReturnsEmptyOnNoMatches(): void
    {
        $loader = new InstructionFileLoader($this->tempDir, ['nonexistent-*/CALIBER_LEARNINGS.md']);
        $result = $loader->loadForced();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testLoadForcedReturnsEmptyWhenNoPatterns(): void
    {
        $loader = new InstructionFileLoader($this->tempDir, []);
        $result = $loader->loadForced();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testLoadForcedSkipsNonFilesInGlobResults(): void
    {
        mkdir($this->tempDir . '/candy-test', 0755, true);
        // Create a directory (not a file) that matches the glob
        mkdir($this->tempDir . '/candy-test/CALIBER_LEARNINGS.md', 0755, true);

        $loader = new InstructionFileLoader($this->tempDir, ['candy-*/CALIBER_LEARNINGS.md']);
        $result = $loader->loadForced();

        $this->assertCount(0, $result);
    }
}
