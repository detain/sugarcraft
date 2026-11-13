<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\PrivateRetainedDir;

final class PrivateRetainedDirStrandedPartialTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/prd-stranded-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testFreshPartialIsRefused(): void
    {
        $partial = $this->dir . '/f' . PrivateRetainedDir::PARTIAL_SUFFIX;
        file_put_contents($partial, 'live');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refusing to overwrite');
        try {
            PrivateRetainedDir::write($this->dir, 'f', 'x', 'test');
        } finally {
            $this->assertSame('live', file_get_contents($partial));
        }
    }

    public function testStalePartialHealsAndWriteLands(): void
    {
        $partial = $this->dir . '/f' . PrivateRetainedDir::PARTIAL_SUFFIX;
        file_put_contents($partial, 'dead');
        touch($partial, time() - PrivateRetainedDir::STRANDED_PARTIAL_MIN_AGE_SECONDS - 5);

        $file = PrivateRetainedDir::write($this->dir, 'f', 'new', 'test');

        $this->assertSame('new', file_get_contents($file));
        $this->assertFileDoesNotExist($partial);
    }

    public function testSymlinkPartialIsNeverRemoved(): void
    {
        $partial = $this->dir . '/f' . PrivateRetainedDir::PARTIAL_SUFFIX;
        symlink('/nonexistent-target', $partial);

        try {
            PrivateRetainedDir::write($this->dir, 'f', 'x', 'test');
            $this->fail('expected refusal');
        } catch (\RuntimeException) {
            $this->assertTrue(is_link($partial));
        }
    }
}
