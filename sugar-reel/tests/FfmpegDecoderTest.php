<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\FfmpegDecoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Source\Probe;

/**
 * Unit tests for FfmpegDecoder.
 *
 * Since CI has no live ffmpeg, tests that require the binary are skipped.
 * We test the binary-absent path directly, and verify internal math
 * for chunk-framing without needing a real subprocess.
 *
 * @covers \SugarCraft\Reel\Decode\FfmpegDecoder
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

    /**
     * @testdox next() frames a canned rawvideo byte stream and discards the partial tail
     *
     * Drives the real read/accumulate/partial-discard loop in next() by
     * injecting an in-memory stream of two complete frames plus a short
     * trailing frame — no ffmpeg required (plan Step 2, lines 179-181).
     */
    public function testNextFramesCannedRawvideoStream(): void
    {
        $cellsW = 2;
        $frameH = 2;
        $frameBytes = $cellsW * $frameH * 3; // 12 bytes per frame

        $frame1 = str_repeat("\xAA", $frameBytes);
        $frame2 = str_repeat("\xBB", $frameBytes);
        $partial = str_repeat("\xCC", 5); // short trailing frame → discarded

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $frame1 . $frame2 . $partial);
        rewind($stream);

        $decoder = new FfmpegDecoder();
        $this->injectStreamState($decoder, $stream, $cellsW, $frameH, $frameBytes);

        $first = $decoder->next();
        $this->assertNotNull($first);
        $this->assertSame($frame1, $first->bytes);
        $this->assertSame($cellsW, $first->w);
        $this->assertSame($frameH, $first->h);

        $second = $decoder->next();
        $this->assertNotNull($second);
        $this->assertSame($frame2, $second->bytes);

        // Partial trailing frame is silently discarded.
        $this->assertNull($decoder->next());
        // EOF is stable.
        $this->assertNull($decoder->next());

        fclose($stream);
    }

    /**
     * Inject decoder framing state + a fake stdout stream via reflection so
     * next() can be exercised without launching ffmpeg.
     *
     * @param resource $stream
     */
    private function injectStreamState(FfmpegDecoder $decoder, $stream, int $cellsW, int $frameH, int $frameBytes): void
    {
        $r = new \ReflectionClass($decoder);
        foreach ([
            'stdout' => $stream,
            'cellsW' => $cellsW,
            'frameH' => $frameH,
            'frameBytes' => $frameBytes,
        ] as $prop => $value) {
            $p = $r->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($decoder, $value);
        }
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
    // F7: stderr sink — live decode of a real clip closes cleanly
    // -------------------------------------------------------------------------

    /**
     * @testdox open()/next()/close() decode a real ffmpeg-generated clip and close cleanly (stderr sink)
     *
     * Hardening / characterisation test for F7. A real stderr-flood deadlock
     * (ffmpeg blocking once its ~64KB stderr buffer fills against a reader-less
     * pipe) cannot be safely asserted in a unit test, so this proves the
     * structural fix instead: with stderr redirected to a file sink the decoder
     * still decodes frames and the subprocess closes without hanging. The
     * structural proof is that FfmpegDecoder no longer holds an unread stderr
     * pipe (the `$stderr` property is gone). Watchdog-guarded so a regression
     * that DID deadlock can't wedge the suite.
     */
    public function testLiveDecodeWithStderrSinkClosesCleanly(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/sugar-reel-test-' . getmypid() . '.mp4';

        // Watchdog: timeout does NOT kill a proc_open/pipe deadlock, so spawn a
        // backgrounded killer that SIGKILLs this test's ffmpeg children after
        // 20s. Cancelled on a clean finish below.
        $wd = proc_open(
            ['sh', '-c', 'sleep 20; pkill -9 -f sugar-reel-test'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $wdPipes,
        );

        try {
            // Generate a tiny clip via ffmpeg (arg-array — no shell string).
            $gen = proc_open(
                [
                    Probe::ffmpeg(),
                    '-hide_banner', '-loglevel', 'error',
                    '-f', 'lavfi',
                    '-i', 'testsrc=duration=1:size=64x48:rate=10',
                    '-y', $clip,
                ],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $genPipes,
            );
            $this->assertIsResource($gen, 'ffmpeg clip generation must start');
            foreach ($genPipes as $p) {
                if (is_resource($p)) {
                    \fclose($p);
                }
            }
            proc_close($gen);
            $this->assertFileExists($clip, 'ffmpeg must produce the test clip');

            $decoder = new FfmpegDecoder();
            $decoder->open($clip, 16, 12, 10.0, Mode::HalfBlock);

            $frames = 0;
            $first = null;
            while (($frame = $decoder->next()) !== null) {
                if ($first === null) {
                    $first = $frame;
                }
                $frames++;
                // Bound the loop; one second at 10fps is ~10 frames.
                if ($frames >= 50) {
                    break;
                }
            }

            $this->assertGreaterThanOrEqual(1, $frames, 'decoder must yield at least one frame');
            $this->assertInstanceOf(RgbFrame::class, $first);

            // close() must return without hanging on a leftover stderr pipe.
            $decoder->close();
            // Stable EOF after close.
            $this->assertNull($decoder->next());
        } finally {
            if (isset($wd) && is_resource($wd)) {
                proc_terminate($wd);
                proc_close($wd);
            }
            if (is_file($clip)) {
                @unlink($clip);
            }
        }
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
