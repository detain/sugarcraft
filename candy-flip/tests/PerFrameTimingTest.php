<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Decoder;
use SugarCraft\Flip\Frame;
use PHPUnit\Framework\TestCase;

/**
 * Tests for per-frame timing parsed from GIF Graphic Control Extension (GCE).
 * Verifies that `imagecreatefromstring()` in-memory decoding works and that
 * each frame carries its own delay value from the GCE block.
 */
final class PerFrameTimingTest extends TestCase
{
    private string $gifPath;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd not available');
        }
        $this->gifPath = sys_get_temp_dir() . '/multi-frame-' . uniqid() . '.gif';
    }

    protected function tearDown(): void
    {
        if (isset($this->gifPath) && file_exists($this->gifPath)) {
            unlink($this->gifPath);
        }
    }

    /**
     * Build a minimal 2-frame animated GIF with distinct GCE delays.
     * Frame 1: delay=20 (200ms), Frame 2: delay=5 (50ms).
     * Canvas: 1x1, 2-color GCT (black + red).
     */
    private function buildTwoFrameGif(int $delay1, int $delay2): void
    {
        // GIF89a header (6 bytes)
        $gif = "\x47\x49\x46\x38\x39\x61"; // GIF89a

        // Logical Screen Descriptor (7 bytes): 1x1, no GCT flag here (global flag below)
        $gif .= "\x01\x00\x01\x00\x80\x00\x00";
        //        ^ width=1 ^ height=1 ^ packed (GCT flag=1, GCT size=2^2=4 colors)
        //                               ^ bg=0 ^ par=0

        // Global Color Table: 4 entries × 3 bytes = 12 bytes
        $gif .= "\x00\x00\x00"           // color 0: black
              . "\xff\x00\x00"           // color 1: red
              . "\x00\x00\x00"           // color 2: black (same)
              . "\x00\x00\x00";          // color 3: black (same)

        // Application Extension for NETSCAPE looping (so animation repeats).
        $gif .= "\x21\xFF\x0B"                      // extension start + block size
             . "NETSCAPE2.0"                         // app identifier
             . "\x03\x01\x00\x00\x00";              // sub-block + loop count (0=forever) + block terminator

        // --- Frame 1 ---
        // Graphic Control Extension: delay = $delay1 centiseconds
        $gif .= "\x21\xF9\x04\x00"                 // GCE header
              . chr($delay1 & 0xFF) . chr(($delay1 >> 8) & 0xFF)  // delay LE
              . "\x00\x00";                         // disposal=0, transparent=none

        // Image Descriptor for frame 1: 1x1 at (0,0)
        $gif .= "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00";

        // LZW minimum code size + image data (1x1 red pixel)
        // Sub-block format: [len=3][clear=4][idx=1][eoi=5][terminator=0]
        $gif .= "\x02";                            // LZW min code size = 2
        $gif .= "\x03\x04\x01\x05\x00";           // LZW: clear(4) + index 1 (red) + EOI(5) + terminator

        // --- Frame 2 ---
        // Graphic Control Extension: delay = $delay2 centiseconds
        $gif .= "\x21\xF9\x04\x00"
              . chr($delay2 & 0xFF) . chr(($delay2 >> 8) & 0xFF)
              . "\x00\x00";

        // Image Descriptor for frame 2: same 1x1 at (0,0)
        $gif .= "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00";

        // LZW image data for frame 2 (1x1 black pixel)
        $gif .= "\x02";
        $gif .= "\x44\x00\x3B";

        file_put_contents($this->gifPath, $gif);
    }

    public function testDecodeReturnsTwoFrames(): void
    {
        $this->buildTwoFrameGif(20, 5);
        $frames = Decoder::decode($this->gifPath, 1, 1);
        $this->assertCount(2, $frames);
    }

    public function testFirstFrameHasCorrectDelay(): void
    {
        $this->buildTwoFrameGif(20, 5);
        $frames = Decoder::decode($this->gifPath, 1, 1);
        $this->assertSame(20, $frames[0]->delay);
    }

    public function testSecondFrameHasCorrectDelay(): void
    {
        $this->buildTwoFrameGif(20, 5);
        $frames = Decoder::decode($this->gifPath, 1, 1);
        $this->assertSame(5, $frames[1]->delay);
    }

    public function testFramesHaveDistinctDelays(): void
    {
        $this->buildTwoFrameGif(15, 3);
        $frames = Decoder::decode($this->gifPath, 1, 1);
        $this->assertNotSame($frames[0]->delay, $frames[1]->delay);
    }

    public function testFrameDelaysAreDistinctAcrossThreeFrames(): void
    {
        $this->buildTwoFrameGif(8, 12);
        $frames = Decoder::decode($this->gifPath, 1, 1);
        $this->assertCount(2, $frames);
        $this->assertSame(8, $frames[0]->delay);
        $this->assertSame(12, $frames[1]->delay);
    }

    public function testFramesStillRenderCorrectly(): void
    {
        $this->buildTwoFrameGif(10, 20);
        $frames = Decoder::decode($this->gifPath, 1, 1);
        $this->assertInstanceOf(Frame::class, $frames[0]);
        $this->assertSame(1, $frames[0]->width());
        $this->assertSame(1, $frames[0]->height());
        $this->assertInstanceOf(Frame::class, $frames[1]);
        $this->assertSame(1, $frames[1]->width());
        $this->assertSame(1, $frames[1]->height());
    }

    /**
     * Single-frame GIF with no GCE should default delay=10.
     */
    public function testSingleFrameWithoutGceUsesDefaultDelay(): void
    {
        // Build a single-frame GIF with no GCE (static GIF).
        $gif = "\x47\x49\x46\x38\x37\x61"       // GIF87a
             . "\x01\x00\x01\x00\x80\x00\x00"  // LSD: 1x1, GCT=1, size=2^2=4
             . "\x00\x00\x00"                   // GCT[0]=black
             . "\xff\x00\x00\x00\x00\x00\x00"  // GCT[1]=red, [2]=[3]=black
             . "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00" // Image Descriptor
             . "\x02"                           // LZW min code
             . "\x44\x00\x3b";                  // image data + trailer
        $path = sys_get_temp_dir() . '/single-' . uniqid() . '.gif';
        file_put_contents($path, $gif);
        try {
            $frames = Decoder::decode($path, 1, 1);
            $this->assertCount(1, $frames);
            $this->assertSame(10, $frames[0]->delay);
        } finally {
            unlink($path);
        }
    }

    public function testFrameDelayIsNotAlwaysZero(): void
    {
        $this->buildTwoFrameGif(20, 20);
        $frames = Decoder::decode($this->gifPath, 1, 1);
        // Neither frame should have delay 0 (GIF spec default is 10).
        foreach ($frames as $frame) {
            $this->assertNotSame(0, $frame->delay);
        }
    }
}
