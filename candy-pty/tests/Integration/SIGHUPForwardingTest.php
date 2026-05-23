<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PtySystemFactory;

/**
 * Confirms closing the PTY master delivers SIGHUP to a session-leader
 * child whose controlling terminal is the PTY slave — the kernel's
 * `tty_hangup()` path firing on the last master close.
 *
 * Two bugs gated this for a long time:
 *
 * 1. `posix_openpt` returns a fd without `FD_CLOEXEC`. `proc_open`'s
 *    descriptor spec only wires fds 0-2 across fork+exec; every other
 *    parent fd inherits. The child held the master open, so the kernel
 *    refcount never dropped to 0 on parent close → no SIGHUP.
 *
 * 2. `fopen('php://fd/N')` calls `dup(N)` and wraps the duplicate
 *    (see php-src plain_wrapper.c). `fclose($stream)` only closed the
 *    duplicate; the original posix_openpt fd in the parent stayed open.
 *
 * Both fixes (FD_CLOEXEC on the master + fall-through libc close after
 * fclose) are required — verified by isolating either fix in turn:
 * with only one, the child still survived >2 s. With both, `sleep 30`
 * exits within ~20 ms of master close.
 *
 * Mirrors creack/pty's `TestClose` integration check and the same
 * watchdog convention as `SIGINTForwardingTest`.
 *
 * @see plans/leftover/phase-01-pty-quickwins/step-12-signal-forwarder-tests.md
 */
final class SIGHUPForwardingTest extends TestCase
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

    public function testMasterCloseHangsUpSessionLeaderChild(): void
    {
        $this->requirePtySyscalls();
        if (!\is_executable(self::SLEEP_PATH)) {
            $this->markTestSkipped(\sprintf('sleep not installed at %s', self::SLEEP_PATH));
        }
        // Darwin's tty_hangup() semantics diverge from Linux's: master
        // close does not synchronously deliver SIGHUP to the session
        // leader within the 1 s window this test enforces. The FD_CLOEXEC
        // + libc-close fixes documented above are Linux-kernel specific —
        // the equivalent macOS path needs separate investigation.
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('Darwin tty_hangup() timing diverges from Linux — needs separate macOS-side fix.');
        }

        $start = \microtime(true);

        $system = PtySystemFactory::default();
        $pair = $system->open(80, 24);
        $master = $pair->master();
        $child = null;

        try {
            // sleep 30 — far past the wallclock budget so we KNOW any
            // success here came from SIGHUP via tty_hangup(), not the
            // sleep timing out naturally.
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

            // Let the shim's setsid + TIOCSCTTY + pcntl_exec settle so
            // the kernel records `sleep` as the slave PTY's foreground
            // pgroup leader BEFORE we hang up. 200 ms mirrors the
            // SIGINT test's reliable settle window on slow CI.
            \usleep(200_000);

            $closeTs = \microtime(true);
            $master->close();

            // Poll exited() for up to 1 s — the plan-mandated window.
            // tty_hangup() runs synchronously inside the kernel close
            // path, so SIGHUP should be queued for `sleep` almost
            // immediately; the poll only buys time for sleep's signal
            // handler to dispatch and the proc_get_status() refresh.
            $exitDeadline = $closeTs + 1.0;
            while (\microtime(true) < $exitDeadline && !$child->exited()) {
                \usleep(10_000);
            }

            $this->assertTrue(
                $child->exited(),
                'sleep 30 must exit within 1 s of master close — '
                . 'tty_hangup() did not fire. Likely regressions: '
                . 'FD_CLOEXEC on master fd, or libc close after fclose in PosixMasterPty::close().',
            );

            $exit = $child->wait();
            // SIGHUP-killed processes conventionally exit 128+1 = 129
            // but shells/wrappers may translate. Load-bearing assertion
            // is "non-zero" — a sleep 30 that ran to natural completion
            // would have returned 0 and we'd never see it inside 3 s.
            $this->assertNotSame(
                0,
                $exit,
                'sleep exited 0 — SIGHUP was swallowed or never delivered.',
            );

            $elapsed = \microtime(true) - $start;
            $this->assertLessThan(
                self::WALLCLOCK_BUDGET_SEC,
                $elapsed,
                'SIGHUPForwardingTest exceeded its 3 s wallclock budget',
            );
        } finally {
            // Watchdog: if SIGHUP didn't take, SIGKILL the orphan
            // ourselves so we don't leak a 30 s ghost into the suite.
            if ($child !== null && !$child->exited()) {
                try {
                    $child->kill(MasterPty::SIGKILL);
                } catch (\Throwable) {
                    // Process may have raced to exit; ignore.
                }
                try {
                    $child->wait();
                } catch (\Throwable) {
                    // wait() may fail if pcntl already reaped; ignore.
                }
            }
            if (!$master->isClosed()) {
                $master->close();
            }
        }
    }
}
