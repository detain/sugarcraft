<?php

declare(strict_types=1);

namespace SugarCraft\GifView\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\WatchMsg;
use SugarCraft\GifView\Cli;

/**
 * E-02: the front end honours the AUTHORED loop count (a spliced NETSCAPE
 * block lands on the Player as total playthroughs, raw 1 -> 2), and E-10:
 * --watch arms Player::withWatch behind the stat seam. The real Program
 * loop is never entered — the $play seam captures the wired Player, and a
 * scripted fake-stat drives the change token without any wall-clock poll.
 *
 * @covers \SugarCraft\GifView\Cli
 */
final class CliLoopWatchTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->paths = [];
        parent::tearDown();
    }

    /** @return resource */
    private function memory()
    {
        $s = fopen('php://memory', 'w+');
        self::assertIsResource($s);

        return $s;
    }

    /** @param resource $stream */
    private static function drain($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /** A solid black single-frame GIF written by GD (no loop extension). */
    private function blackGifPath(): string
    {
        $img = imagecreatetruecolor(8, 6);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $black);
        $path = $this->paths[] = sys_get_temp_dir() . '/gifview-e02-' . uniqid('', true) . '.gif';
        imagegif($img, $path);
        imagedestroy($img);

        return $path;
    }

    /** A gradient GIF whose decoded cells are provably NOT all black. */
    private function gradientGifBytes(): string
    {
        $img = imagecreatetruecolor(8, 6);
        for ($y = 0; $y < 6; $y++) {
            for ($x = 0; $x < 8; $x++) {
                imagesetpixel($img, $x, $y, imagecolorallocate($img, $x * 31 % 256, $y * 47 % 256, 200));
            }
        }
        $path = $this->paths[] = sys_get_temp_dir() . '/gifview-e10-grad-' . uniqid('', true) . '.gif';
        imagegif($img, $path);
        imagedestroy($img);

        return (string) file_get_contents($path);
    }

    /** Insert a NETSCAPE2.0 loop app-extension after the GCT of GD bytes. */
    private function withLoopBlock(string $bytes, int $raw): string
    {
        $packed = ord($bytes[10]);
        $gctBytes = ($packed & 0x80) !== 0 ? (1 << (($packed & 0x07) + 1)) * 3 : 0;
        $block = "\x21\xFF\x0B" . 'NETSCAPE2.0' . "\x03\x01" . pack('v', $raw) . "\x00";

        return substr($bytes, 0, 13 + $gctBytes) . $block . substr($bytes, 13 + $gctBytes);
    }

    /**
     * @param ?\Closure(Player): int $play
     * @param ?\Closure(string): (array|false) $watchStat
     * @param ?\Closure(string, int, int): array{string, list<\SugarCraft\Animation\Frame>} $decode
     */
    private function cli(array $argv, ?\Closure $play = null, ?\Closure $watchStat = null, ?\Closure $decode = null): array
    {
        $out = $this->memory();
        $err = $this->memory();
        $exit = Cli::main(array_merge(['candy-gifview'], $argv), $out, $err, $play, $watchStat, $decode);

        return [$exit, self::drain($out), self::drain($err)];
    }

    /** @return \Closure(string): array<string|int,mixed> */
    private function constantStat(int $ino): \Closure
    {
        return static fn (string $p): array => ['dev' => 1, 'ino' => $ino, 'size' => 100, 'mtime_ns' => 1000];
    }

    public function testPlainGifPlayerLoopsStayInfinite(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required to build test GIFs');
        }
        $seen = null;
        [$exit, , $err] = $this->cli([$this->blackGifPath()], function (Player $p) use (&$seen): int {
            $seen = $p;

            return 0;
        });
        $this->assertSame(0, $exit, $err);
        $this->assertNotNull($seen);
        $this->assertNull($seen->loops, 'no loop extension -> infinite (gif.c:1601 divergence pinned)');
    }

    public function testAuthoredLoopCountBindsThePlayer(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required to build test GIFs');
        }
        $path = $this->blackGifPath();
        $spliced = $this->withLoopBlock((string) file_get_contents($path), 1);
        $looped = $this->paths[] = sys_get_temp_dir() . '/gifview-e02-loop-' . uniqid('', true) . '.gif';
        file_put_contents($looped, $spliced);

        $seen = null;
        [$exit, , $err] = $this->cli([$looped], function (Player $p) use (&$seen): int {
            $seen = $p;

            return 0;
        });
        $this->assertSame(0, $exit, $err);
        // raw 1 means ONE extra repeat -> 2 total playthroughs (gif.c:862).
        $this->assertSame(2, $seen->loops);
    }

    public function testWatchWithAValueIsRefused(): void
    {
        [$exit, , $err] = $this->cli(['--watch=5', 'x.gif']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--watch takes no value', $err);
    }

    public function testWatchArmsTheProbeAndNoWatchDoesNot(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required to build test GIFs');
        }
        $path = $this->blackGifPath();
        $calls = 0;
        $stat = function (string $p) use (&$calls): array {
            $calls++;

            return ['dev' => 1, 'ino' => 7, 'size' => 100, 'mtime_ns' => 1000];
        };

        $armed = null;
        [$exit] = $this->cli(['--watch', $path], function (Player $p) use ($stat): int {
            // Token never moves: the probe must report no change, keeping the
            // Player instance and re-arming the next poll tick.
            [$same, $cmd] = $p->update(new WatchMsg());
            self::assertSame($p, $same);
            self::assertInstanceOf(\Closure::class, $cmd);

            return 0;
        }, $stat);
        $this->assertSame(0, $exit);
        $this->assertGreaterThan(1, $calls, 'baseline token at wiring + poll at probe time');

        [$exit2] = $this->cli([$path], function (Player $p): int {
            [$same, $cmd] = $p->update(new WatchMsg());
            self::assertSame($p, $same);
            self::assertNull($cmd, 'un-armed Player ignores WatchMsg');

            return 0;
        });
        $this->assertSame(0, $exit2);
    }

    public function testTokenChangeRebindsFramesAndLoopCount(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required to build test GIFs');
        }
        $path = $this->blackGifPath();
        $tokens = [['dev' => 1, 'ino' => 7, 'size' => 100, 'mtime_ns' => 1000],
            ['dev' => 1, 'ino' => 8, 'size' => 100, 'mtime_ns' => 2000]];
        $stat = static function (string $p) use (&$tokens): array {
            return array_shift($tokens) ?? ['dev' => 1, 'ino' => 8, 'size' => 100, 'mtime_ns' => 2000];
        };

        // The swap happens AFTER wiring: the captured Player must hold the
        // black frames, and the reload must land on the looped gradient.
        $swapped = $this->withLoopBlock($this->gradientGifBytes(), 1);

        [$exit, , $err] = $this->cli(['--watch', $path], function (Player $p) use ($path, $stat, $swapped): int {
            $before = $p;
            file_put_contents($path, $swapped);
            [$next, $cmd] = $p->update(new WatchMsg());
            self::assertNotSame($before, $next, 'token change must rebind');
            self::assertNotNull($cmd, 'poll tick stays armed across the reload');
            self::assertSame(0, $next->index);
            self::assertSame(2, $next->loops, 'reloaded loop count is re-derived');
            self::assertNotSame($before->frames[0]->cells, $next->frames[0]->cells);
            // Token now settled at the newest value: another poll is a no-op.
            [$same, $cmd2] = $next->update(new WatchMsg());
            self::assertSame($next, $same);
            self::assertNotNull($cmd2);

            return 0;
        }, $stat);
        $this->assertSame(0, $exit, $err);
    }

    public function testBrokenReloadKeepsPlayingStale(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required to build test GIFs');
        }
        $path = $this->blackGifPath();
        $tokens = [['dev' => 1, 'ino' => 7, 'size' => 100, 'mtime_ns' => 1000],
            ['dev' => 1, 'ino' => 8, 'size' => 100, 'mtime_ns' => 2000]];
        $stat = static function (string $p) use (&$tokens): array {
            return array_shift($tokens) ?? ['dev' => 1, 'ino' => 8, 'size' => 100, 'mtime_ns' => 2000];
        };

        [$exit] = $this->cli(['--watch', $path], function (Player $p) use ($path, $stat): int {
            file_put_contents($path, 'not a gif at all');
            [$same, $cmd] = $p->update(new WatchMsg());
            self::assertSame($p, $same, 'half-written file keeps the stale frames (chicle guard shape)');
            self::assertNotNull($cmd, 'and the poll stays armed');

            return 0;
        }, $stat);
        $this->assertSame(0, $exit);
    }

    /**
     * Security m11: a raw \Error escaping the reload walk (outside the
     * Decoder's documented RuntimeException contract) must not kill the
     * watch loop. The $decode seam answers the wiring-time intake with the
     * real frames and throws \Error on the reload — the widened rescue catch
     * keeps the stale frames playing and the poll armed.
     */
    public function testRawErrorOnReloadKeepsPlayingStale(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required to build test GIFs');
        }
        $path = $this->blackGifPath();
        $tokens = [['dev' => 1, 'ino' => 7, 'size' => 100, 'mtime_ns' => 1000],
            ['dev' => 1, 'ino' => 8, 'size' => 100, 'mtime_ns' => 2000]];
        $stat = static function (string $p) use (&$tokens): array {
            return array_shift($tokens) ?? ['dev' => 1, 'ino' => 8, 'size' => 100, 'mtime_ns' => 2000];
        };

        $decodeCalls = 0;
        $decode = static function (string $p, int $w, int $h) use (&$decodeCalls): array {
            $decodeCalls++;
            if ($decodeCalls === 1) {
                $bytes = \SugarCraft\Animation\Decoder::readFile($p);

                return [$bytes, iterator_to_array(
                    \SugarCraft\Animation\Decoder::decodeBytes($bytes, $w, $h),
                    preserve_keys: false,
                )];
            }

            throw new \Error('simulated walk failure on reload');
        };

        [$exit, , $err] = $this->cli(['--watch', $path], function (Player $p) use (&$decodeCalls): int {
            self::assertSame(1, $decodeCalls, 'wiring consumed the first intake');
            [$same, $cmd] = $p->update(new WatchMsg());
            self::assertSame($p, $same, 'an \Error on reload keeps the stale frames');
            self::assertNotNull($cmd, 'and the poll stays armed');

            return 0;
        }, $stat, $decode);

        $this->assertSame(0, $exit, $err);
        $this->assertSame(2, $decodeCalls, 'the reload was attempted — the \Error came from inside the guarded walk');
    }
}
