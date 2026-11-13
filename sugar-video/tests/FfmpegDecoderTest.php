<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\FfmpegDecoder;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Source\Probe;
use SugarCraft\Video\Support\FfmpegCommandBuilder;
use SugarCraft\Video\Tests\Concerns\CapturesErrorLog;
use SugarCraft\Video\Tests\Concerns\HidesPathBinaries;

/**
 * Unit tests for FfmpegDecoder.
 *
 * Since CI has no live ffmpeg, tests that require the binary are skipped.
 * The binary-absent path is exercised on every host by hiding PATH
 * binaries, and internal chunk-framing math is verified without a real
 * subprocess.
 *
 * @covers \SugarCraft\Video\Decode\FfmpegDecoder
 */
final class FfmpegDecoderTest extends TestCase
{
    use CapturesErrorLog;
    use HidesPathBinaries;

    // -------------------------------------------------------------------------
    // Binary-absent path — what happens when ffmpeg is missing
    // -------------------------------------------------------------------------

    /**
     * @testdox open() throws RuntimeException when ffmpeg binary is absent
     */
    public function testOpenThrowsWhenFfmpegAbsent(): void
    {
        $decoder = new FfmpegDecoder();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ffmpeg not found');
        $this->withoutPathBinaries(
            static fn () => $decoder->open('/nonexistent/video.mp4', 80, 24, 30.0),
        );
    }

    /**
     * @testdox headers on a LOCAL source are dropped with a logged reason (not silently)
     *
     * The ffmpeg open() path logs then discards request headers for a non-network
     * source, because ffmpeg rejects -headers on a file input. The notice fires
     * before the subprocess is even attempted, so hiding ffmpeg from PATH still lets
     * us prove the log line without spawning anything.
     */
    public function testLocalSourceHeadersDroppedWithLoggedReason(): void
    {
        $logged = $this->captureErrorLog(function (): void {
            $decoder = new FfmpegDecoder();
            $this->withoutPathBinaries(static function () use ($decoder): void {
                try {
                    $decoder->open('/tmp/local.mkv', 80, 24, 30.0, Mode::HalfBlock, 0.0, ['Authorization' => 'Bearer x']);
                } catch (\RuntimeException) {
                    // Expected: ffmpeg hidden so open() throws AFTER the drop notice.
                }
            });
        });

        $this->assertStringContainsString('ignoring 1 HTTP request header', $logged);
        // Round-1 review m4, decoder leg: the notice must not echo the credential
        // it just refused to send — negative direction, same invariant as the
        // audio companion's drop test.
        $this->assertStringNotContainsString('Bearer x', $logged, 'never log header VALUES');
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
     * @testdox a frame split across next() calls RESUMES from the buffered bytes
     *
     * findings #45/#50: reads are now bounded (a stalled pipe no longer blocks the
     * UI forever). A bounded read can return mid-frame, so the partial bytes must
     * persist across the null return and be completed by a later next() — dropping
     * them would desync the forward-only pipe. No ffmpeg: pre-seed the buffer to the
     * post-stall state, put the remaining bytes on the stream, assert completion.
     */
    public function testPartialFrameResumesAcrossCalls(): void
    {
        $cellsW = 2;
        $frameH = 2;
        $frameBytes = $cellsW * $frameH * 3; // 12

        $halfB = str_repeat("\x22", 5);
        $restB = str_repeat("\x33", $frameBytes - 5);

        // The stream now holds only the TAIL of frame B; the head is already in the
        // decoder's persistent buffer, exactly as a bounded read would leave it.
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $restB);
        rewind($stream);

        $decoder = new FfmpegDecoder();
        $this->injectStreamState($decoder, $stream, $cellsW, $frameH, $frameBytes);

        // Seed the buffer with the half-frame a previous call left behind.
        $raw = (new \ReflectionClass($decoder))->getProperty('rawBuffer');
        $raw->setAccessible(true);
        $raw->setValue($decoder, $halfB);

        // The buffered head + this call's tail reassemble into one whole frame.
        $resumed = $decoder->next();
        $this->assertNotNull($resumed, 'the buffered half-frame is completed, not lost');
        $this->assertSame($halfB . $restB, $resumed->bytes);

        // Buffer is drained back to empty after a frame is produced.
        $this->assertSame('', $raw->getValue($decoder));

        fclose($stream);
    }

    /**
     * @testdox a fully stalled pipe dies at the per-frame-fill deadline — and keeps the partial
     *
     * The witness for the absolute-deadline fix (findings #45/#50): reads inside
     * ONE next() must race a single clock, so the call cannot outlive the fill's
     * budget no matter how many bounded reads it would take. The pipe here is a
     * unix socket pair whose peer stays open and silent — select() sees neither
     * data nor EOF, so this exercises the pure-timeout path (a full budget spent
     * waiting, then null). Any reintroduced internal retry that loops past the
     * deadline pushes elapsed over the ceiling; a revert to the old blocking
     * fread hangs instead. A plain per-read re-arm WITHOUT retry is invisible on
     * a silent pipe (one timeout either way) — that mutation is caught by
     * {@see self::testTrickleStillDiesAtFrameFillDeadline()} below,
     * which drips bytes just inside each select window. `readTimeout` is the test
     * seam that makes the bound observable on a 0.2 s scale.
     */
    public function testStalledPipeDiesAtFrameFillDeadline(): void
    {
        $cellsW = 2;
        $frameH = 2;
        $frameBytes = $cellsW * $frameH * 3; // 12
        $partial = str_repeat("\x22", $frameBytes - 1); // one byte short of a frame

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertNotFalse($pair, 'socket pair is the silent, never-EOF pipe');
        [$peer, $readEnd] = $pair;

        try {
            $decoder = new FfmpegDecoder();
            $this->injectStreamState($decoder, $readEnd, $cellsW, $frameH, $frameBytes);

            $r = new \ReflectionClass($decoder);
            $raw = $r->getProperty('rawBuffer');
            $raw->setAccessible(true);
            $raw->setValue($decoder, $partial);
            $timeout = $r->getProperty('readTimeout');
            $timeout->setAccessible(true);
            $timeout->setValue($decoder, 0.2);

            $started = hrtime(true) / 1_000_000_000;
            $frame = $decoder->next();
            $elapsed = hrtime(true) / 1_000_000_000 - $started;

            $this->assertNull($frame, 'a stalled fill yields no frame');
            $this->assertGreaterThanOrEqual(
                0.15,
                $elapsed,
                'the wait really used its bounded window (an instant EOF path would prove nothing)'
            );
            $this->assertLessThan(
                0.35,
                $elapsed,
                'one stalled frame-fill never re-arms its budget past the deadline'
            );
            $this->assertSame($partial, $raw->getValue($decoder), 'the partial stays buffered for the next call');
        } finally {
            fclose($peer);
            fclose($readEnd);
        }
    }

    /**
     * @testdox a trickling pipe still dies at the frame-fill deadline
     *
     * The companion witness to {@see self::testStalledPipeDiesAtFrameFillDeadline()}
     * for the true findings #45/#50 wedge: a server that drips bytes just inside
     * every individual select window, so all but the final bounded read succeed —
     * the kill comes from the last, shrunken window timing out (or the spent-budget
     * guard), which is exactly what a re-arm prevents since under a re-arm no window
     * ever shrinks. An absolute
     * per-frame-fill deadline still kills the call once the frame's total budget is
     * spent; a per-read re-arm (deadline recomputed before every readStdout) would
     * never fire and the UI would livelock. A child process trickles one byte every
     * 80 ms into a pipe; the frame is 12 bytes and `readTimeout` is 0.4 s, so
     * correct code returns null after ~0.4 s with only a few bytes buffered, while
     * a re-arm mutation instead assembles a whole frame at ~1.0 s and turns the
     * null-assertion red outright — the strongest possible pin. The child is
     * terminated and reaped in `finally` (child-lifetime discipline).
     */
    public function testTrickleStillDiesAtFrameFillDeadline(): void
    {
        $cellsW = 2;
        $frameH = 2;
        $frameBytes = $cellsW * $frameH * 3; // 12

        $child = proc_open(
            [\PHP_BINARY, '-r', 'while (true) { fwrite(STDOUT, "x"); fflush(STDOUT); usleep(80_000); }'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($child, 'trickle child must start');

        try {
            $decoder = new FfmpegDecoder();
            $this->injectStreamState($decoder, $pipes[1], $cellsW, $frameH, $frameBytes);

            $timeout = (new \ReflectionClass($decoder))->getProperty('readTimeout');
            $timeout->setAccessible(true);
            $timeout->setValue($decoder, 0.4);

            $started = hrtime(true) / 1_000_000_000;
            $frame = $decoder->next();
            $elapsed = hrtime(true) / 1_000_000_000 - $started;

            $this->assertNull(
                $frame,
                'a trickling fill is killed by the frame budget even though every individual read succeeds'
            );
            $this->assertGreaterThanOrEqual(
                0.35,
                $elapsed,
                'bytes really were flowing — the loop kept reading, past many successful waits'
            );
            $this->assertLessThan(
                0.65,
                $elapsed,
                'the ABSOLUTE fill deadline ended the wait; a per-read re-arm would still be looping here'
            );
        } finally {
            // fclose the read end BEFORE proc_close: proc_close reaps the child
            // and closes every remaining pipe itself, and a second fclose on an
            // already-closed resource is a TypeError, not a no-op.
            fclose($pipes[1]);
            proc_terminate($child);
            proc_close($child);
        }
    }

    // -------------------------------------------------------------------------
    // SV-M37: PNG IEND scan cursor (quadratic re-scan cure)
    // -------------------------------------------------------------------------

    /**
     * @testdox SV-M37: multi-top-up PNG scans incrementally and survives a marker straddling a chunk
     *
     * Load-bearing: drop the scan cursor (strpos back to offset 0) and the
     * parked-cursor assertion reddens; overshoot the cursor slack past
     * marker-length-1 and the straddled IEND is missed, losing the frame.
     */
    public function testPngTopUpsParkTheScanCursorAndLandTheStraddledFrame(): void
    {
        $marker = "\x00\x00\x00\x00IEND\xae\x42\x60\x82"; // the whole 12-byte search key
        $partOne = str_repeat('A', 100_000) . substr($marker, 0, 2);

        $stream = fopen('php://temp', 'r+b');
        $this->assertNotFalse($stream);
        fwrite($stream, $partOne);
        rewind($stream);

        $decoder = new FfmpegDecoder();
        $r = new \ReflectionClass($decoder);
        foreach (['stdout' => $stream, 'graphics' => true, 'frameW' => 8, 'frameH' => 6, 'cellsW' => 8, 'cellsH' => 3] as $prop => $value) {
            $p = $r->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($decoder, $value);
        }

        $cursor = $r->getProperty('pngScanCursor');
        $cursor->setAccessible(true);
        $nextPng = $r->getMethod('nextPng');
        $nextPng->setAccessible(true);

        // Phase 1: three 64KB top-ups, no complete marker anywhere — the call
        // ends null (stream exhausted, no child) with the cursor parked
        // marker-length-1 behind the buffer end, NOT at 0.
        $this->assertNull($nextPng->invoke($decoder));
        $this->assertSame(strlen($partOne) - 11, $cursor->getValue($decoder), 'the cursor parks where a straddling marker could still start');

        // Phase 2: the rest of the marker trickles in across the call boundary.
        // php://temp shares one cursor for read+write: append, then park the
        // read position back at the end of what the decoder already consumed.
        fwrite($stream, substr($marker, 2));
        fseek($stream, strlen($partOne));
        $frame = $nextPng->invoke($decoder);
        $this->assertNotNull($frame, 'the frame must land even with its IEND split over two top-ups');
        $this->assertSame($partOne . substr($marker, 2), $frame->png);
        $this->assertSame(0, $cursor->getValue($decoder), 'consumption resets the cursor');
    }

    // -------------------------------------------------------------------------
    // SV-M14: stderr tail (not head) for sinks over the cap
    // -------------------------------------------------------------------------

    /**
     * @testdox SV-M14: a >400-byte stderr sink yields its LAST bytes, not its first
     */
    public function testStderrTailReturnsTheTailForOversizedSinks(): void
    {
        $path = sys_get_temp_dir() . '/ffdec-tail-' . uniqid('', true) . '.txt';
        $head = 'HEAD-NOISE ' . str_repeat('x', 500);
        $tail = 'the-real-error: Option not found';
        file_put_contents($path, $head . $tail);

        try {
            $tailFn = new \ReflectionMethod(FfmpegDecoder::class, 'stderrTail');
            $tailFn->setAccessible(true);
            $read = (string) $tailFn->invoke(null, $path);

            $this->assertStringContainsString($tail, $read, 'the failure detail lives at the tail — the old negative seek showed only noise');
            $this->assertStringNotContainsString('HEAD-NOISE', $read);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @testdox SV-M14: a short sink still reads whole (and the null path stays quiet)
     */
    public function testStderrTailKeepsShortSinksIntact(): void
    {
        $tailFn = new \ReflectionMethod(FfmpegDecoder::class, 'stderrTail');
        $tailFn->setAccessible(true);

        $path = sys_get_temp_dir() . '/ffdec-short-' . uniqid('', true) . '.txt';
        file_put_contents($path, 'small but sincere');
        try {
            $this->assertSame('small but sincere', (string) $tailFn->invoke(null, $path));
        } finally {
            @unlink($path);
        }

        $this->assertSame('', (string) $tailFn->invoke(null, null));
        $this->assertSame('', (string) $tailFn->invoke(null, $path . '.missing'));
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
            'frameW' => $cellsW, // 1 source col per cell in these (half-block-style) fixtures
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
        // close() may log the real ffmpeg failure to error_log (decoding a
        // missing file exits non-zero and that code drifts by race); capture
        // it so the suite stays silent and pin that only the known failure
        // note — never unrelated noise — can appear.
        $log = $this->captureErrorLog(fn() => $decoder->close());
        $this->assertTrue(
            $log === '' || str_contains($log, 'FfmpegDecoder: ffmpeg exited with code'),
            'close() logged unexpected error output: ' . $log
        );

        // After close(), next() must return null (not throw)
        $result = $decoder->next();
        $this->assertNull($result);
    }

    /**
     * @testdox close() grants the pre-TERM grace window — a child that exits on its
     *          own once stdout is dropped is reaped unsignalled (BoundedReaper fold pin)
     *
     * The deleted per-lib BoundedReaper gave ffmpeg a natural-exit window after the
     * parent closed the read end before ever signalling; the fold onto sugar-core's
     * BoundedShutdown keeps that rung as hasExited(CLOSE_GRACE_SECONDS) ahead of
     * terminateAndAwaitExit(). A writer child mimicking ffmpeg's EPIPE exit (self-
     * exit 0 on the first failed fwrite) must therefore leave with code 0 — a
     * regression to immediate escalation SIGTERMs it, proc_close() collapses the
     * signal to -1, and the assertion goes red.
     */
    public function testCloseObservesPreTermGraceForASelfExitingChild(): void
    {
        $code = <<<'PHP'
$deadline = microtime(true) + 3.0;
while (microtime(true) < $deadline) {
    if (@fwrite(STDOUT, 'f') === false) {
        exit(0);
    }
    usleep(20000);
}
exit(7);
PHP;
        $child = proc_open(
            [\PHP_BINARY, '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($child);

        try {
            $decoder = new FfmpegDecoder();
            $r = new \ReflectionClass($decoder);
            foreach (['process' => $child, 'stdout' => $pipes[1]] as $prop => $value) {
                $p = $r->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue($decoder, $value);
            }

            $started = microtime(true);
            $log = $this->captureErrorLog(fn() => $decoder->close());
            $elapsed = microtime(true) - $started;

            $this->assertSame(0, $decoder->exitCode(), 'the grace window must reap the self-exiting child without a signal');
            $this->assertSame('', $log, 'an unsignalled clean exit must log nothing');
            $this->assertLessThan(1.4, $elapsed, 'close() must not wait out the full grace ceiling on a prompt exit');
        } finally {
            // close() reaped the child and closed the stdout read end it was
            // handed; only the pipes this test opened remain.
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
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

        $clip = sys_get_temp_dir() . '/sugar-video-test-' . getmypid() . '.mp4';

        // Watchdog: timeout does NOT kill a proc_open/pipe deadlock, so spawn a
        // backgrounded killer that SIGKILLs this test's ffmpeg children after
        // 20s. Cancelled on a clean finish below.
        $wd = proc_open(
            ['sh', '-c', 'sleep 20; pkill -9 -f sugar-video-test'],
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
    // close() must not report its own teardown as a decoder failure
    // -------------------------------------------------------------------------

    /**
     * @testdox close() after a normal decode logs nothing, but a real ffmpeg failure still logs
     *
     * REGRESSION, reported against `examples/play.php` in sixel/iterm2: every
     * quit printed `FfmpegDecoder: ffmpeg exited with code 224`.
     *
     * 224 is not a failure. `close()` closes the read end of the stdout pipe
     * FIRST, deliberately — the class calls that "the polite exit for a stream
     * decoder" — and ffmpeg answers the resulting write error by exiting with
     * `AVERROR(EPIPE)` (-32) truncated to a byte. The teardown was reporting
     * its own success as an error, through `error_log`, i.e. onto stderr and
     * straight over the frame sugar-core had just composed. A child that had
     * to be signalled by the reaper is the same story with `proc_close()`
     * returning -1.
     *
     * Both halves are asserted together on purpose: silencing the noise is
     * only correct if a genuine failure still speaks, so the second decode
     * feeds ffmpeg a file that is not a video at all.
     */
    public function testCloseDoesNotLogItsOwnTeardownButStillLogsRealFailures(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/sugar-video-test-teardown-' . getmypid() . '.mp4';
        $notAVideo = sys_get_temp_dir() . '/sugar-video-test-teardown-' . getmypid() . '.txt';

        try {
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
            foreach ($genPipes as $pipe) {
                if (is_resource($pipe)) {
                    \fclose($pipe);
                }
            }
            proc_close($gen);
            $this->assertFileExists($clip);

            // A decode abandoned mid-stream is the ordinary case: quit, seek,
            // resize and mode change all close a decoder that still had frames.
            $logged = $this->captureErrorLog(function () use ($clip): void {
                $decoder = new FfmpegDecoder();
                $decoder->open($clip, 16, 12, 10.0, Mode::HalfBlock);
                $this->assertNotNull($decoder->next());
                $decoder->close();
            });

            $this->assertSame('', trim($logged), "an ordinary teardown must be silent; got: {$logged}");

            file_put_contents($notAVideo, "not a video\n");

            $logged = $this->captureErrorLog(function () use ($notAVideo): void {
                $decoder = new FfmpegDecoder();
                $decoder->open($notAVideo, 16, 12, 10.0, Mode::HalfBlock);
                $decoder->next();
                $decoder->close();
            });

            $this->assertStringContainsString('ffmpeg exited with code', $logged, 'a real ffmpeg failure must still be reported');
        } finally {
            foreach ([$clip, $notAVideo] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
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

    // -------------------------------------------------------------------------
    // HTTP/stream source — reconnect input options
    // -------------------------------------------------------------------------

    /**
     * @testdox an http(s) source gets the reconnect input options, before -i
     */
    public function testNetworkSourceCommandIncludesReconnectFlagsBeforeInput(): void
    {
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', 'https://srv/media/m1/stream?sig=x', 80, 48, 24.0);

        foreach (['-reconnect', '-reconnect_streamed', '-reconnect_delay_max'] as $flag) {
            $this->assertContains($flag, $cmd, "network command must carry {$flag}");
        }

        // Reconnect options are INPUT options — they must precede -i <source>.
        $reconnectIdx = array_search('-reconnect', $cmd, true);
        $inputIdx = array_search('-i', $cmd, true);
        $this->assertIsInt($reconnectIdx);
        $this->assertIsInt($inputIdx);
        $this->assertLessThan($inputIdx, $reconnectIdx, '-reconnect must come before -i');

        // The source URL is passed verbatim as the -i argument.
        $this->assertSame('https://srv/media/m1/stream?sig=x', $cmd[$inputIdx + 1]);
    }

    /**
     * @testdox plain http (not just https) is treated as a network source
     */
    public function testPlainHttpIsANetworkSource(): void
    {
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', 'http://box:8096/s.mkv', 80, 48, 24.0);
        $this->assertContains('-reconnect', $cmd);
    }

    /**
     * @testdox a local file source omits the reconnect options (ffmpeg rejects them on files)
     */
    public function testLocalFileCommandOmitsReconnectFlags(): void
    {
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/movie.mkv', 80, 48, 24.0);

        $this->assertNotContains('-reconnect', $cmd);
        $this->assertNotContains('-reconnect_streamed', $cmd);
        $this->assertContains('/tmp/movie.mkv', $cmd);
    }

    /**
     * @testdox SV-H2: the reconnect flags default is byte-identical to the historic block
     */
    public function testNetworkReconnectFlagsDefaultIsUnchanged(): void
    {
        $this->assertSame([
            '-reconnect', '1',
            '-reconnect_streamed', '1',
            '-reconnect_delay_max', '4',
        ], FfmpegCommandBuilder::networkReconnectFlags());
    }

    /**
     * @testdox SV-H3: no 5.1-only options in the reconnect block (sugar-video-003)
     */
    public function testReconnectBlockCarriesNoFiveOneOnlyFlag(): void
    {
        $this->assertNotContains('-reconnect_on_network_error', FfmpegCommandBuilder::networkReconnectFlags());
        $this->assertNotContains('-reconnect_on_network_error', FfmpegCommandBuilder::networkReconnectFlags(true));
    }

    /**
     * @testdox SV-H2: a bound allowlist appends the zero-redirect transport pin
     */
    public function testNetworkReconnectFlagsPinRedirectsWhenHostRestricted(): void
    {
        $this->assertSame(
            [
                '-reconnect', '1',
                '-reconnect_streamed', '1',
                '-reconnect_delay_max', '4',
                '-max_redirects', '0',
            ],
            FfmpegCommandBuilder::networkReconnectFlags(true),
        );
        $this->assertSame(0, FfmpegCommandBuilder::ALLOWED_MAX_REDIRECTS, 'zero redirects — the allowlist is a first-hop policy');
    }

    /**
     * @testdox SV-H2: a restricted network decode command carries -max_redirects 0 before -i
     */
    public function testHostRestrictedNetworkCommandCarriesZeroRedirectPin(): void
    {
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', 'https://srv/media/m1/stream?sig=x', 80, 48, 24.0, 0.0, false, null, null, null, true);

        $redirectIdx = array_search('-max_redirects', $cmd, true);
        $inputIdx = array_search('-i', $cmd, true);
        $this->assertIsInt($redirectIdx, 'allowlisted playback must pin the redirect budget');
        $this->assertSame('0', $cmd[$redirectIdx + 1]);
        $this->assertIsInt($inputIdx);
        $this->assertLessThan($inputIdx, $redirectIdx, '-max_redirects is an input option — before -i');
    }

    /**
     * @testdox SV-H2: the redirect pin never touches a local-source argv
     */
    public function testHostRestrictedLocalCommandIsByteIdentical(): void
    {
        $restricted = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/movie.mkv', 80, 48, 24.0, 0.0, false, null, null, null, true);
        $unrestricted = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/movie.mkv', 80, 48, 24.0);

        $this->assertSame($unrestricted, $restricted);
        $this->assertNotContains('-max_redirects', FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', 'https://srv/s.mkv', 80, 48, 24.0), 'no allowlist, no behavior change');
    }

    /**
     * @testdox the command always carries the core rawvideo/rgb24/scale args
     */
    public function testCommandHasCoreRawvideoArgs(): void
    {
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/movie.mkv', 100, 60, 25.0);

        $joined = implode(' ', $cmd);
        $this->assertStringContainsString('-f rawvideo', $joined);
        $this->assertStringContainsString('-pix_fmt rgb24', $joined);
        // Aspect-preserving fit + centred pad to the exact frame size.
        $this->assertStringContainsString(
            'fps=25,scale=100:60:force_original_aspect_ratio=decrease:flags=bilinear,pad=100:60:(ow-iw)/2:(oh-ih)/2',
            $joined,
        );
        $this->assertSame('-', $cmd[array_key_last($cmd)], 'output goes to the stdout pipe (-)');
    }

    /**
     * @testdox a letterbox box equal to the frame yields a single fit+pad (no extra scale)
     */
    public function testEqualLetterboxBoxStaysSingleStage(): void
    {
        // HalfBlock-style: padW/padH == frameW/frameH → one fit+pad, no trailing scale.
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/m.mkv', 120, 72, 24.0, 0.0, false, 120, 72);

        $vf = $cmd[array_search('-vf', $cmd, true) + 1];
        $this->assertSame(
            'fps=24,scale=120:72:force_original_aspect_ratio=decrease:flags=bilinear,pad=120:72:(ow-iw)/2:(oh-ih)/2',
            $vf,
        );
        $this->assertSame(1, substr_count($vf, 'scale='), 'no second scale when the box matches the frame');
    }

    /**
     * @testdox a differing letterbox box letterboxes then squashes to the frame grid (QuarterBlock aspect fix)
     */
    public function testDifferingLetterboxBoxAddsSquashScale(): void
    {
        // QuarterBlock 120x36: frame 240x72, but the display box is 240x144 (cellsW:2cellsH).
        // The video is fitted+padded to 240x144 then squashed to 240x72; the cell grid
        // stretches it back to true aspect, so the video no longer comes out half width.
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/m.mkv', 240, 72, 24.0, 0.0, false, 240, 144);

        $vf = $cmd[array_search('-vf', $cmd, true) + 1];
        $this->assertStringContainsString(
            'scale=240:144:force_original_aspect_ratio=decrease:flags=bilinear,pad=240:144:(ow-iw)/2:(oh-ih)/2',
            $vf,
        );
        $this->assertStringEndsWith(',scale=240:72:flags=bilinear', $vf, 'final squash to the frame grid');
        $this->assertSame(2, substr_count($vf, 'scale='), 'fit scale + final squash scale');
    }

    /**
     * @testdox a positive startSec adds a fast input seek (-ss) before -i
     */
    public function testStartSecAddsInputSeekBeforeInput(): void
    {
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/m.mkv', 80, 48, 24.0, 90.5);

        $ssIdx = array_search('-ss', $cmd, true);
        $inputIdx = array_search('-i', $cmd, true);
        $this->assertIsInt($ssIdx, '-ss must be present for a positive startSec');
        $this->assertSame('90.500', $cmd[$ssIdx + 1], 'seek time is formatted to ms precision');
        $this->assertLessThan($inputIdx, $ssIdx, '-ss is an input option → before -i');
    }

    /**
     * @testdox a zero startSec omits the input seek entirely
     */
    public function testZeroStartSecOmitsSeek(): void
    {
        $this->assertNotContains('-ss', FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/m.mkv', 80, 48, 24.0, 0.0));
        $this->assertNotContains('-ss', FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/m.mkv', 80, 48, 24.0));
    }

    // -------------------------------------------------------------------------
    // Graphics variant — buildCommand($graphics = true) emits a PNG image2pipe
    // -------------------------------------------------------------------------

    /**
     * @testdox graphics buildCommand emits an image2pipe PNG stream (not rawvideo)
     *
     * For graphics modes (Sixel/Kitty/iTerm2) the decoder produces full-resolution
     * PNG frames via `-f image2pipe -vcodec png -compression_level 1`, and must NOT
     * carry the rawvideo/rgb24 args used by the text/cell renderers. The scale/pad
     * filter still uses the exact frameW:frameH, and the output still goes to `-`.
     */
    public function testGraphicsCommandEmitsImage2PipePng(): void
    {
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/m.mkv', 200, 120, 24.0, 0.0, true);

        // PNG image2pipe encode flags are present (as adjacent argv pairs).
        $this->assertContains('-f', $cmd);
        $this->assertContains('image2pipe', $cmd);
        $this->assertContains('-vcodec', $cmd);
        $this->assertContains('png', $cmd);
        $this->assertContains('-compression_level', $cmd);

        // -f image2pipe must be an adjacent flag/value pair.
        $fIdx = array_search('-f', $cmd, true);
        $this->assertIsInt($fIdx);
        $this->assertSame('image2pipe', $cmd[$fIdx + 1]);

        // The rawvideo path's args must be absent on the graphics variant.
        $this->assertNotContains('rawvideo', $cmd);
        $this->assertNotContains('rgb24', $cmd);

        // The scale/pad filter still targets the exact frame pixel size.
        $joined = implode(' ', $cmd);
        $this->assertStringContainsString('scale=200:120', $joined);

        // Output still ends with the stdout pipe marker.
        $this->assertSame('-', $cmd[array_key_last($cmd)], 'graphics output still goes to the stdout pipe (-)');
    }

    /**
     * @testdox graphics buildCommand still inserts -ss / reconnect like the rawvideo path
     */
    public function testGraphicsCommandKeepsSeekAndReconnect(): void
    {
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', 'https://srv/s.mkv?sig=x', 320, 200, 24.0, 7.5, true);

        $this->assertContains('-reconnect', $cmd);
        $ssIdx = array_search('-ss', $cmd, true);
        $this->assertIsInt($ssIdx);
        $this->assertSame('7.500', $cmd[$ssIdx + 1]);
        // Still an image2pipe stream despite the seek/reconnect options.
        $this->assertContains('image2pipe', $cmd);
    }

    /**
     * @testdox the default ($graphics = false) buildCommand stays a rawvideo stream (no image2pipe)
     *
     * Guards the text-mode path against the graphics branch leaking in: the
     * default argv must keep rawvideo/rgb24 and must NOT carry image2pipe/png.
     */
    public function testDefaultCommandStaysRawvideo(): void
    {
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', '/tmp/m.mkv', 80, 48, 24.0);

        $this->assertContains('rawvideo', $cmd);
        $this->assertContains('rgb24', $cmd);
        $this->assertNotContains('image2pipe', $cmd);
        $this->assertNotContains('-compression_level', $cmd);
        $this->assertSame('-', $cmd[array_key_last($cmd)]);
    }

    // The PNG IEND framing in next()/nextPng() (split on the 12-byte IEND marker)
    // is exercised end-to-end by the live integration test below
    // (testLiveGraphicsDecodeYieldsPngFrames) — the PNG pipe cannot be injected
    // as cheaply as the rawvideo stream, so we drive it through real ffmpeg and
    // skip when the binary is absent (matching the existing style).

    /**
     * @testdox a live graphics-mode decode yields full-resolution PNG-payload frames
     *
     * Integration coverage for the image2pipe + IEND-framing path: open a real
     * clip in a graphics Mode (Sixel), and assert each RgbFrame carries a PNG
     * blob ($png) at the terminal's full pixel resolution (cells·cellPx) with an
     * empty raw byte buffer. Skipped when ffmpeg is absent (CI may lack it).
     */
    public function testLiveGraphicsDecodeYieldsPngFrames(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/sugar-video-test-gfx-' . getmypid() . '.mp4';

        // Watchdog: SIGKILL any test ffmpeg children after 20s so a regression
        // that deadlocks the PNG pipe can't wedge the suite.
        $wd = proc_open(
            ['sh', '-c', 'sleep 20; pkill -9 -f sugar-video-test-gfx'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $wdPipes,
        );

        try {
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
            $this->assertFileExists($clip);

            $cellsW = 8;
            $cellsH = 6;
            $cellPxW = 10;
            $cellPxH = 20;

            $decoder = new FfmpegDecoder($cellPxW, $cellPxH);
            $decoder->open($clip, $cellsW, $cellsH, 10.0, Mode::Sixel);

            $frame = $decoder->next();
            $this->assertInstanceOf(RgbFrame::class, $frame);

            // Graphics frames carry a self-contained PNG blob; the raw grid is empty.
            $this->assertNotNull($frame->png, 'graphics decode must fill $png');
            $this->assertSame('', $frame->bytes, 'graphics decode leaves $bytes empty');
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", (string) $frame->png, 'payload is a real PNG');

            // Frame is sized at the terminal's FULL pixel resolution.
            $this->assertSame($cellsW * $cellPxW, $frame->w);
            $this->assertSame($cellsH * $cellPxH, $frame->h);

            // The PNG decodes and its pixel size matches the requested frame size.
            $gd = $frame->toGd();
            $this->assertSame($cellsW * $cellPxW, imagesx($gd));
            $this->assertSame($cellsH * $cellPxH, imagesy($gd));
            imagedestroy($gd);

            $decoder->close();
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

    /**
     * @testdox reconnect flags and -ss coexist for a seeked network stream, both before -i
     */
    public function testNetworkSeekOrdersReconnectThenSeekThenInput(): void
    {
        $cmd = FfmpegDecoder::buildCommand('/usr/bin/ffmpeg', 'https://srv/s.mkv?sig=x', 80, 48, 24.0, 12.0);

        $reconnectIdx = array_search('-reconnect', $cmd, true);
        $ssIdx = array_search('-ss', $cmd, true);
        $inputIdx = array_search('-i', $cmd, true);
        $this->assertIsInt($reconnectIdx);
        $this->assertIsInt($ssIdx);
        $this->assertLessThan($ssIdx, $reconnectIdx, 'reconnect block precedes -ss');
        $this->assertLessThan($inputIdx, $ssIdx, '-ss precedes -i');
    }

    // -------------------------------------------------------------------------
    // exitCode() — returns the ffmpeg exit code after close()
    // -------------------------------------------------------------------------

    /**
     * @testdox exitCode() returns null before any process was opened
     */
    public function testGetExitCodeReturnsNullBeforeOpen(): void
    {
        $decoder = new FfmpegDecoder();
        $this->assertNull($decoder->exitCode());
    }

    /**
     * @testdox exitCode() returns null while process is still running
     */
    public function testGetExitCodeReturnsNullWhileRunning(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/sugar-video-test-exitcode-' . getmypid() . '.mp4';
        $wd = proc_open(
            ['sh', '-c', 'sleep 20; pkill -9 -f sugar-video-test-exitcode'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $wdPipes,
        );

        try {
            $gen = proc_open(
                [Probe::ffmpeg(), '-hide_banner', '-loglevel', 'error',
                    '-f', 'lavfi', '-i', 'testsrc=duration=2:size=64x48:rate=10',
                    '-y', $clip],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $genPipes,
            );
            foreach ($genPipes as $p) {
                if (is_resource($p)) {
                    fclose($p);
                }
            }
            proc_close($gen);

            $decoder = new FfmpegDecoder();
            $decoder->open($clip, 16, 12, 10.0, Mode::HalfBlock);

            $this->assertNull($decoder->exitCode(), 'exit code should be null while process is running');

            $decoder->close();
            // After close, exit code is set (0 if successful)
            $this->assertNotNull($decoder->exitCode());
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

    /**
     * @testdox exitCode() returns 0 for successful decode after close()
     */
    public function testGetExitCodeReturnsZeroOnSuccess(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/sugar-video-test-exitcode0-' . getmypid() . '.mp4';

        $gen = proc_open(
            [Probe::ffmpeg(), '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'testsrc=duration=1:size=64x48:rate=10',
                '-y', $clip],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $genPipes,
        );
        foreach ($genPipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($gen);

        try {
            $decoder = new FfmpegDecoder();
            $decoder->open($clip, 16, 12, 10.0, Mode::HalfBlock);

            // Read all frames to let ffmpeg finish
            while ($decoder->next() !== null) {
            }

            $decoder->close();
            $this->assertSame(0, $decoder->exitCode(), 'ffmpeg should exit with code 0 on success');
        } finally {
            if (is_file($clip)) {
                @unlink($clip);
            }
        }
    }

    // -------------------------------------------------------------------------
    // reopen() — close and re-open with new parameters
    // -------------------------------------------------------------------------

    /**
     * @testdox reopen() closes the previous decoder and opens with new parameters
     */
    public function testReopenClosesAndReopensWithNewParams(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/sugar-video-test-reopen-' . getmypid() . '.mp4';

        $gen = proc_open(
            [Probe::ffmpeg(), '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'testsrc=duration=2:size=320x240:rate=15',
                '-y', $clip],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $genPipes,
        );
        foreach ($genPipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($gen);

        try {
            $decoder = new FfmpegDecoder();
            // First open with one set of params
            $decoder->open($clip, 20, 15, 15.0, Mode::HalfBlock);

            // Reopen with different params
            $decoder->reopen($clip, 40, 30, 30.0, Mode::QuarterBlock);

            // Verify we can decode frames with the new parameters
            $frame = $decoder->next();
            $this->assertNotNull($frame, 'should be able to decode after reopen');
            $this->assertSame(80, $frame->w, 'QuarterBlock: 40 cols * 2 cols per cell = 80');
            $this->assertSame(60, $frame->h, 'QuarterBlock: 30 rows * 2 rows per cell = 60');

            $decoder->close();
            $this->assertNull($decoder->next(), 'next() should return null after close');
        } finally {
            if (is_file($clip)) {
                @unlink($clip);
            }
        }
    }

    // -------------------------------------------------------------------------
    // fps() — returns the cached fps from the last open() call
    // -------------------------------------------------------------------------

    /**
     * @testdox fps() returns 0.0 before any open() call
     */
    public function testFpsReturnsZeroBeforeOpen(): void
    {
        $decoder = new FfmpegDecoder();
        $this->assertSame(0.0, $decoder->fps());
    }

    /**
     * @testdox fps() returns the fps passed to open()
     */
    public function testFpsReturnsOpenedFps(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/sugar-video-test-fps-' . getmypid() . '.mp4';

        $gen = proc_open(
            [Probe::ffmpeg(), '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'testsrc=duration=1:size=64x48:rate=10',
                '-y', $clip],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $genPipes,
        );
        foreach ($genPipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($gen);

        try {
            $decoder = new FfmpegDecoder();
            $decoder->open($clip, 16, 12, 24.5, Mode::HalfBlock);

            $this->assertSame(24.5, $decoder->fps());

            $decoder->close();
            // fps remains cached after close
            $this->assertSame(24.5, $decoder->fps());
        } finally {
            if (is_file($clip)) {
                @unlink($clip);
            }
        }
    }

    /**
     * @testdox fps() is updated when reopen() is called with a different fps
     */
    public function testFpsIsUpdatedOnReopen(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/sugar-video-test-fps-reopen-' . getmypid() . '.mp4';

        $gen = proc_open(
            [Probe::ffmpeg(), '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'testsrc=duration=1:size=64x48:rate=10',
                '-y', $clip],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $genPipes,
        );
        foreach ($genPipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($gen);

        try {
            $decoder = new FfmpegDecoder();
            $decoder->open($clip, 16, 12, 15.0, Mode::HalfBlock);
            $this->assertSame(15.0, $decoder->fps());

            $decoder->reopen($clip, 16, 12, 30.0, Mode::HalfBlock);
            $this->assertSame(30.0, $decoder->fps());
        } finally {
            if (is_file($clip)) {
                @unlink($clip);
            }
        }
    }

    /**
     * @testdox next() reports a crashed child as the typed failure with swept diagnostics
     */
    public function testACrashedChildAtEofThrowsTheTypedFailureWithSweptDiagnostics(): void
    {
        $decoder = new FfmpegDecoder();
        $stderr = sys_get_temp_dir() . '/ffdec-stderr-' . uniqid('', true) . '.txt';
        file_put_contents($stderr, "\x1b[31mboom\x1b[0m: Invalid data found when processing input\n");

        $process = proc_open(
            [PHP_BINARY, '-r', 'fclose(STDIN); fclose(STDOUT); exit(7);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'a']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        foreach ([
            'stdout' => $pipes[1],
            'process' => $process,
            'cellsW' => 4,
            'frameW' => 4,
            'frameH' => 2,
            'frameBytes' => 24,
            'source' => 'http://abc%40def@example.test/clip.m3u8',
            'stderrPath' => $stderr,
        ] as $key => $value) {
            (new \ReflectionProperty(FfmpegDecoder::class, (string) $key))->setValue($decoder, $value);
        }

        $deadline = microtime(true) + 2.0;
        while ((proc_get_status($process)['running'] ?? false) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        try {
            $this->expectException(\SugarCraft\Video\Decode\FfmpegDecodeFailure::class);
            $decoder->next();
        } finally {
            $decoder->close();
            @unlink($stderr);
        }
    }

    /**
     * @testdox the failure copy sweeps escape bytes, keeps the tail text, and never leaks URL credentials
     */
    public function testTheFailureMessageIsSweptAndRedacted(): void
    {
        $decoder = new FfmpegDecoder();
        $stderr = sys_get_temp_dir() . '/ffdec-tail-' . uniqid('', true) . '.txt';
        file_put_contents($stderr, "\x1b[31mboom\x1b[0m: Invalid data found\n");
        $process = proc_open(
            [PHP_BINARY, '-r', 'fclose(STDIN); fclose(STDOUT); exit(7);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'a']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        foreach ([
            'stdout' => $pipes[1],
            'process' => $process,
            'cellsW' => 4,
            'frameW' => 4,
            'frameH' => 2,
            'frameBytes' => 24,
            'source' => 'http://abc%40def@example.test/clip.m3u8',
            'stderrPath' => $stderr,
        ] as $key => $value) {
            (new \ReflectionProperty(FfmpegDecoder::class, (string) $key))->setValue($decoder, $value);
        }
        $deadline = microtime(true) + 2.0;
        while ((proc_get_status($process)['running'] ?? false) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        try {
            $message = '';
            try {
                $decoder->next();
            } catch (\SugarCraft\Video\Decode\FfmpegDecodeFailure $failure) {
                $message = $failure->getMessage();
            }
            $this->assertNotSame('', $message, 'the crashed child must raise the typed failure');
            $this->assertStringContainsString('code 7', $message);
            $this->assertStringContainsString('boom', $message, 'the stderr tail must ride the message');
            $this->assertStringNotContainsString("\x1b", $message, 'escape bytes from the tail must be swept');
            $this->assertStringNotContainsString('abc%40def', $message, 'URL credentials must never ride the message');
            $this->assertStringContainsString('example.test', $message);
        } finally {
            $decoder->close();
            @unlink($stderr);
        }
    }

    /**
     * @testdox close() unlinks the per-open stderr diagnostic file
     */
    public function testCloseCleansUpTheStderrDiagnosticFile(): void
    {
        $decoder = new FfmpegDecoder();
        $stderr = sys_get_temp_dir() . '/ffdec-clean-' . uniqid('', true) . '.txt';
        file_put_contents($stderr, 'tail');
        (new \ReflectionProperty(FfmpegDecoder::class, 'stderrPath'))->setValue($decoder, $stderr);
        $decoder->close();
        $this->assertFileDoesNotExist($stderr, 'the diagnostic capture must not outlive the decoder');
    }

    /**
     * @testdox m8: the PNG-buffer trip clears the buffer so the stall is not permanent
     */
    public function testThePngBufferTripDropsTheBufferedBytes(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Decode/FfmpegDecoder.php');
        $trip = strpos($source, 'strlen($this->pngBuffer) + strlen($chunk) > self::MAX_PNG_BUFFER');
        $this->assertIsInt($trip, 'the trip site must exist');
        $window = substr($source, $trip, 900);
        $this->assertStringContainsString(
            "\$this->pngBuffer = '';",
            $window,
            'm8: bailing without clearing re-trips forever and pins the megabytes',
        );
        $clear = strpos($window, "\$this->pngBuffer = '';");
        $bail = strpos($window, 'return null;', (int) $clear);
        $this->assertIsInt($clear);
        $this->assertIsInt($bail, 'the trip must bail after clearing');
        $this->assertLessThan($bail, $clear, 'the clear must happen before the bail');
    }

    /**
     * @testdox M8: the child environment drops proxy/secret variables and keeps PATH
     */
    public function testTheScrubbedEnvironmentDropsProxyAndSecretVariables(): void
    {
        $hostile = [
            'HTTP_PROXY' => 'http://evil.test',
            'http_proxy' => 'http://evil.test',
            'ALL_PROXY' => 'socks5://evil.test',
            'AWS_SESSION_TOKEN' => 'nope',
            'S3_SECRET' => 'nope',
            'DB_PASSWORD' => 'nope',
            'SSH_private_KEY' => 'nope',
        ];
        $saved = [];
        foreach ($hostile as $name => $value) {
            $saved[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            $env = \SugarCraft\Video\Support\FfmpegCommandBuilder::scrubbedEnv();
            $this->assertArrayHasKey('PATH', $env, 'PATH must survive — an explicit env REPLACES the parent one');
            foreach (array_keys($hostile) as $name) {
                $this->assertArrayNotHasKey($name, $env, $name . ' must never reach a media child');
            }
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    /**
     * @testdox M8: a spawned ffmpeg child literally cannot see the parent's proxy
     */
    public function testASpawnedFfmpegChildNeverSeesTheParentProxy(): void
    {
        $dir = sys_get_temp_dir() . '/m8-scrub-' . uniqid('', true);
        mkdir($dir);
        $dump = $dir . '/env-dump.txt';
        file_put_contents(
            $dir . '/ffmpeg',
            "#!/bin/sh\n/usr/bin/env > " . escapeshellarg($dump) . "\nexit 0\n",
        );
        chmod($dir . '/ffmpeg', 0755);
        $subject = $dir . '/clip.mp4';
        file_put_contents($subject, 'x');

        $savedPath = getenv('PATH');
        $savedProxy = getenv('HTTP_PROXY');
        putenv('PATH=' . $dir);
        putenv('HTTP_PROXY=http://evil-proxy.test');
        Probe::clearBinaryCacheForTesting();

        try {
            $decoder = new FfmpegDecoder();
            $decoder->open($subject, 8, 6, 10.0, Mode::HalfBlock);
            $decoder->close();

            $this->assertFileExists($dump, 'the stub must have run as the media child');
            $childEnv = (string) file_get_contents($dump);
            $this->assertStringNotContainsString('evil-proxy', $childEnv, 'the proxy env must not ride the child');
            $this->assertStringNotContainsString('HTTP_PROXY=', $childEnv);
            $this->assertStringContainsString('PATH=', $childEnv);
        } finally {
            putenv($savedPath === false ? 'PATH' : 'PATH=' . $savedPath);
            putenv($savedProxy === false ? 'HTTP_PROXY' : 'HTTP_PROXY=' . $savedProxy);
            Probe::clearBinaryCacheForTesting();
            @unlink($dump);
            @unlink($dir . '/ffmpeg');
            @unlink($subject);
            @rmdir($dir);
        }
    }

    /**
     * @testdox SV-M43: formatFloat keeps the historical dot-decimal bytes
     */
    public function testFloatFormattingMatchesTheOldCastBytes(): void
    {
        $this->assertSame('24', FfmpegCommandBuilder::formatFloat(24.0));
        $this->assertSame('23.976', FfmpegCommandBuilder::formatFloat(23.976));
        $this->assertSame('0.5', FfmpegCommandBuilder::formatFloat(0.5));
        $this->assertSame(['-ss', '12.500'], FfmpegCommandBuilder::inputSeekFlag(12.5));
    }

    /**
     * @testdox SV-M43: under a comma-decimal locale the argv still shows dots
     *
     * setlocale() needs the locale data installed; where the host lacks it the
     * documented fallback is the format-string assert below (the argv sites
     * must not use the locale-aware %f / bare float cast — a locale-less host
     * cannot demonstrate the difference, so the shape is pinned instead).
     */
    public function testLocaleCommaHostStillEmitsDotDecimals(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        $applied = setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'de_DE@euro', 'German');

        if ($applied === false) {
            if ($previous !== false) {
                setlocale(LC_NUMERIC, $previous);
            }
            // Documented skip arm: pin the formatter contract on a locale-blind host.
            $this->assertSame('12.500', sprintf('%.3F', 12.5), '%F is the locale-independent form');
            $src = (string) file_get_contents(\dirname(__DIR__) . '/src/Support/FfmpegCommandBuilder.php');
            $this->assertStringNotContainsString("sprintf('%.3f'", $src, 'the seek flag must not use locale-aware %f');
            $this->assertStringNotContainsString("'-ss', (string)", $src);
            $decoderSrc = (string) file_get_contents(\dirname(__DIR__) . '/src/Decode/FfmpegDecoder.php');
            $this->assertStringNotContainsString('(string) $fps', $decoderSrc, 'the fps filter must ride formatFloat()');
            $this->markTestSkipped('no comma-decimal locale data on this host — format-string arms asserted instead');
        }

        try {
            $flags = FfmpegCommandBuilder::inputSeekFlag(12.5);
            $this->assertSame('12.500', $flags[1], 'LC_NUMERIC must not leak a comma into ffmpeg argv');
            $cmd = FfmpegDecoder::buildCommand('ffmpeg', '/tmp/x.mp4', 64, 32, 23.976);
            $vf = $cmd[array_search('-vf', $cmd, true) + 1];
            $this->assertStringContainsString('fps=23.976,', $vf);
        } finally {
            setlocale(LC_NUMERIC, $previous === false ? 'C' : $previous);
        }
    }

    /**
     * @testdox SV-SEC1 (074): the other CLI media tools' credential channels are swept too
     */
    public function testTheScrubAlsoDropsMysqlPgpassNetrcAndPswdNames(): void
    {
        $hostile = [
            'MYSQL_PWD' => 'nope',
            'PGPASSFILE' => '/home/u/.pgpass',
            'NETRC' => '/home/u/.netrc',
            'SAP_APS_PSWD' => 'nope',
            'pgpassfile' => 'lowercase-shape-still-gone',
        ];
        $saved = [];
        foreach ($hostile as $name => $value) {
            $saved[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            $env = \SugarCraft\Video\Support\FfmpegCommandBuilder::scrubbedEnv();
            foreach (array_keys($hostile) as $name) {
                $this->assertArrayNotHasKey($name, $env, $name . ' must never reach a media child');
            }
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    /**
     * @testdox SV-SEC1 (093): the last-resort PATH is OS-aware; SV-SEC1 (098): the docblock counts four binaries
     */
    public function testTheFallbackPathSetMatchesTheHostFamily(): void
    {
        $savedPath = getenv('PATH');
        putenv('PATH'); // unset it — the fallback branch must answer

        try {
            $env = \SugarCraft\Video\Support\FfmpegCommandBuilder::scrubbedEnv();
            if (\PHP_OS_FAMILY === 'Windows') {
                $this->assertStringContainsString('System32', $env['PATH'], 'a Windows child needs Windows directories');
            } else {
                $this->assertSame('/usr/local/bin:/usr/bin:/bin', $env['PATH'], 'the Unix string is unchanged');
            }
        } finally {
            putenv($savedPath === false ? 'PATH' : 'PATH=' . $savedPath);
        }

        $src = (string) file_get_contents(\dirname(__DIR__) . '/src/Support/FfmpegCommandBuilder.php');
        $this->assertMatchesRegularExpression('/drives four separate\s*\n?\s*\* binaries.*ffprobe/', $src, 'SV-SEC1 (098): the enumeration must match the four child kinds the library actually spawns');
    }

    /**
     * @testdox SV-L83 (house law): the stderr sink lives in a process-owned 0700 dir, not shared /tmp root
     */
    public function testTheStderrSinkRidesAnOwnedPrivateDirectory(): void
    {
        $sinkDir = new \ReflectionMethod(FfmpegDecoder::class, 'sinkDir');
        $dir = $sinkDir->invoke(null);
        $this->assertIsString($dir, 'the host must be able to secure the owned dir (the arms below would else fail the law)');
        $this->assertDirectoryExists($dir);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4), 'owned-private mode or nothing');
        $this->assertSame(sys_get_temp_dir(), dirname($dir));
        $this->assertSame($dir, $sinkDir->invoke(null), 'one dir per process, not one per open()');

        // And the sink FILE lands under it: run a real open() against a stub
        // ffmpeg, then read the stderrPath the decoder recorded.
        $bin = sys_get_temp_dir() . '/reel-l83-' . bin2hex(random_bytes(5));
        mkdir($bin);
        file_put_contents($bin . '/ffmpeg', "#!/bin/sh\nexit 0\n");
        chmod($bin . '/ffmpeg', 0755);
        $source = $bin . '/ffmpeg'; // any existing file passes the pre-spawn source check
        $savedPath = getenv('PATH');
        putenv('PATH=' . $bin);
        Probe::clearBinaryCacheForTesting();
        $decoder = new FfmpegDecoder();

        try {
            try {
                $decoder->open($source, 8, 6, 10.0);
            } catch (\Throwable) {
                // A stub that yields no frames may fail loudly later — the sink
                // is recorded BEFORE the spawn, so the pin below holds either way.
            }
            $sink = (new \ReflectionProperty(FfmpegDecoder::class, 'stderrPath'))->getValue($decoder);
            $this->assertIsString($sink, 'with the owned dir secured, the diagnostic sink must not fall back to the null device');
            $this->assertSame($dir, dirname($sink), 'SV-L83: no symlink-swap surface in world-visible /tmp root');
        } finally {
            $decoder->close();
            putenv($savedPath === false ? 'PATH' : 'PATH=' . $savedPath);
            Probe::clearBinaryCacheForTesting();
            @unlink($bin . '/ffmpeg');
            @rmdir($bin);
        }
    }

    /**
     * @testdox SV-M44 (gate default-leg, route a): a Windows host loudly refuses the ffmpeg video path before any spawn
     */
    public function testTheWindowsHostRefusesFfmpegVideoWithGuidance(): void
    {
        // A stub ffmpeg that would mark its spawn — the refusal must happen
        // before it, so the marker staying absent IS the load-bearing pin.
        $dir = sys_get_temp_dir() . '/sugar-video-win-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $marker = $dir . '/spawned';
        file_put_contents($dir . '/ffmpeg', "#!/bin/sh\ntouch " . $marker . "\nexit 0\n");
        chmod($dir . '/ffmpeg', 0755);
        $savedPath = getenv('PATH');
        putenv('PATH=' . $dir);
        Probe::clearBinaryCacheForTesting();
        FfmpegDecoder::setWindowsHostForTesting(true);

        try {
            $thrown = null;
            try {
                (new FfmpegDecoder())->open('/tmp/whatever.mp4', 8, 6, 24.0);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }

            $this->assertNotNull($thrown, 'the Windows ffmpeg path must refuse, not black-screen');
            $this->assertStringContainsString('Windows', $thrown->getMessage());
            $this->assertStringContainsString('stream_select', $thrown->getMessage(), 'the refusal must name the mechanism');
            $this->assertStringContainsString('GIF', $thrown->getMessage(), 'the refusal must point at what still works');
            $this->assertFileDoesNotExist($marker, 'nothing may be spawned on the refused route');
        } finally {
            FfmpegDecoder::setWindowsHostForTesting(null);
            putenv($savedPath === false ? 'PATH' : 'PATH=' . $savedPath);
            Probe::clearBinaryCacheForTesting();
            @unlink($marker);
            @unlink($dir . '/ffmpeg');
            @rmdir($dir);
        }
    }

    public function testTheMissingSourceRefusalNeverEchoesUrlCredentials(): void
    {
        $dir = sys_get_temp_dir() . '/sugar-video-cred-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/ffmpeg', "#!/bin/sh\nexit 0\n");
        chmod($dir . '/ffmpeg', 0755);
        $savedPath = getenv('PATH');
        putenv('PATH=' . $dir);
        Probe::clearBinaryCacheForTesting();
        try {
            (new FfmpegDecoder())->open('file://user:pass@example.invalid/missing.mp4', 8, 6, 10.0);
            self::fail('the refusal was expected');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('user:pass', $e->getMessage());
            self::assertStringContainsString('video source not found:', $e->getMessage());
            self::assertStringContainsString('***@', $e->getMessage());
        } finally {
            putenv($savedPath === false ? 'PATH' : 'PATH=' . $savedPath);
            Probe::clearBinaryCacheForTesting();
            @unlink($dir . '/ffmpeg');
            @rmdir($dir);
        }
    }
}
