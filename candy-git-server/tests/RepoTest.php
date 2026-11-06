<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\Visibility;

/**
 * @covers \SugarCraft\GitServer\Repo
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
    // -------------------------------------------------------------------------

    /**
     * Fix . : init() used to make $path itself the
     * bare repo while exists() and every discovery gate looked for
     * $path/.git — a freshly created repository was invisible to its own
     * creator. This roundtrip pins the layout agreement.
     */
    public function testInitProducesLayoutVisibleToExistsAndDiscovery(): void
    {
        $path = $this->tmpDir . '/fresh-created';
        $r = Repo::new('fresh', $path)->withDescription('roundtrip')->init();

        $this->assertTrue($r->exists(), 'init() must satisfy exists(): bare Git at $path/.git');
        $this->assertTrue(is_dir($path . '/.git'));
        // The metadata writes land INSIDE the Git dir now, not beside it.
        $this->assertTrue(is_file($path . '/.git/description'));
        $this->assertStringContainsString('roundtrip', (string) file_get_contents($path . '/.git/description'));
        // init() must NOT drop a git-daemon-export-ok marker: that file
        // opts the repo into anonymous read-export for any co-hosted stock
        // git-daemon, so it is an operator decision, never automatic.
        $this->assertFalse(is_file($path . '/.git/git-daemon-export-ok'));
        $this->assertTrue(is_dir($path . '/.git/hooks'));
        // Real git commands run against $path (git -C path works on this layout).
        $this->assertSame([], $r->branches());
    }

    public function testExistsReturnsFalseWhenNotInitialized(): void
    {
        $path = $this->createRepoPath('not-init');
        $r = Repo::new('not-init', $path);

        $this->assertFalse($r->exists());
    }

    /**
     * n15: writeVisibilityMarker creates the marker directory fresh; the
     * mkdir must state owner-only intent, not inherit a permissive umask.
     */
    public function testAFreshlyCreatedMarkerDirectoryIsOwnerOnly(): void
    {
        $bare = $this->tmpDir . '/fresh-cap.git';

        Repo::new('fresh-cap', $bare)->withPublic(true)->writeVisibilityMarker();

        $this->assertSame(0700, \fileperms($bare . '/.git') & 0777, 'the fresh marker directory is owner-only regardless of process umask');
        $this->assertSame(Visibility::Public, Repo::markerVisibility($bare . '/.git'), 'the marker still round-trips through the reader');
    }
}
