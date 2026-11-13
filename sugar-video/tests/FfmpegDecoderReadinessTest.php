<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\FfmpegDecodeFailure;
use SugarCraft\Video\Decode\FfmpegDecoder;
use SugarCraft\Video\Decode\RgbFrame;
use SugarCraft\Video\Source\Probe;

/**
 * E722 (round 82) boundedness pins for the FfmpegDecoder pipe posture.
 *
 * The decoder's reads are a documented PUMP CONTRACT (class docblock): a
 * blocking fread with no internal deadline — a live-but-silent ffmpeg parks
 * the caller until close() takes the child down. These tests pin the two
 * halves this class DOES bound, so the contract is a promise with teeth,
 * not prose:
 *
 *  - a child that ENDS releases every read promptly (EOF polarity);
 *  - a child that is alive-and-wedged still dies within the BoundedShutdown
 *    ceiling once close() is reached (teardown polarity) — driven through a
 *    leashed harness so a regression that removes the ladder fails FAST
 *    instead of hanging the suite (r69 law).
 *
 * @covers \SugarCraft\Video\Decode\FfmpegDecoder
 */
final class FfmpegDecoderReadinessTest extends TestCase
{
    /** Wall ceiling for the harness child: ladder (≤3.5s) + generous margin. */
    private const HARNESS_TIMEOUT_SECONDS = 20;

    private ?string $errorLogBackup = null;

    protected function tearDown(): void
    {
        if ($this->errorLogBackup !== null) {
            // Empty string is the CLI default (log to stderr) — ini_set
            // accepts it; inventing a magic label would create a file.
            \ini_set('error_log', $this->errorLogBackup);
            $this->errorLogBackup = null;
        }
    }

    /**
     * @testdox a child that dies abnormally releases the read promptly — with the typed refusal
     *
     * SV-M22/022 adjudication of the pump-contract first arm. The original
     * premise ("dies without OUTPUT" meaning silent) predates the M12 law
     * this decoder now documents at the read site: a dead-abnormal child
     * THROWS — crash is not end-of-stream. An empty .mp4 dies LOUD (non-zero
     * exit + stderr text), so "promptly with null" was reachable only while a
     * zombie-timing race kept proc_get_status reporting the child RUNNING at
     * the refusal check — green under suite load, deterministic red on an
     * idle box (found by running this file in isolation for M22). The arm now
     * pins the CURRENT law, and the promptness budget survives verbatim: the
     * typed refusal is the prompt release for the abnormal half.
     *
     * The clean half ("child ENDS → null promptly") moved to its own honest
     * fixture below, so EOF polarity is still pinned — with a child that
     * really does end without output.
     */
    public function testNextRefusesTypedPromptlyWhenChildDiesAbnormally(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $base = (string) tempnam(sys_get_temp_dir(), 'sugar-video-empty');
        $clip = $base . '.mp4';
        $this->assertTrue(rename($base, $clip), 'cannot name the empty-file fixture');
        $log = $clip . '.errlog';
        $this->errorLogBackup = (string) \ini_get('error_log');
        \ini_set('error_log', $log);

        $decoder = null;
        try {
            $decoder = new FfmpegDecoder();
            $decoder->open($clip, 8, 6, 10.0);

            // SV-M22/022: force the fact the branch under test keys on — the
            // refusal distinguishes DEAD (throw) from live-but-silent (null),
            // so pinning the DEAD arm must wait out the death, not race it.
            // The old test's green under suite load WAS the race, won by the
            // wrong side (running=true answered null). Poll-until-ceiling,
            // observing the same proc_get_status the decoder consults — the
            // process resource is private, the reflection is the seam here.
            $proc = (new \ReflectionProperty(FfmpegDecoder::class, 'process'))->getValue($decoder);
            $this->assertIsResource($proc, 'the empty file is a regular path — the spawn must happen');
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $status = @proc_get_status($proc);
                if (!is_array($status) || !($status['running'] ?? false)) {
                    break;
                }
                usleep(10_000);
            }

            $thrown = null;
            $start = microtime(true);
            try {
                $decoder->next();
            } catch (FfmpegDecodeFailure $failure) {
                $thrown = $failure;
            }

            // SV-M22 review: this 5.0 s bound is the completion-form budget
            // M22 asks for — next() IS the operation measured; the ceiling
            // bounds the grace-ladder worst case, not a settle window.
            $this->assertLessThan(5.0, microtime(true) - $start, 'the typed refusal must release the read as promptly as EOF');
            $this->assertNotNull($thrown, 'M12: a dead-abnormal child is not EOF — the read refuses typed');
            $this->assertStringContainsString('exited with code', $thrown->getMessage());
            $this->assertStringContainsString('ffmpeg said', $thrown->getMessage(), 'the stderr tail rides the refusal');

            $again = null;
            try {
                $decoder->next();
            } catch (FfmpegDecodeFailure $failure) {
                $again = $failure;
            }

            $this->assertNotNull($again, 'the typed refusal is stable, not a one-shot');

            $decoder->close();
            $this->assertNotSame(0, $decoder->exitCode(), 'ffmpeg must have failed on the empty file');

            $logged = is_file($log) ? (string) file_get_contents($log) : '';
            $this->assertStringContainsString(
                "FfmpegDecoder: ffmpeg exited with code {$decoder->exitCode()}",
                $logged,
                'close() must record the ffmpeg failure on the error log',
            );
        } finally {
            $decoder?->close();
            @unlink($clip);
            @unlink($log);
        }
    }

    /**
     * @testdox a child that ENDS cleanly releases next() promptly with null — the true EOF polarity
     *
     * The original arm's honest half: a real end-of-stream (valid one-frame
     * clip; ffmpeg writes the frame, closes the pipe and exits 0) must
     * release with null — never the typed refusal — and stay stable. This is
     * the fixture the pre-M12 test NEEDED; the empty file was always a loud
     * crash wearing an EOF costume.
     */
    public function testNextReturnsNullPromptlyWhenChildEndsCleanly(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $clip = sys_get_temp_dir() . '/sugar-video-ready-' . getmypid() . '.mp4';
        $gen = proc_open(
            [
                Probe::ffmpeg(), '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'color=c=red:s=8x6:r=10',
                '-frames:v', '1',
                '-y', $clip,
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $genPipes,
        );
        $this->assertIsResource($gen, 'ffmpeg clip generation must start');
        foreach ($genPipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }

        $this->assertSame(0, proc_close($gen), 'the one-frame clip must generate cleanly');
        $this->assertFileExists($clip);

        $decoder = null;
        try {
            $decoder = new FfmpegDecoder();
            $decoder->open($clip, 8, 6, 10.0);

            $start = microtime(true);
            $frame = $decoder->next();
            $this->assertInstanceOf(RgbFrame::class, $frame, 'the single frame is delivered');
            // The decoder sizes its output at cellsH × rowsPerCell pixels
            // (the CALIBER rowspercell law this lib's decoders honour): an
            // 8x6 HalfBlock pane decodes 8x12.
            $this->assertSame(8, $frame->w);
            $this->assertSame(12, $frame->h);

            $frame2 = $decoder->next();
            $this->assertLessThan(5.0, microtime(true) - $start, 'the clean end must release promptly');
            $this->assertNull($frame2, 'a clean-exit child ends the stream — null, not the typed refusal');
            $this->assertNull($decoder->next(), 'EOF is stable');

            $decoder->close();
            $this->assertSame(0, $decoder->exitCode(), 'the child exited clean');
        } finally {
            $decoder?->close();
            @unlink($clip);
        }
    }

    /**
     * @testdox a live-but-silent child parks reads observably-yet-not-ready, and close() stays bounded
     *
     * Teardown polarity of the pump contract, against the wedge the class
     * docblock names: ffmpeg connected to a black-hole HTTP listener that
     * never answers, so the child is alive, silent, and blocked in its input
     * read. A zero-timeout stream_select on the very fd next() parks shows
     * NOT-ready (the hazard is real and observable without parking on it),
     * and close() still ends the child within the canonical ladder's
     * ceiling. Run through a `timeout -s KILL`-leashed child (df/lane-cd
     * precedent): strip the ladder and the harness never prints DONE — the
     * mutation reads RED in bounded time, never as a suite hang.
     */
    public function testSilentLiveChildParksReadsAndCloseStaysWithinLadderCeiling(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }
        if (!$this->timeoutBinaryAvailable()) {
            $this->markTestSkipped('timeout(1) not present — cannot leash the wedge harness');
        }

        $blackhole = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($blackhole === false) {
            $this->markTestSkipped("cannot bind loopback listener: {$errstr}");
        }
        $port = (int) (parse_url((string) stream_socket_get_name($blackhole, false), PHP_URL_PORT) ?? 0);
        $this->assertGreaterThan(0, $port, 'loopback listener must report its port');

        $pidFile = (string) tempnam(sys_get_temp_dir(), 'sugar-video-wedge-pid');
        $harness = __DIR__ . '/Support/ffmpeg-wedge-harness.php';
        $autoload = __DIR__ . '/../vendor/autoload.php';

        try {
            $process = proc_open(
                [
                    'timeout', '-s', 'KILL', (string) self::HARNESS_TIMEOUT_SECONDS,
                    \PHP_BINARY, $harness, $autoload, "http://127.0.0.1:{$port}/stream", $pidFile,
                ],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process);

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            $harnessExit = proc_close($process);

            $this->assertStringContainsString(
                'DONE',
                $stdout,
                "harness must reach its end marker — a missing DONE under the leash means close() itself blocked (ladder regression). stdout:\n{$stdout}\nstderr:\n{$stderr}",
            );
            $this->assertSame(0, $harnessExit, 'harness must exit cleanly');
            $this->assertStringContainsString('CHILD_RUNNING=1', $stdout, 'ffmpeg must be alive while silent');
            $this->assertStringNotContainsString('CHILD_DIED', $stdout, 'a premature child death voids the wedge polarity');
            $this->assertStringContainsString('SELECT_READY=0', $stdout, 'the silent pipe must be selectably NOT-ready — that is the pump hazard, pinned as real');

            $this->assertSame(1, preg_match('/CLOSE_SECONDS=([\d.]+)/', $stdout, $m), 'close() timing must be reported');
            // Ceiling: GRACE+TERM+KILL = 3.5s of ladder, +1s slack for the
            // poll tick and process teardown. (SV-M22 review: already the
            // completion-form budget — CLOSE_SECONDS is stamped inside the
            // harness around the measured call; no fixed sleep to replace.)
            $this->assertLessThanOrEqual(4.5, (float) $m[1], 'close() must stay inside the canonical ladder ceiling');

            // Honest exit-status polarity: a TERM-interrupted ffmpeg exits
            // CLEANLY (its own interrupt callback aborts the read — code 0),
            // an escalated one exits signalled. Neither is "success" in the
            // meaningful sense, so what we pin is DEADNESS, not the code:
            // the kernel pid must be gone, i.e. close() took the child down
            // and reaped it — not merely stopped waiting for it.
            $this->assertStringContainsString('CHILD_ALIVE_AFTER_CLOSE=0', $stdout, 'close() must leave no live or zombie ffmpeg child');
            $this->assertMatchesRegularExpression('/EXIT_CODE=\S+/', $stdout, 'the recorded exit code must still be reported');
        } finally {
            // Hermetic under every outcome, including a KILLed harness that
            // never got to close its ffmpeg itself. Signal 9 literal —
            // sugar-video does not require ext-pcntl (the deleted BoundedReaper's precedent, now sugar-core BoundedShutdown's doctrine).
            $wedgedPid = (int) @file_get_contents($pidFile);
            if ($wedgedPid > 0 && \function_exists('posix_kill')) {
                @posix_kill($wedgedPid, 9);
            }
            @unlink($pidFile);
            fclose($blackhole);
        }
    }

    /**
     * @testdox open() holds stderr as a FILE sink, never a second pipe
     *
     * Structural pin of the F7/E722 posture: a reader-less stderr pipe is
     * how the blocking read turns into a permanent deadlock (ffmpeg fills
     * the 64KB kernel buffer and stops producing on stdout too), so the
     * descriptor spec must keep exactly two pipes (stdin, stdout) and a
     * file sink on stderr. Flipping the sink to a pipe reads RED here in
     * bounded time, mirroring the deadlock this design avoids.
     *
     * Since the loud-failure work the file sink is the per-open DIAGNOSTIC
     * capture (a tempnam'd file whose tail rides the FfmpegDecodeFailure),
     * with the null device as the tempnam-failure fallback — a file either
     * way, never a pipe.
     */
    public function testStderrIsAFileSinkNeverAPipe(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Decode/FfmpegDecoder.php');

        $this->assertStringContainsString(
            "['file', \$this->stderrPath ?? \$devNull, 'w'],",
            $source,
            'stderr must be opened on a FILE sink (the diagnostic capture file, never a pipe)',
        );
        $this->assertSame(
            2,
            substr_count($source, "['pipe', '"),
            'exactly two pipes in the descriptor spec — stdin and stdout, never stderr',
        );
        $this->assertMatchesRegularExpression(
            '/\$this->stdout = \$pipes\[1\];/',
            $source,
            'the decoder must capture ONLY the stdout pipe',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$pipes\[2\]/',
            $source,
            'no code path may capture a stderr pipe — stderr is a file sink by design',
        );
    }

    private function timeoutBinaryAvailable(): bool
    {
        $status = 127;
        exec('command -v timeout >/dev/null 2>&1', $out, $status);

        return $status === 0;
    }
}
