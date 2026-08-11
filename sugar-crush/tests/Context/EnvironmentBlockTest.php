<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\EnvironmentBlock;

/**
 * Tests for EnvironmentBlock — covers capture(), render(), and gitStatusSnapshot().
 */
final class EnvironmentBlockTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/environment_block_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
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

    // ─── capture() factory tests ───────────────────────────────────

    public function testCaptureSetsCwdAndModelName(): void
    {
        $block = EnvironmentBlock::capture('/some/path', 'claude-sonnet-4-6');

        $this->assertSame('/some/path', $block->cwd());
        $this->assertSame('claude-sonnet-4-6', $block->modelName());
    }

    public function testCaptureSetsNowTimestamp(): void
    {
        $before = new DateTimeImmutable();
        $block = EnvironmentBlock::capture('/any', 'model');
        $after = new DateTimeImmutable();

        $this->assertNotNull($block->now());
        $this->assertGreaterThanOrEqual($before, $block->now());
        $this->assertLessThanOrEqual($after, $block->now());
    }

    // ─── render() basic structure tests ─────────────────────────────

    public function testRenderContainsEnvTags(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'test-model');
        $output = $block->render();

        $this->assertStringStartsWith("<env>\n", $output);
        $this->assertStringEndsWith("\n</env>", $output);
    }

    public function testRenderContainsWorkingDirectory(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'test-model');
        $output = $block->render();

        $this->assertStringContainsString('Working directory: ' . $this->tempDir, $output);
    }

    public function testRenderContainsPlatform(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'test-model');
        $output = $block->render();

        $this->assertStringContainsString('Platform: ' . strtolower(PHP_OS_FAMILY), $output);
    }

    public function testRenderContainsPhpVersion(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'test-model');
        $output = $block->render();

        $this->assertStringContainsString('PHP version: ' . PHP_VERSION, $output);
    }

    public function testRenderContainsModelName(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'my-custom-model');
        $output = $block->render();

        $this->assertStringContainsString('Model: my-custom-model', $output);
    }

    public function testRenderContainsCurrentDate(): void
    {
        $now = new DateTimeImmutable();
        $block = EnvironmentBlock::capture($this->tempDir, 'model', $now);
        $output = $block->render();

        $this->assertStringContainsString('Current date: ' . $now->format('Y-m-d'), $output);
    }

    // ─── render() non-git directory tests ───────────────────────────

    public function testRenderShowsNotGitRepoWhenNoGitDirectory(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'model');
        $output = $block->render();

        $this->assertStringContainsString('Is directory a git repo: No', $output);
        // Should not include git status block
        $this->assertStringNotContainsString('Current branch:', $output);
        $this->assertStringNotContainsString('Status:', $output);
        $this->assertStringNotContainsString('Recent commits:', $output);
    }

    // ─── render() git directory tests ───────────────────────────────

    public function testRenderShowsGitRepoWhenGitDirectoryPresent(): void
    {
        // Create a fake .git directory
        mkdir($this->tempDir . '/.git', 0777, true);

        $block = EnvironmentBlock::capture($this->tempDir, 'model');
        $output = $block->render();

        $this->assertStringContainsString('Is directory a git repo: Yes', $output);
    }

    public function testRenderIncludesGitStatusSnapshotInGitRepo(): void
    {
        // Create a fake .git directory
        mkdir($this->tempDir . '/.git', 0777, true);

        $block = EnvironmentBlock::capture($this->tempDir, 'model');
        $output = $block->render();

        $this->assertStringContainsString('Current branch:', $output);
        $this->assertStringContainsString('Status:', $output);
        $this->assertStringContainsString('Recent commits:', $output);
    }

    // ─── constructor tests ─────────────────────────────────────────

    public function testConstructorAcceptsOptionalNow(): void
    {
        $now = new DateTimeImmutable('2025-01-01');
        $block = new EnvironmentBlock('/path', 'model', $now);

        $this->assertSame($now, $block->now());
    }

    public function testConstructorDefaultsNowToNull(): void
    {
        $block = new EnvironmentBlock('/path', 'model');

        $this->assertNull($block->now());
    }

    public function testConstructorDefaultsNowToNullViaCapture(): void
    {
        // When constructing directly with null now, render falls back to new DateTimeImmutable
        $block = new EnvironmentBlock($this->tempDir, 'model', null);
        $output = $block->render();

        // Should contain a date in Y-m-d format
        $this->assertMatchesRegularExpression('/Current date: \d{4}-\d{2}-\d{2}/', $output);
    }

    // ─── immutability tests ─────────────────────────────────────────

    public function testInstancesAreImmutable(): void
    {
        $block = EnvironmentBlock::capture($this->tempDir, 'model');

        $this->assertSame($this->tempDir, $block->cwd());
        $this->assertSame('model', $block->modelName());
    }
}
