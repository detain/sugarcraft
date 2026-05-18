<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PtySystemFactory;

/**
 * Counterpart to {@see SIGINTForwardingTest}: with
 * `controllingTerminal: false` (the default), the child does NOT claim
 * the slave PTY as its ctty, so the kernel line discipline cannot
 * translate `\x03` into SIGINT for the child's process group. Writing
 * Ctrl+C to the master must arrive as plain data and `sleep 30` must
 * survive — proving the opt-in nature of the TIOCSCTTY shim path.
 *
 * Why it matters: many candy-pty consumers (record-and-replay, pump,
 * Expect) deliberately spawn WITHOUT a controlling terminal because
 * they don't want job-control signals leaking from the master writer
 * into the captured process. Regressing the default to "always claim
 * ctty" would silently break those flows.
 *
 * Mirrors the watchdog convention from `SIGINTForwardingTest`'s
 * `finally` block.
 *
 * @see plans/leftover/phase-01-pty-quickwins/step-12-signal-forwarder-tests.md
 */
final class NoControllingTerminalTest extends TestCase
{
    private const SLEEP_PATH = '/bin/sleep';
    private const WALLCLOCK_BUDGET_SEC = 4.0;

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

    public function testCtrlCByteDoesNotKillSleepWithoutControllingTerminal(): void
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
            // controllingTerminal: false (the default) — no shim wrap,
            // no setsid, no TIOCSCTTY. The child's stdio is the slave
            // PTY but the slave is NOT its ctty.
            $child = $pair->slave()->spawn(
                [self::SLEEP_PATH, '30'],
                [
                    'PATH' => \getenv('PATH') ?: '/usr/bin:/bin',
                    'LANG' => 'C',
                    'LC_ALL' => 'C',
                ],
                80,
                24,
                controllingTerminal: false,
            );

            \stream_set_blocking($master->stream(), false);

            // Same settle window as the SIGINT test: by 200 ms the
            // child has fork+exec'd and installed sleep's default
            // SIGINT disposition. We need this even though we're
            // EXPECTING no signal delivery — so the assertion below
            // isn't accidentally true because the child wasn't ready.
            \usleep(200_000);

            $master->write("\x03");

            // Drain the slave-side echo (if any) so the ring doesn't
            // back-pressure; then sleep 1 s and assert sleep still
            // running. The 1 s mirrors the SIGINT test's deadline so
            // a real signal — were one to fire — would have shown up.
            $drainDeadline = \microtime(true) + 1.0;
            while (\microtime(true) < $drainDeadline) {
                $master->read(4096, 0.05);
                if ($child->exited()) {
                    break;
                }
            }

            $this->assertFalse(
                $child->exited(),
                'sleep 30 exited within 1 s of \\x03 — controlling-terminal opt-in is LEAKING. '
                . 'The TIOCSCTTY shim must run only when controllingTerminal:true is requested.',
            );

            // Now clean up explicitly with SIGTERM so the assertion
            // window doesn't roll into the wallclock budget.
            $child->kill(MasterPty::SIGTERM);
            $waitDeadline = \microtime(true) + 1.0;
            while (\microtime(true) < $waitDeadline && !$child->exited()) {
                \usleep(10_000);
            }
            $this->assertTrue(
                $child->exited(),
                'sleep did not respond to SIGTERM within 1 s — child reaper regressed.',
            );

            $exit = $child->wait();
            $this->assertNotSame(0, $exit, 'sleep exited 0 after SIGTERM — exit code regressed.');

            $elapsed = \microtime(true) - $start;
            $this->assertLessThan(
                self::WALLCLOCK_BUDGET_SEC,
                $elapsed,
                'NoControllingTerminalTest exceeded its wallclock budget',
            );
        } finally {
            if ($child !== null && !$child->exited()) {
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
