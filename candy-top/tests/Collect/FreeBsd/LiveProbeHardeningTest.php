<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\FreeBsd\LiveProbe;

/** S-P04 (resolve: absolute PATH only, cached) and S-P07 (stdout byte cap). */
final class LiveProbeHardeningTest extends TestCase
{
    private string $dir = '';
    private string|false $savedPath = false;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX child processes');
        }
        $this->savedPath = getenv('PATH');
        $this->dir = sys_get_temp_dir() . '/ct-probe-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        LiveProbe::forgetResolved();
    }

    protected function tearDown(): void
    {
        if ($this->savedPath !== false) {
            putenv('PATH=' . $this->savedPath);
        }
        LiveProbe::forgetResolved();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function tool(string $dir, string $name, string $out): string
    {
        @mkdir($dir);
        $path = $dir . '/' . $name;
        file_put_contents($path, "#!/bin/sh\necho $out\n");
        chmod($path, 0755);

        return $path;
    }

    public function testRelativePathEntryIsNeverSelected(): void
    {
        $name = 'ctprobe' . bin2hex(random_bytes(3));
        $this->tool($this->dir . '/rel', $name, 'hijacked');
        $cwd = getcwd();
        chdir($this->dir);
        try {
            putenv('PATH=./rel:rel');
            $this->assertNull(LiveProbe::new()->run([$name]));
        } finally {
            chdir($cwd);
        }
    }

    public function testAbsolutePathEntryIsFound(): void
    {
        $name = 'ctprobe' . bin2hex(random_bytes(3));
        $this->tool($this->dir, $name, 'found');
        putenv('PATH=' . $this->dir);
        $this->assertSame("found\n", LiveProbe::new()->run([$name]));
    }

    public function testResolveHitIsCachedAndDoesNoStat(): void
    {
        $name = 'ctprobe' . bin2hex(random_bytes(3));
        $path = $this->tool($this->dir, $name, 'once');
        putenv('PATH=' . $this->dir);
        $this->assertSame("once\n", LiveProbe::new()->run([$name]));
        // With the file gone a re-stat would miss; a cache hit goes straight to proc_open,
        // which fails on the missing binary and reports null instead of searching again.
        unlink($path);
        putenv('PATH=/nonexistent');
        $this->assertNull(LiveProbe::new()->run([$name]));
        // The cache entry survived (a miss would also be null, so prove it by recreating):
        $this->tool($this->dir, $name, 'again');
        $this->assertSame("again\n", LiveProbe::new()->run([$name]), 'resolved path reused although PATH no longer lists it');
    }

    public function testOutputOverTheCapIsKilledAndNull(): void
    {
        $this->assertGreaterThan(0, LiveProbe::MAX_OUTPUT);
        $started = microtime(true);
        $this->assertNull(LiveProbe::new()->run(['yes']));
        $this->assertLessThan(2.0, microtime(true) - $started, 'cut by the byte cap, not the 2 s timeout');
    }
}
