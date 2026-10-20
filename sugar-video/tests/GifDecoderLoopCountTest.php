<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\GifDecoder;
use SugarCraft\Video\Render\Mode;

/**
 * E-02: the GifDecoder reports what the file AUTHORED about looping.
 *
 * NETSCAPE2.0 (and its ANIMEXTS1.0 twin) sub-block carries a loop_count of
 * EXTRA repeats after the first implied play — raw 0 means play forever — so
 * loopCount() returns TOTAL playthroughs (raw N > 0 becomes N + 1) or null
 * for infinite/unauthored. libnsgif defaults an absent extension to a single
 * play (gif.c:1601); we deliberately follow SugarCrush/chicle/browser
 * practice instead and answer null, pinned below. Because raw 0 normalises
 * to null and raw N >= 1 to N + 1 >= 2, no authored bytes can ever yield a
 * loopCount() of 1 — playOnce() is honest host information that stays false
 * for every real GIF, and a one-shot showing is the engine Player's own
 * policy (its loop flag), not a file fact.
 *
 * The read rides the SAME captured bytes as the frames (E-11 law): a reopen
 * of a mutated path keeps reporting the original capture.
 *
 * @covers \SugarCraft\Video\Decode\GifDecoder
 */
final class GifDecoderLoopCountTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->paths = [];
        parent::tearDown();
    }

    private function track(string $path): string
    {
        $this->paths[] = $path;
        return $path;
    }

    /** A solid black GIF on disk, as GD writes it (no loop extension). */
    private function plainGifPath(): string
    {
        $img = imagecreatetruecolor(8, 6);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $black);

        $path = $this->track(sys_get_temp_dir() . '/sugar-video-e02-' . uniqid('', true) . '.gif');
        imagegif($img, $path);
        imagedestroy($img);

        return $path;
    }

    /**
     * A NETSCAPE/ANIMEXTS application-extensions block carrying $raw as its
     * little-endian loop_count, byte-for-byte the shape gif.c:808-871 parses.
     */
    private function loopBlock(int $raw, string $identifier = 'NETSCAPE2.0'): string
    {
        return "\x21\xFF\x0B" . $identifier . "\x03\x01" . pack('v', $raw) . "\x00";
    }

    /** Write a copy of the plain GIF with the loop block spliced after the GCT. */
    private function loopedGifPath(int $raw, string $identifier = 'NETSCAPE2.0'): string
    {
        $bytes = (string) file_get_contents($this->plainGifPath());
        $packed = ord($bytes[10]);
        $gctBytes = ($packed & 0x80) !== 0 ? (1 << (($packed & 0x07) + 1)) * 3 : 0;
        $spliced = substr($bytes, 0, 13 + $gctBytes) . $this->loopBlock($raw, $identifier) . substr($bytes, 13 + $gctBytes);

        $path = $this->track(sys_get_temp_dir() . '/sugar-video-e02-loop-' . uniqid('', true) . '.gif');
        $this->assertTrue(file_put_contents($path, $spliced) === strlen($spliced));

        return $path;
    }

    private function open(string $path): GifDecoder
    {
        $decoder = new GifDecoder();
        $decoder->open($path, 4, 4, 10.0, Mode::Ascii);

        return $decoder;
    }

    public function testUnopenedDecoderReportsInfinite(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $decoder = new GifDecoder();
        $this->assertNull($decoder->loopCount());
        $this->assertFalse($decoder->playOnce());
    }

    public function testAbsentLoopExtensionMeansInfinite(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        // The deliberate divergence from libnsgif (absent -> one play) pinned:
        // browsers and chicle never stop on an unauthored GIF.
        $decoder = $this->open($this->plainGifPath());
        $this->assertNull($decoder->loopCount());
        $this->assertFalse($decoder->playOnce());
    }

    /**
     * raw 0 = play forever -> null; raw 1 = one EXTRA repeat -> 2 total;
     * raw 3 -> 4 total. The extra-repeats reading is gif.c:862 verbatim.
     */
    public function testRawLoopCountNormalisesToTotalPlaythroughs(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $expected = [0 => null, 1 => 2, 3 => 4];
        foreach ($expected as $raw => $total) {
            $decoder = $this->open($this->loopedGifPath($raw));
            $this->assertSame($total, $decoder->loopCount(), "raw loop_count {$raw}");
            $this->assertFalse($decoder->playOnce(), "raw loop_count {$raw} never means play-once");
        }
    }

    public function testAnimExtsTwinIdentifierIsAlsoRecognised(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $decoder = $this->open($this->loopedGifPath(1, 'ANIMEXTS1.0'));
        $this->assertSame(2, $decoder->loopCount());
    }

    public function testPlayOnceIsConsistentWithLoopCount(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        // Honest-host-info identity: playOnce() is exactly loopCount() === 1,
        // and no authored file reaches 1 — proven across the raws above plus
        // the unopened state; this arm pins the identity directly.
        foreach ([null, 2, 4] as $total) {
            $path = $total === null ? $this->plainGifPath() : $this->loopedGifPath($total - 1);
            $decoder = $this->open($path);
            $this->assertSame($total === 1, $decoder->playOnce(), 'playOnce mirrors loopCount===1');
        }
    }

    /**
     * E-11 companion: the loop fact is read from the ONE capture. Swap the
     * file at the same path for an unlooped copy and reopen — the decoder
     * must keep reporting the original capture's authored count.
     */
    public function testReopenOfMutatedPathKeepsCapturedLoopCount(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->loopedGifPath(1);
        $plain = $this->plainGifPath();

        $decoder = $this->open($path);
        $this->assertSame(2, $decoder->loopCount());

        $this->assertTrue(file_put_contents($path, (string) file_get_contents($plain)) !== false);
        $decoder->reopen($path, 4, 4, 10.0, Mode::Ascii);

        $this->assertSame(2, $decoder->loopCount(), 'reopen replays the captured bytes, not the swapped file');
    }
}
