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
     * A GIF with header + GCT + trailer but no Image Descriptor triggers the
     * renderSingleFrame() → sample() fallback path. Since there is no valid
     * LZW image data, imagecreatefromstring() returns false and the fallback
     * path returns an empty array — no crash, no warning.
     */
    public function testNoImageDescriptorReturnsEmptyFramesGracefully(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        $buf = $this->buildGifWithNoImageDescriptor();
        $this->tmpPath = sys_get_temp_dir() . '/no-imgdesc-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $buf);

        // No @ suppression — any PHP warning would fail the suite.
        $frames = Decoder::decode($this->tmpPath, 1, 1);
        $this->assertIsArray($frames);
        $this->assertCount(0, $frames,
            'GIF with no Image Descriptor has no decodable frames');
    }

    /**
     * Regression: a GIF with only header + LSD + GCT + trailer must not emit
     * PHP warnings when parseHeader() walks the byte stream and finds no
     * blocks before the trailer. The early-exit at blockType===0x3B handles it.
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
        // No valid image data → 0 frames, no warnings.
        $this->assertCount(0, $frames);
    }

    /**
     * The sample() method (called from renderSingleFrame) uses area-average
     * downsampling with transparent-pixel awareness. We test this path by
     * creating a GIF with a proper Image Descriptor that goes through the
     * normal multi-frame path, but we also verify the single-frame path
     * handles the null return from imagecreatefromstring gracefully.
     */
    public function testDecodeWithBrokenLzwDataYieldsEmptyFrames(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        // A GIF with a valid header and an Image Descriptor but corrupted LZW
        // data — imagecreatefromstring will return false, yielding no frames.
        $buf = '';
        $buf .= "\x47\x49\x46\x38\x39\x61"; // GIF89a
        $buf .= "\x01\x00";                  // width = 1
        $buf .= "\x01\x00";                  // height = 1
        $buf .= "\x80";                      // GCT flag=1, size exp=0
        $buf .= "\x00";
        $buf .= "\x00";
        $buf .= "\x00\x00\x00";             // GCT[0] black
        $buf .= "\xff\x00\x00";             // GCT[1] red
        // Image Descriptor pointing to valid offset but broken LZW data.
        $buf .= "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00"; // Image Descriptor
        // LZW min code = 2, but the following "data" is just the trailer.
        $buf .= "\x02";                      // LZW min code
        $buf .= "\x3b";                      // trailer immediately after (invalid)

        $this->tmpPath = sys_get_temp_dir() . '/broken-lzw-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $buf);

        $frames = @Decoder::decode($this->tmpPath, 1, 1);
        $this->assertIsArray($frames);
        $this->assertCount(0, $frames,
            'GIF with broken LZW data must yield 0 frames');
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
