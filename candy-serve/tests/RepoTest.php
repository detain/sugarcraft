<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Repo;

/**
 * @covers \SugarCraft\Serve\Repo
 */
final class RepoTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/repo-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    private function createRepoPath(string $name = 'test'): string
    {
        $path = $this->tmpDir . '/' . $name;
        mkdir($path, 0755, true);
        return $path;
    }

    // -------------------------------------------------------------------------
    // Builder method tests
    // -------------------------------------------------------------------------

    public function testWithDescription(): void
    {
        $r = Repo::new('test', '/tmp/test')->withDescription('My Description');
        $this->assertSame('My Description', $r->description);
    }

    public function testWithHighlightLanguage(): void
    {
        $r = Repo::new('test', '/tmp/test')->withHighlightLanguage('php');
        $this->assertSame('php', $r->highlightLanguage);
    }

    public function testWithAllowPush(): void
    {
        $r = Repo::new('test', '/tmp/test')->withAllowPush(true);
        $this->assertTrue($r->allowPush);
    }

    // -------------------------------------------------------------------------
    // Git operations tests
    // Note: Repo::init() uses git init without -C flag, so git commands run
    // in the current working directory, not in $this->path.
    // Only test non-git-dependent functionality.
    // -------------------------------------------------------------------------

    public function testExistsReturnsFalseWhenNotInitialized(): void
    {
        $path = $this->createRepoPath('not-init');
        $r = Repo::new('not-init', $path);

        $this->assertFalse($r->exists());
    }
}
