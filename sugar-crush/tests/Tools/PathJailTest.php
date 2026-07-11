<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\PathJail;

/**
 * @see PathJail
 */
final class PathJailTest extends TestCase
{
    private string $jail;
    private string $outside;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/sugarcrush_pathjail_' . uniqid();
        $this->jail = $base . '/jail';
        $this->outside = $base . '/outside';
        mkdir($this->jail, 0777, true);
        mkdir($this->outside, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->jail, $this->outside, dirname($this->jail)] as $dir) {
            if (is_dir($dir)) {
                $this->rrmdir($dir);
            }
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
        rmdir($dir);
    }

    // =========================================================================
    // resolve()
    // =========================================================================

    /** The jail root itself is a valid in-jail path (regression: off-by-one). */
    public function testResolveAcceptsJailRootItself(): void
    {
        $resolved = PathJail::resolve($this->jail, '.');

        $this->assertSame(realpath($this->jail), $resolved);
    }

    public function testResolveAcceptsJailRootViaEmptyPath(): void
    {
        $resolved = PathJail::resolve($this->jail, '');

        $this->assertSame(realpath($this->jail), $resolved);
    }

    public function testResolveAcceptsExistingInJailChild(): void
    {
        file_put_contents($this->jail . '/child.txt', 'hi');

        $resolved = PathJail::resolve($this->jail, 'child.txt');

        $this->assertSame(realpath($this->jail . '/child.txt'), $resolved);
    }

    public function testResolveAcceptsNewFileInJail(): void
    {
        // File does not exist yet, but its parent (the jail root) does.
        $resolved = PathJail::resolve($this->jail, 'newfile.txt');

        $this->assertSame(realpath($this->jail) . '/newfile.txt', $resolved);
    }

    public function testResolveRejectsDotDotEscape(): void
    {
        file_put_contents($this->outside . '/secret.txt', 'top secret');

        $resolved = PathJail::resolve($this->jail, '../outside/secret.txt');

        $this->assertNull($resolved);
    }

    public function testResolveRejectsAbsoluteOutsidePath(): void
    {
        file_put_contents($this->outside . '/secret.txt', 'top secret');

        $resolved = PathJail::resolve($this->jail, $this->outside . '/secret.txt');

        $this->assertNull($resolved);
    }

    public function testResolveRejectsSymlinkEscape(): void
    {
        file_put_contents($this->outside . '/secret.txt', 'top secret');
        $link = $this->jail . '/link-to-secret';
        symlink($this->outside . '/secret.txt', $link);

        $resolved = PathJail::resolve($this->jail, 'link-to-secret');

        $this->assertNull($resolved);
    }

    public function testResolveReturnsNullForMissingRoot(): void
    {
        $resolved = PathJail::resolve($this->jail . '/does-not-exist', 'x');

        $this->assertNull($resolved);
    }

    // =========================================================================
    // resolveDir()
    // =========================================================================

    public function testResolveDirAcceptsJailRootItself(): void
    {
        $resolved = PathJail::resolveDir($this->jail, '.');

        $this->assertSame(realpath($this->jail), $resolved);
    }

    public function testResolveDirAcceptsInJailSubdir(): void
    {
        mkdir($this->jail . '/sub');

        $resolved = PathJail::resolveDir($this->jail, 'sub');

        $this->assertSame(realpath($this->jail . '/sub'), $resolved);
    }

    public function testResolveDirRejectsDotDotEscape(): void
    {
        $resolved = PathJail::resolveDir($this->jail, '../outside');

        $this->assertNull($resolved);
    }
}
