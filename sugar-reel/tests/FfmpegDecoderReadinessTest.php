<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\FfmpegDecoder;
use SugarCraft\Reel\Source\Probe;
use SugarCraft\Reel\Support\BoundedReaper;

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
 *  - a child that is alive-and-wedged still dies within the BoundedReaper
 *    ceiling once close() is reached (teardown polarity) — driven through a
 *    leashed harness so a regression that removes the ladder fails FAST
 *    instead of hanging the suite (r69 law).
 *
 * @covers \SugarCraft\Reel\Decode\FfmpegDecoder
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
     * @testdox a child that dies with no output releases next() promptly with null
     *
     * EOF polarity of the pump contract: an ffmpeg that fails at open (empty
     * file is a regular path, so the spawn happens; the decode does not)
     * closes the pipe, and the blocking read returns null within moments —
     * the child's death IS the read's bound. The recorded exit code and its
     * error_log line (E366's "the recorded exit code keeps its meaning") are
     * captured and pinned too.
     */
    public function testNextReturnsNullPromptlyWhenChildDiesWithoutOutput(): void
    {
        if (!Probe::hasFFmpeg()) {
            $this->markTestSkipped('ffmpeg not present');
        }

        $base = (string) tempnam(sys_get_temp_dir(), 'sugar-reel-empty');
        $clip = $base . '.mp4';
        $this->assertTrue(rename($base, $clip), 'cannot name the empty-file fixture');
        $log = $clip . '.errlog';
        $this->errorLogBackup = (string) \ini_get('error_log');
        \ini_set('error_log', $log);

        $decoder = null;
        try {
            $decoder = new FfmpegDecoder();
            $decoder->open($clip, 8, 6, 10.0);

            $start = microtime(true);
            $this->assertNull($decoder->next(), 'a dead child must release the read as EOF');
            $this->assertLessThan(5.0, microtime(true) - $start, 'EOF release must be prompt');
            $this->assertNull($decoder->next(), 'EOF is stable');

            $decoder->close();
            $this->assertNotSame(0, $decoder->getExitCode(), 'ffmpeg must have failed on the empty file');

            $logged = is_file($log) ? (string) file_get_contents($log) : '';
            $this->assertStringContainsString(
                "FfmpegDecoder: ffmpeg exited with code {$decoder->getExitCode()}",
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
     * @testdox a live-but-silent child parks reads observably-yet-not-ready, and close() stays bounded
     *
     * Teardown polarity of the pump contract, against the wedge the class
     * docblock names: ffmpeg connected to a black-hole HTTP listener that
     * never answers, so the child is alive, silent, and blocked in its input
     * read. A zero-timeout stream_select on the very fd next() parks shows
     * NOT-ready (the hazard is real and observable without parking on it),
     * and close() still ends the child within the BoundedReaper ladder's
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

        $pidFile = (string) tempnam(sys_get_temp_dir(), 'sugar-reel-wedge-pid');
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
            // poll tick and process teardown.
            $this->assertLessThanOrEqual(4.5, (float) $m[1], 'close() must stay inside the BoundedReaper ceiling');

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
            // sugar-reel does not require ext-pcntl (BoundedReaper precedent).
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
     */
    public function testStderrIsAFileSinkNeverAPipe(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Decode/FfmpegDecoder.php');

        $this->assertStringContainsString(
            "['file', \$devNull, 'w'],",
            $source,
            'stderr must be opened on a FILE sink (the OS null device)',
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
