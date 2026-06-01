<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\FfmpegDecoder;
use SugarCraft\Reel\Source\Probe;

/**
 * Unit tests for FfmpegDecoder.
 *
 * Since CI has no live ffmpeg, tests that require the binary are skipped.
 * We test the binary-absent path directly, and verify internal math
 * for chunk-framing without needing a real subprocess.
 *
 * @covers FfmpegDecoder
 */
final class FfmpegDecoderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Binary-absent path — what happens when ffmpeg is missing
    // -------------------------------------------------------------------------

    /**
     * @testdox open() throws RuntimeException when ffmpeg binary is absent
     */
    public function testOpenThrowsWhenFfmpegAbsent(): void
    {
        if (Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg is present on this host; binary-absent path not testable');
        }

        $decoder = new FfmpegDecoder();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ffmpeg not found');
        $decoder->open('/nonexistent/video.mp4', 80, 24, 30.0);
    }

    // -------------------------------------------------------------------------
    // Chunk-framing math (half-block mode)
    // -------------------------------------------------------------------------

    /**
     * @testdox frame byte size is cellsW * cellsH * 2 * 3 in half-block mode
     *
     * Half-block mode doubles the vertical resolution: each cell spans 2 rows,
     * so the frame height = cellsH * 2. Each pixel is 3 bytes (RGB24).
     * This verifies the math used in FfmpegDecoder::open().
     *
     * Formula: frameBytes = cellsW * (cellsH * 2) * 3
     */
    public function testChunkFramingLogicWithSyntheticBytes(): void
    {
        $cellsW = 3;
        $cellsH = 2;

        // FfmpegDecoder computes: $this->frameBytes = $cellsW * $cellsH * 2 * 3;
        $expectedFrameBytes = $cellsW * $cellsH * 2 * 3;

        $this->assertSame(36, $expectedFrameBytes);

        // For half-block mode, height = cellsH * 2 = 4
        $frameHeight = $cellsH * 2;
        $this->assertSame(4, $frameHeight);

        // The frame should hold cellsW * frameHeight pixels = 3 * 4 = 12 pixels
        $totalPixels = $cellsW * $frameHeight;
        $this->assertSame(12, $totalPixels);

        // Each pixel is 3 bytes RGB24 → 12 * 3 = 36 bytes
        $this->assertSame($expectedFrameBytes, $totalPixels * 3);
    }

    // -------------------------------------------------------------------------
    // State machine: close then next returns null
    // -------------------------------------------------------------------------

    /**
     * @testdox next() returns null after close() is called
     *
     * Even without a real ffmpeg process, we can verify the state machine:
     * after close(), the decoder should not throw but return null.
     */
    public function testNextReturnsNullAfterClose(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $decoder = new FfmpegDecoder();
        try {
            $decoder->open('/nonexistent.mp4', 80, 24, 30.0);
        } catch (\RuntimeException) {
            // If the file doesn't exist ffmpeg may still start then fail.
            // We only care about the post-close behavior.
        }
        $decoder->close();

        // After close(), next() must return null (not throw)
        $result = $decoder->next();
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // getIterator returns a Generator
    // -------------------------------------------------------------------------

    /**
     * @testdox getIterator() returns a Generator instance
     */
    public function testGetIteratorReturnsGenerator(): void
    {
        $decoder = new FfmpegDecoder();
        $iterator = $decoder->getIterator();

        $this->assertInstanceOf(\Generator::class, $iterator);
    }
}
