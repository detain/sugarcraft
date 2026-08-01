<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Decoder;
use SugarCraft\Flip\Frame;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the renderSingleFrame() → sample() code path in Decoder.
 * This path is taken when a valid GIF has a header and color table
 * but no Image Descriptors in the byte stream.
 *
 * Since imagecreatefromstring() requires valid LZW data, such "empty"
 * GIFs yield 0 frames — we test that this happens gracefully without
 * PHP warnings or crashes.
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
     * Build a minimal GIF89a with header + LSD + GCT + trailer but
     * NO Image Descriptor. When parsed, frameInfos stays empty and
     * decode() calls renderSingleFrame(), which fails to create an
     * image from the non-existent LZW data → 0 frames returned.
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
     * A GIF with a valid header and Image Descriptor but corrupted LZW
     * data — imagecreatefromstring returns false and decodeFrameImage returns
     * null, so the multi-frame loop skips that frame → 0 frames total.
     */
    public function testDecodeWithBrokenLzwDataYieldsEmptyFrames(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        $buf = '';
        $buf .= "\x47\x49\x46\x38\x39\x61"; // GIF89a
        $buf .= "\x01\x00";                  // width = 1
        $buf .= "\x01\x00";                  // height = 1
        $buf .= "\x80";                      // GCT flag=1, size exp=0
        $buf .= "\x00";
        $buf .= "\x00";
        $buf .= "\x00\x00\x00";             // GCT[0] black
        $buf .= "\xff\x00\x00";             // GCT[1] red
        // Image Descriptor at offset ~20 (after header+LSD+GCT).
        $buf .= "\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00"; // Image Descriptor
        // LZW min code = 2, but "image data" is just the trailer (invalid).
        $buf .= "\x02\x3b";

        $this->tmpPath = sys_get_temp_dir() . '/broken-lzw-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $buf);

        $frames = @Decoder::decode($this->tmpPath, 1, 1);
        $this->assertIsArray($frames);
        $this->assertCount(0, $frames,
            'GIF with broken LZW data must yield 0 frames');
    }
}
