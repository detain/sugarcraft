<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Ipmi;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Ipmi\SdrCache;

/**
 * The SDR cache's private home: a 0700 per-user dir that refuses
 * symlinks and loose permissions, files reserved 0600 before anything is
 * written, released on demand, and stale files of dead pids swept.
 */
final class SdrCacheTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/candy-top-sdrc-' . bin2hex(random_bytes(5));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->base . '/*') ?: [] as $d) {
            is_link($d) ? @unlink($d) : @rmdir($d);
        }
        @rmdir($this->base);
    }

    private static function mode(string $path): string
    {
        clearstatcache();

        return substr(sprintf('%o', fileperms($path)), -4);
    }

    public function testOpenMakesAPrivatePerUserDir(): void
    {
        $cache = SdrCache::open($this->base);
        $this->assertNotNull($cache);
        $this->assertSame($this->base . '/candy-top-' . posix_geteuid(), $cache->dir);
        $this->assertSame('0700', self::mode($cache->dir));
        $this->assertNotNull(SdrCache::open($this->base), 'reopening an existing private dir is fine');
    }

    public function testALooseDirIsTightenedAndASymlinkRefused(): void
    {
        $dir = $this->base . '/candy-top-' . posix_geteuid();
        mkdir($dir, 0777);
        chmod($dir, 0777);
        $this->assertNotNull(SdrCache::open($this->base));
        $this->assertSame('0700', self::mode($dir));
        rmdir($dir);

        $elsewhere = $this->base . '/elsewhere';
        mkdir($elsewhere, 0700);
        symlink($elsewhere, $dir);
        $this->assertNull(SdrCache::open($this->base), 'a planted symlink is never followed');
        $this->assertNull(SdrCache::open($this->base . '/missing'));
        $this->assertNull(SdrCache::open(''));
    }

    public function testReserveCreatesThePrivateFileUpFrontAndReleaseRemovesIt(): void
    {
        $cache = SdrCache::open($this->base);
        $this->assertNotNull($cache);
        $file = $cache->reserve();
        $this->assertNotNull($file);
        $this->assertFileExists($file, 'exists before ipmitool ever writes to it');
        $this->assertSame('0600', self::mode($file));
        $this->assertSame(0, filesize($file));
        $this->assertMatchesRegularExpression('/^candy-top-sdr-' . getmypid() . '-[0-9a-f]{8}\.cache$/', basename($file));

        $cache->release($file);
        $this->assertFileDoesNotExist($file);

        $a = (string) $cache->reserve();
        $b = (string) $cache->reserve();
        $this->assertNotSame($a, $b);
        $cache->releaseAll();
        $this->assertFileDoesNotExist($a);
        $this->assertFileDoesNotExist($b);
        $cache->release('/etc/passwd'); // only its own files: a no-op
        $this->assertFileExists('/etc/passwd');
    }

    public function testSweepRemovesOnlyDeadPidsFiles(): void
    {
        $cache = SdrCache::open($this->base);
        $this->assertNotNull($cache);
        $dead = $cache->dir . '/candy-top-sdr-2147483646-0badc0de.cache';
        $live = $cache->dir . '/candy-top-sdr-' . posix_getppid() . '-0badc0de.cache';
        $mine = $cache->dir . '/candy-top-sdr-' . getmypid() . '-0badc0de.cache';
        $other = $cache->dir . '/notes.txt';
        foreach ([$dead, $live, $mine, $other] as $f) {
            touch($f);
        }
        $this->assertSame(1, $cache->sweep());
        $this->assertFileDoesNotExist($dead);
        $this->assertFileExists($live, 'another live candy-top keeps its cache');
        $this->assertFileExists($mine);
        $this->assertFileExists($other, 'only cache-named files are touched');
    }
}
