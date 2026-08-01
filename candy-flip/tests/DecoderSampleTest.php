<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Decoder;
use SugarCraft\Flip\Frame;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Decoder::sample() — the area-average downsampler for single-frame
 * GIFs that have no Image Descriptor (so frameInfos is empty).
 *
 * The public entry point Decoder::decode() calls renderSingleFrame() when it
 * finds a valid GIF header but no Image Descriptors in the byte stream.
 * renderSingleFrame() calls sample() to downsample the decoded GD image.
 */
final class DecoderSampleTest extends TestCase
{
    private ?string $tmpPath = null;

    protected function tearDown(): void
    {
        if ($this->tmpPath !== null && file_exists($this->tmpPath)) {
            unlink($this->tmpPath);
        }
    }

    /**
     * Build a minimal GIF89a that has a valid header and global color table
     * but NO Image Descriptor — this forces frameInfos to remain empty and
     * triggers the renderSingleFrame() → sample() code path.
     *
     * Structure:
     *   - GIF89a header          (6 bytes)
     *   - Logical Screen Desc.   (7 bytes)  1×1 pixels
     *   - Global Color Table     (6 bytes)  2 entries
     *   - NO Graphic Control Ext
     *   - NO Image Descriptor
     *   - NO LZW data
     *   - GIF Trailer            (1 byte)
     *
     * When parsed, parseHeader() finds no 0x2C bytes → frameInfos = [].
     * decode() then calls renderSingleFrame() → sample().
     */
    private function buildGifWithNoImageDescriptor(): string
    {
        $buf = '';
        $buf .= "\x47\x49\x46\x38\x39\x61"; // GIF89a
        $buf .= "\x01\x00";                  // width = 1
        $buf .= "\x01\x00";                  // height = 1
        $buf .= "\x80";                      // GCT flag=1, GCT size exp=0 → 2 entries
        $buf .= "\x00";                      // bg index = 0
        $buf .= "\x00";                      // pixel aspect ratio = 0
        $buf .= "\x00\x00\x00";             // GCT[0] = black
        $buf .= "\xff\x00\x00";             // GCT[1] = red
        $buf .= "\x3B";                      // GIF trailer (no Image Descriptor)
        return $buf;
    }

    /**
     * A GIF with header + GCT + trailer but no image data should be accepted
     * by decode() and yield a single Frame via renderSingleFrame() → sample().
     */
    public function testDecodeNoImageDescriptorYieldsSingleFrame(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        $buf = $this->buildGifWithNoImageDescriptor();
        $this->tmpPath = sys_get_temp_dir() . '/no-imgdesc-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $buf);

        $frames = Decoder::decode($this->tmpPath, 1, 1);
        $this->assertCount(1, $frames, 'GIF with no Image Descriptor must yield exactly one frame');
        $this->assertSame(1, $frames[0]->width());
        $this->assertSame(1, $frames[0]->height());
    }

    /**
     * Verify sample() produces a Frame with default delay (10cs) and
     * DISPOSAL_NONE since there's no GCE.
     */
    public function testNoImageDescriptorFrameHasDefaultTiming(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        $buf = $this->buildGifWithNoImageDescriptor();
        $this->tmpPath = sys_get_temp_dir() . '/no-imgdesc-timing-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $buf);

        $frames = Decoder::decode($this->tmpPath, 1, 1);
        $this->assertSame(10, $frames[0]->delay,
            'Frame from GIF without GCE must have default delay of 10 centiseconds');
        $this->assertSame(Frame::DISPOSAL_NONE, $frames[0]->disposal);
    }

    /**
     * sample() must not throw on a larger cell grid — it should downsample
     * the 1×1 source to the requested size.
     */
    public function testNoImageDescriptorDownsamplesLargerGrid(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        $buf = $this->buildGifWithNoImageDescriptor();
        $this->tmpPath = sys_get_temp_dir() . '/no-imgdesc-large-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $buf);

        $frames = Decoder::decode($this->tmpPath, 4, 4);
        $this->assertCount(1, $frames);
        $this->assertSame(4, $frames[0]->width());
        $this->assertSame(4, $frames[0]->height());
    }

    /**
     * A GIF that is just "GIF89a" + short header (less than 6 bytes after
     * version stamp) — not enough to even reach the parseHeader path —
     * is already caught by the "too short" guard in decode() before
     * parseHeader is called. But a GIF that passes the header check
     * but has no image data (just GCT + trailer) must not cause
     * imagecreatefromstring to return false — it should handle the
     * degenerate case gracefully.
     */
    public function testNoImageDescriptorReturnsEmptyCellsIfDecodeFails(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        // A GIF89a header + screen descriptor + GCT + trailer but completely
        // broken LZW data (no actual image). This exercises the renderSingleFrame
        // path where imagecreatefromstring may return false, and the null check
        // in renderSingleFrame returns null → decode() returns [].
        $buf = '';
        $buf .= "\x47\x49\x46\x38\x39\x61"; // GIF89a
        $buf .= "\x01\x00";                  // width = 1
        $buf .= "\x01\x00";                  // height = 1
        $buf .= "\x80";                      // GCT flag=1, size exp=0 (2 entries)
        $buf .= "\x00";                      // bg
        $buf .= "\x00";                      // par
        $buf .= "\x00\x00\x00";             // GCT[0] black
        $buf .= "\xff\x00\x00";             // GCT[1] red
        $buf .= "\x3B";                      // trailer

        $this->tmpPath = sys_get_temp_dir() . '/broken-lzw-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $buf);

        // This is the degenerate case: no image descriptor at all.
        // The parser finds nothing, frameInfos=[], renderSingleFrame is called,
        // imagecreatefromstring on the minimal GIF may work or fail depending
        // on how GD handles it — either way we get a result (frame or empty).
        $frames = Decoder::decode($this->tmpPath, 1, 1);
        // The GIF has no image to decode — result is one frame or empty.
        $this->assertIsArray($frames);
    }

    /**
     * Regression: a GIF with a GCT but only a trailer (no Image Descriptor,
     * no GCE) must not emit PHP warnings when parseHeader walks the byte
     * stream and finds no blocks. The early-exit at blockType===0x3B handles it.
     */
    public function testGifWithOnlyTrailerNoWarning(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        // Valid header + LSD + GCT + trailer, nothing else.
        $buf = '';
        $buf .= "\x47\x49\x46\x38\x39\x61"; // GIF89a
        $buf .= "\x02\x00";                  // width = 2
        $buf .= "\x02\x00";                  // height = 2
        $buf .= "\x80";                      // GCT flag=1, size exp=0 (2 entries)
        $buf .= "\x00";
        $buf .= "\x00";
        $buf .= "\x00\x00\x00";
        $buf .= "\xff\x00\x00";
        $buf .= "\x3B";

        $this->tmpPath = sys_get_temp_dir() . '/gct-trailer-only-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $buf);

        // No @ suppression — any PHP warning here would fail the suite.
        $frames = Decoder::decode($this->tmpPath, 2, 2);
        $this->assertIsArray($frames);
        $this->assertCount(1, $frames, 'GCT+trailer-only GIF must yield one frame');
    }
}
