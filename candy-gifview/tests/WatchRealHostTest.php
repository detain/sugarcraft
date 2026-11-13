<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Exec-based integration pin for the changeToken stat-cache cure (finding
 * 001 — the --watch-frozzle crown).
 *
 * PHP's stat cache is per-process and survives WRITES BY OTHER PROCESSES:
 * the first stat() of a path freezes its answer for the rest of the
 * request, and a long-lived --watch poll is one endless request. In-process
 * writes self-clear the cache, so no same-process test can ever see the
 * staleness — a child polling loop fed by out-of-band mutations is the only
 * shape that catches the bug class. Both mutation arms run here:
 *
 *  (a) same-size in-place edit — the baseline file is back-dated by touch()
 *      so the edit's fresh mtime second differs from it by construction
 *      (a sub-second race can never mask the flip);
 *  (b) replace-by-rename — fresh inode, different size.
 *
 * Under the pre-cure code the child answered ONE frozen token forever and
 * both arms redden; every poll also carried a bare `$s['mtime_ns']`
 * undefined-key Warning against the real stat, so stderr must stay clean.
 */
final class WatchRealHostTest extends TestCase
{
    /** Child poll cadence and how long each arm watches for the flip. */
    private const POLL_MICROS = 50_000;
    private const WATCH_SECONDS = 3.0;

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
        $this->paths = [];
    }

    public function testAnExternalSameSizeEditFlipsThePollToken(): void
    {
        $path = $this->gifPath();
        $before = (string) file_get_contents($path);

        // Equal-length replacement: same dev, same ino, same size — only a
        // fresh stat can see the change. Back-date so the edit's mtime
        // second is guaranteed distinct from the baseline's.
        $replacement = $this->exactLength($this->gifBytes(), strlen($before));
        touch($path, time() - 30);

        $lines = $this->watchWhile($path, static function () use ($path, $replacement): void {
            file_put_contents($path, $replacement);
        });

        $this->assertGreaterThanOrEqual(
            2,
            count(array_unique($lines)),
            'the poll answered the same frozen token across an external same-size edit — the stat cache was never cleared',
        );
        $this->assertSame($this->liveToken($path), (string) end($lines), 'the last poll token disagrees with a fresh stat');
    }

    public function testAReplaceByRenameFlipsThePollToken(): void
    {
        $path = $this->gifPath();
        $incoming = $this->gifPath();

        $lines = $this->watchWhile($path, static function () use ($path, $incoming): void {
            self::assertTrue(rename($incoming, $path), 'replace-by-rename failed to install');
        });

        $this->assertGreaterThanOrEqual(
            2,
            count(array_unique($lines)),
            'the poll kept a stale identity across a replace-by-rename — dev/ino/size never reached a fresh stat',
        );
        $this->assertSame($this->liveToken($path), (string) end($lines));
    }

    /**
     * Spawn the polling child against $path, run $mutate out-of-band, and
     * return the child's token lines. The child process never performs the
     * write itself, so nothing self-cleared its stat cache: this is the
     * external editor's audience shape, exactly.
     *
     * @param \Closure(): void $mutate
     *
     * @return list<string>
     */
    private function watchWhile(string $path, \Closure $mutate): array
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd is required to build the watched GIF fixtures.');
        }

        $proc = proc_open(
            ['timeout', (string) ((int) ceil(self::WATCH_SECONDS) + 5), \PHP_BINARY,
                '-d', 'display_errors=stderr', '-d', 'error_reporting=-1',
                $this->writeDriver(), $path, (string) self::WATCH_SECONDS],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc, 'could not start the polling child');
        fclose($pipes[0]);

        usleep(400_000); // let the child take its baseline reading first
        $mutate();

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertStringNotContainsString('Warning', $err, 'a poll emitted a raw PHP warning: ' . $err);

        $lines = array_values(array_filter(
            explode("\n", trim($out)),
            static fn (string $l): bool => $l !== '',
        ));
        $this->assertNotEmpty($lines, 'the polling child answered nothing');

        return $lines;
    }

    /**
     * Standalone polling driver: reflects into the private changeToken and
     * prints its real-stat answer on the watch cadence until deadline.
     */
    private function writeDriver(): string
    {
        $autoload = (string) realpath(__DIR__ . '/../vendor/autoload.php');
        $cadence = (string) self::POLL_MICROS;
        $driver = $this->paths[] = sys_get_temp_dir() . '/gifview-watch-driver-' . uniqid('', true) . '.php';
        file_put_contents($driver, <<<PHP
            <?php
            require '{$autoload}';
            \$method = new ReflectionMethod(\SugarCraft\GifView\Cli::class, 'changeToken');
            \$method->setAccessible(true);
            \$path = \$argv[1];
            \$deadline = microtime(true) + (float) \$argv[2];
            do {
                echo implode(',', \$method->invoke(null, \$path, null)), "\n";
                fflush(STDOUT);
                usleep({$cadence});
            } while (microtime(true) < \$deadline);
            PHP);

        return $driver;
    }

    /** The same token, recomputed from THIS process with an honest fresh stat. */
    private function liveToken(string $path): string
    {
        clearstatcache(false, $path);
        $s = stat($path);
        $this->assertIsArray($s);

        return implode(',', [$s['dev'], $s['ino'], $s['size'], $s['mtime']]);
    }

    /** A small GD GIF at a fresh temp path (pixel content varies per call). */
    private function gifPath(): string
    {
        $path = $this->paths[] = sys_get_temp_dir() . '/gifview-watch-' . uniqid('', true) . '.gif';
        file_put_contents($path, $this->gifBytes());

        return $path;
    }

    /** @return non-empty-string */
    private function gifBytes(): string
    {
        $im = imagecreatetruecolor(6, 4);
        for ($x = 0; $x < 6; $x++) {
            for ($y = 0; $y < 4; $y++) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 37 + $y * 11 + count($this->paths)) % 256, $y * 53 % 256, $x * 29 % 256));
            }
        }
        $tmp = tempnam(sys_get_temp_dir(), 'gv_watch_gif_');
        $this->paths[] = $tmp;
        imagegif($im, $tmp);
        imagedestroy($im);
        $bytes = (string) file_get_contents($tmp);
        $this->assertNotSame('', $bytes);

        return $bytes;
    }

    /** Grow with NULs after the trailer, or truncate, to hit $length exactly. */
    private function exactLength(string $bytes, int $length): string
    {
        if (strlen($bytes) >= $length) {
            return substr($bytes, 0, $length);
        }

        return str_pad($bytes, $length, "\0");
    }
}
