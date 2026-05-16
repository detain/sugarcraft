<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PtySystemFactory;

/**
 * Stress the master ⇄ slave throughput by pushing 1 MB of pseudo-random
 * ASCII through `cat` and asserting the round-trip is byte-identical.
 *
 * Cat is invoked through a tiny `stty raw -echo; exec cat` wrapper so
 * the kernel line discipline is bypassed: without raw mode the
 * canonical buffer caps line length at MAX_CANON (255 bytes on Linux)
 * and a single 1 MB write would deadlock. With raw mode every byte
 * written to master arrives at cat's stdin literally, cat writes it
 * back to stdout, and the master read returns exactly what we put in.
 *
 * `controllingTerminal:false` skips the TIOCSCTTY shim — cat doesn't
 * care about ctty and skipping the shim shaves a fork cost.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.4)
 */
final class LargeBufferTest extends TestCase
{
    private const BASH_PATH = '/usr/bin/bash';
    private const CAT_PATH = '/usr/bin/cat';
    private const PAYLOAD_BYTES = 1_048_576;
    private const WALLCLOCK_BUDGET_SEC = 8.0;

    /**
     * Generous flush deadline (longer than the 0.5 s
     * {@see \SugarCraft\Pty\PumpOptions::DEFAULT_FLUSH_DEADLINE_SEC})
     * so slow CI runners can drain 1 MB through the PTY without a
     * spurious early exit.
     */
    private const FLUSH_DEADLINE_SEC = 5.0;

    /**
     * POSIX + FFI prerequisites — must run before any PTY syscall.
     * Mirrors {@see InteractiveShellTestCase::requirePtySyscalls()}.
     */
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required to fork the cat child.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testOneMegabyteRoundTripsByteForByte(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable(self::BASH_PATH)) {
            $this->markTestSkipped(\sprintf('bash not installed at %s', self::BASH_PATH));
        }
        if (!\is_executable(self::CAT_PATH)) {
            $this->markTestSkipped(\sprintf('cat not installed at %s', self::CAT_PATH));
        }

        // Deterministic printable-ASCII payload — bin2hex gives us 2x
        // the random bytes as 0-9a-f, which is what we want: no \r/\n,
        // no NUL, no SGR or control bytes that the PTY would mangle if
        // raw mode ever got reset mid-flight.
        $payload = \bin2hex(\random_bytes((int) (self::PAYLOAD_BYTES / 2)));
        $this->assertSame(self::PAYLOAD_BYTES, \strlen($payload));

        $system = PtySystemFactory::default();
        $pair = $system->open(80, 24);
        $master = $pair->master();
        $child = null;

        $start = \microtime(true);

        try {
            // `stty raw -echo` flips ICANON + ECHO off so the master
            // write isn't capped at MAX_CANON and bytes don't get
            // doubled by kernel echo. `exec cat` replaces bash in
            // place so the child PID we wait on IS cat.
            $child = $pair->slave()->spawn(
                [self::BASH_PATH, '-c', 'stty raw -echo; exec cat'],
                [
                    'TERM' => 'xterm-256color',
                    'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
                    'LANG' => 'C',
                    'LC_ALL' => 'C',
                ],
                80,
                24,
                controllingTerminal: false,
            );

            \stream_set_blocking($master->stream(), false);

            // Give the wrapper a few ms to invoke stty + exec into cat
            // before we start hammering the slave with bytes.
            \usleep(50_000);

            // Drive bytes out one chunk at a time while concurrently
            // draining echo from the master. A pure write-then-read
            // loop would block when the kernel TTY buffer fills — the
            // slave-input pipe (master→cat) and slave-output pipe
            // (cat→master) are each ~4 KB on Linux, so we have to
            // round-trip drain on every write or back-pressure
            // deadlocks the loop at ~9 buffer-fulls.
            $captured = '';
            $written = 0;
            $chunk = 4096;
            $writeDeadline = \microtime(true) + self::FLUSH_DEADLINE_SEC;
            $stream = $master->stream();

            while ($written < self::PAYLOAD_BYTES) {
                if (\microtime(true) > $writeDeadline) {
                    $this->fail(\sprintf(
                        'write loop missed flushDeadlineSec (wrote %d / %d bytes, captured %d)',
                        $written,
                        self::PAYLOAD_BYTES,
                        \strlen($captured),
                    ));
                }

                $slice = \substr($payload, $written, $chunk);
                $n = $master->write($slice);
                if ($n > 0) {
                    $written += $n;
                }

                // Aggressive drain: keep reading until master is no
                // longer immediately readable. Without this the slave's
                // tty output buffer fills and back-pressures cat, which
                // back-pressures our writer.
                while (true) {
                    $r = [$stream]; $w = null; $e = null;
                    $ready = @\stream_select($r, $w, $e, 0, 0);
                    if ($ready !== 1) {
                        break;
                    }
                    $tail = @\fread($stream, 65536);
                    if (!\is_string($tail) || $tail === '') {
                        break;
                    }
                    $captured .= $tail;
                }

                if ($n === 0) {
                    // Master write side full — wait for either read or
                    // write readiness so we make forward progress
                    // instead of busy-looping.
                    $r = [$stream]; $w = [$stream]; $e = null;
                    @\stream_select($r, $w, $e, 0, 10_000);
                }
            }

            // Sending VEOF (`\x04`) in raw mode is just a literal byte,
            // not an EOF — cat sees it as data. To make cat exit we
            // either close stdin (deliver EOF) or kill it. We close
            // master in the finally, but first send VEOF as the spec
            // requires so the byte stream finalises predictably; then
            // we shut the master to signal real EOF.
            $master->write("\x04");

            // Drain residual until cat has echoed every byte we wrote
            // plus the trailing VEOF, or the flush deadline trips.
            $expected = self::PAYLOAD_BYTES + 1; // +1 for VEOF byte
            $drainDeadline = \microtime(true) + self::FLUSH_DEADLINE_SEC;
            while (\strlen($captured) < $expected && \microtime(true) < $drainDeadline) {
                $tail = $master->read(65536, 0.05);
                if ($tail === null) {
                    continue;
                }
                if ($tail === '') {
                    break;
                }
                $captured .= $tail;
            }

            // Truncate the trailing VEOF byte before asserting parity.
            $head = \substr($captured, 0, self::PAYLOAD_BYTES);
            $this->assertSame(
                self::PAYLOAD_BYTES,
                \strlen($head),
                \sprintf(
                    'expected to read back %d bytes; got %d (captured total %d)',
                    self::PAYLOAD_BYTES,
                    \strlen($head),
                    \strlen($captured),
                ),
            );
            $this->assertSame(
                $payload,
                $head,
                'cat must have echoed every byte we wrote back to the master byte-for-byte',
            );

            $elapsed = \microtime(true) - $start;
            $this->assertLessThan(
                self::WALLCLOCK_BUDGET_SEC,
                $elapsed,
                'LargeBufferTest exceeded its 8 s wallclock budget',
            );
        } finally {
            if (!$master->isClosed()) {
                // Closing the master delivers SIGHUP + EOF to the
                // slave, letting cat exit cleanly.
                $master->close();
            }
            if ($child !== null && !$child->exited()) {
                try {
                    // Belt-and-braces: kill in case cat ignored SIGHUP.
                    $child->kill(MasterPty::SIGKILL);
                } catch (\Throwable) {
                    // Ignore — process may have raced to exit.
                }
                try {
                    $child->wait();
                } catch (\Throwable) {
                    // Ignore — wait may fail if pcntl already reaped.
                }
            }
        }
    }
}
