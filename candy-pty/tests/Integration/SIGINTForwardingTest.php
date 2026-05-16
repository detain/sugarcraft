<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PtySystemFactory;

/**
 * Confirms `\x03` (Ctrl+C / ETX) written to the master is delivered as
 * SIGINT to a child whose controlling terminal is the PTY slave.
 *
 * This is the canonical TIOCSCTTY shim regression: without
 * controllingTerminal:true (which calls ioctl(TIOCSCTTY) post-fork) the
 * `\x03` byte arrives as plain data and `sleep 30` happily ignores it
 * until the wallclock budget trips. With the shim, the kernel line
 * discipline turns `\x03` into SIGINT for the slave's foreground pgroup
 * — i.e. `sleep` — and the child exits within ~1 s.
 *
 * Mirrors creack/pty's `TestCtrlC` integration check.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.5)
 */
final class SIGINTForwardingTest extends TestCase
{
    private const SLEEP_PATH = '/bin/sleep';
    private const WALLCLOCK_BUDGET_SEC = 3.0;

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
            $this->markTestSkipped('ext-pcntl is required to fork the sleep child.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testCtrlCByteKillsSleepThroughControllingTerminal(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable(self::SLEEP_PATH)) {
            $this->markTestSkipped(\sprintf('sleep not installed at %s', self::SLEEP_PATH));
        }

        $start = \microtime(true);

        $system = PtySystemFactory::default();
        $pair = $system->open(80, 24);
        $master = $pair->master();
        $child = null;

        try {
            // sleep 30 — far past the wallclock budget so we KNOW any
            // success here came from SIGINT, not the sleep timing out.
            $child = $pair->slave()->spawn(
                [self::SLEEP_PATH, '30'],
                [
                    'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
                    'LANG' => 'C',
                    'LC_ALL' => 'C',
                ],
                80,
                24,
                controllingTerminal: true,
            );

            \stream_set_blocking($master->stream(), false);

            // Settle window so the shim's setsid + ioctl(TIOCSCTTY) +
            // pcntl_exec("sleep") sequence finishes BEFORE we send the
            // signal byte. Empirically 50 ms is too tight on this host
            // (the byte lands in the pre-exec PHP runtime's stdin and
            // gets swallowed by the kernel line discipline before sleep
            // ever installs its default SIGINT handler); 200 ms is the
            // smallest value that wins the race reliably on slow CI.
            \usleep(200_000);

            $writeTs = \microtime(true);
            $master->write("\x03");

            // Poll exited() for up to 1 s — the plan-mandated window.
            $exitDeadline = $writeTs + 1.0;
            while (\microtime(true) < $exitDeadline && !$child->exited()) {
                // Drain any noise the slave emitted (TTY may echo "^C"
                // depending on cooked-mode ECHOCTL) so the master ring
                // doesn't fill while we're polling.
                $master->read(4096, 0.05);
            }

            $this->assertTrue(
                $child->exited(),
                'sleep 30 must exit within 1 s of \\x03 — TIOCSCTTY shim regression?',
            );

            $exit = $child->wait();

            // SIGINT-killed processes conventionally exit with 128+2 = 130
            // but the exact code varies by shell wrapper, libc, OS. The
            // load-bearing assertion is "non-zero" — a naturally-completed
            // `sleep 30` would have exited 0, which we'd never see inside
            // the 3 s budget anyway.
            $this->assertNotSame(
                0,
                $exit,
                'sleep exited 0 — SIGINT was swallowed, child likely ran to natural completion',
            );

            $elapsed = \microtime(true) - $start;
            $this->assertLessThan(
                self::WALLCLOCK_BUDGET_SEC,
                $elapsed,
                'SIGINTForwardingTest exceeded its 3 s wallclock budget',
            );
        } finally {
            if ($child !== null && !$child->exited()) {
                // Failure path: SIGINT didn't take. SIGKILL the sleep
                // ourselves so we don't leak a 30 s ghost into the suite.
                try {
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
            if (!$master->isClosed()) {
                $master->close();
            }
        }
    }
}
