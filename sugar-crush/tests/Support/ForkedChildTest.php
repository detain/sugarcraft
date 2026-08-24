<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixTermios;
use SugarCraft\Core\Util\Tty;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the actual bug behind "typed text ends up in the
 * status bar / Enter and Ctrl+P stop working after the first message" (see
 * EngineBackendTest for the same assertion against the real production code
 * path). A forked child that inherits a raw-mode-enabled Tty object and
 * exits via a plain `exit()` used to restore the terminal's ORIGINAL
 * (cooked/echo) settings on the way out, because that inherited object's
 * destructor fired during PHP's normal shutdown sequence and termios lives
 * on the shared kernel TTY device, not per-process.
 *
 * This is now fixed at the ROOT, in candy-core itself:
 * `PosixBackend::restore()` records the PID that called `enableRawMode()`
 * and skips the real syscall when called from a different one (see that
 * method's docblock, and `candy-core/tests/Util/Tty/PosixBackendTest::
 * testChildProcessExitingDoesNotResetTheParentsRawMode()` for the
 * equivalent proof at that layer) - so a forked child ending in a bare
 * `exit()` is safe regardless of what calls it, with no special handling
 * required. {@see ForkedChild::exitNow()} (used by
 * `EngineBackend::runCompleteInChild()`/`Chat::forkToolCalls()`) remains as
 * defense-in-depth: it protects against ANY inherited object's destructor
 * with a real side effect, not just this one.
 *
 * Uses `Tty`'s injected-Termios test seam (a real fd obtained via candy-pty's
 * own FFI `open()`, mirroring `candy-pty/tests/Posix/PosixTermiosTest.php`)
 * rather than `fopen()` + `(int)` cast: `PosixBackend::enableRawMode()`
 * resolves a stream to an fd via `(int) $this->stream`, which is PHP's
 * internal resource ID, not the OS fd - it happens to coincide for a
 * process's original STDIN/STDOUT (both opened first, low IDs, and usually
 * the same physical terminal anyway) but not for a stream opened later in a
 * busy test process. That's a separate, pre-existing quirk of candy-core's
 * fd resolution - irrelevant to production (which only ever wraps the
 * process's real STDIN) and irrelevant to the actual bug this file guards;
 * the injected-Termios seam exists precisely to sidestep it in tests.
 */
final class ForkedChildTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private const O_RDWR = 0x0002;

    /**
     * Both children below leave the instant they are forked, and both are
     * waited for with a bounded {@see waitWithTimeout()} - so on the PASSING
     * path this reaper has nothing to do.
     *
     * It is here for the path where the assertion between the fork and the
     * wait fails, or where the per-test `pcntl_alarm()` fires: either one
     * unwinds past `waitWithTimeout()`, and the alarm reaches this process
     * only ({@see ReapsForkedChildrenTrait} for why). A child left behind by
     * one of those still holds an inherited copy of a raw-mode `Tty` over the
     * shared kernel pty this file is measuring, which is the one resource in
     * the suite where an orphan is not merely untidy.
     */
    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();

        parent::tearDown();
    }

    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required for termios FFI.');
        }
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required to fork a real child.');
        }
        if (!\function_exists('posix_kill') || !\function_exists('posix_getpid')) {
            $this->markTestSkipped('posix is required for this test.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    private function isRaw(string $slavePath): bool
    {
        // BSD/macOS stty takes the device flag lowercase (-f); GNU/Linux
        // coreutils uses uppercase (-F). Using the wrong one fails with an
        // EMPTY stdout, which is indistinguishable from "the terminal is
        // cooked" - so the message on fd 2 is the only thing that tells those
        // two apart, and it used to go to /dev/null.
        //
        // It cannot be folded into stdout with `2>&1` either: the return
        // value below is a substring match for `-icanon`/`-echo`, and stty's
        // diagnostic would be searched as if it were flag output. So fd 2
        // goes to a file this helper reads back and fails on.
        $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
        $stderrFile = (string) tempnam(sys_get_temp_dir(), 'sc_forkedchild_stty_');

        $out = trim((string) shell_exec(
            'stty ' . $flag . ' ' . escapeshellarg($slavePath) . ' -a 2>' . escapeshellarg($stderrFile),
        ));

        $stderr = trim((string) @file_get_contents($stderrFile));
        @unlink($stderrFile);

        self::assertSame(
            '',
            $stderr,
            'stty could not read the pty, so this helper cannot answer whether raw mode is set '
                . 'and a bare false would be read as "cooked": ' . $stderr,
        );

        // A raw-mode tty reports "-icanon -echo" (leading dash = disabled);
        // cooked mode reports the bare "icanon echo" flags instead.
        return str_contains($out, '-icanon') && str_contains($out, '-echo');
    }

    /**
     * A blocking pcntl_waitpid() has no bound - if the child were ever stuck
     * (a hung syscall, an environment where SIGKILL-to-self somehow doesn't
     * land) this test would hang the whole suite/CI job indefinitely rather
     * than failing with a clear message. Same WNOHANG-polling + deadline +
     * SIGKILL-fallback shape as Chat::waitForToolChildrenAsync()'s
     * synchronous sibling - just without the ReactPHP loop, since this test
     * doesn't need one.
     */
    private function waitWithTimeout(int $pid, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $status = 0;
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        if (function_exists('posix_kill')) {
            posix_kill($pid, SIGKILL);
        }
        $status = 0;
        pcntl_waitpid($pid, $status);
        $this->fail("forked child {$pid} did not exit within {$timeoutSeconds}s - had to SIGKILL it");
    }

    public function testExitNowLeavesTheRealTerminalsRawModeIntactAcrossAForkedChild(): void
    {
        $this->requirePtySyscalls();

        $pair = (new PosixPtySystem())->open();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();
        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        // THE STREAM ARGUMENT IS EXPLICIT, AND USED TO BE `null` (round 49,
        // lane e — a FORCED out-of-lane edit, named in that lane's report).
        // WHAT `null` DID: `Tty::__construct()` is `self::backend($stream ??
        // STDIN, $termios)`, so it wrapped THIS PROCESS's descriptor 0 — and
        // the injected-Termios branch of `PosixBackend::enableRawMode()` skips
        // its own `isTty()` guard, so its trailing
        // `@stream_set_blocking($this->stream, false)` and `restore()`'s
        // matching `(…, true)` both landed on the runner's fd 0, in a test
        // whose subject is a PTY it opened itself. Worse here than elsewhere:
        // this file FORKS while holding the `Tty`, so the child inherited it.
        //
        // MEASURED, PHP 8.3.6, three takes: with `null`, fd 0's `blocked` flag
        // goes true -> false across this seam (3/3); with an explicit stream it
        // never moves (3/3). `tests/bootstrap.php` repairs descriptor 0 with
        // exactly that flag, so `restore()` here was silently undoing it for
        // every later test in the run — see that file's write-up.
        //
        // A SOCKET PAIR rather than `php://memory`, and that is forced: PHP
        // reports a memory stream as blocked whatever is set on it, so it
        // cannot tell "the seam wrote the flag here" from "the seam wrote it
        // somewhere else". Asserted in BOTH directions below, which is what
        // makes a revert to `null` red rather than merely undetected.
        $flagSink = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($flagSink, 'no socket pair: this test cannot observe where the seam writes');
        $this->assertTrue(
            stream_get_meta_data($flagSink[0])['blocked'],
            'control: a stream nobody has touched must report blocked, or the probe below reads nothing',
        );

        $tty = new Tty($flagSink[0], new PosixTermios($slaveFd));
        $tty->enableRawMode();

        try {
            $this->assertFalse(
                stream_get_meta_data($flagSink[0])['blocked'],
                'enableRawMode() did not clear O_NONBLOCK on the stream it was GIVEN, so it wrote the flag '
                    . "somewhere else - on a null stream that somewhere else is the runner's descriptor 0",
            );
            $this->assertTrue($this->isRaw($slavePath), 'setup: raw mode must be active before forking');

            $pid = $this->forkTracked();
            $this->assertNotSame(-1, $pid, 'fork failed - cannot exercise this path');

            if ($pid === 0) {
                // Child inherits the SAME $tty object (raw mode already
                // enabled, $saved already populated) - exactly the shape
                // EngineBackend::runCompleteInChild()/Chat::forkToolCalls()
                // are in when they end. Using the fix here, not a bare exit().
                ForkedChild::exitNow(0);
            }

            $this->waitWithTimeout($pid, 5.0);

            $this->assertTrue(
                $this->isRaw($slavePath),
                'the real terminal was knocked out of raw mode by the forked child exiting - ' .
                'ForkedChild::exitNow() failed to bypass the destructor that undoes it',
            );
        } finally {
            $tty->restore();
            $this->assertTrue(
                stream_get_meta_data($flagSink[0])['blocked'],
                'restore() did not put O_NONBLOCK back on the stream it was given',
            );
            fclose($flagSink[0]);
            fclose($flagSink[1]);
            $libc->close($slaveFd);
            $pair->master()->close();
        }
    }

    /**
     * With the candy-core-level fix (PosixBackend::restore() is now
     * PID-aware), even a BARE exit() in the forked child - no
     * ForkedChild::exitNow(), no special handling at all - is safe.
     */
    public function testPlainExitInAForkedChildNoLongerClobbersRawMode(): void
    {
        $this->requirePtySyscalls();

        $pair = (new PosixPtySystem())->open();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();
        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        // THE STREAM ARGUMENT IS EXPLICIT, AND USED TO BE `null` (round 49,
        // lane e — a FORCED out-of-lane edit, named in that lane's report).
        // WHAT `null` DID: `Tty::__construct()` is `self::backend($stream ??
        // STDIN, $termios)`, so it wrapped THIS PROCESS's descriptor 0 — and
        // the injected-Termios branch of `PosixBackend::enableRawMode()` skips
        // its own `isTty()` guard, so its trailing
        // `@stream_set_blocking($this->stream, false)` and `restore()`'s
        // matching `(…, true)` both landed on the runner's fd 0, in a test
        // whose subject is a PTY it opened itself. Worse here than elsewhere:
        // this file FORKS while holding the `Tty`, so the child inherited it.
        //
        // MEASURED, PHP 8.3.6, three takes: with `null`, fd 0's `blocked` flag
        // goes true -> false across this seam (3/3); with an explicit stream it
        // never moves (3/3). `tests/bootstrap.php` repairs descriptor 0 with
        // exactly that flag, so `restore()` here was silently undoing it for
        // every later test in the run — see that file's write-up.
        //
        // A SOCKET PAIR rather than `php://memory`, and that is forced: PHP
        // reports a memory stream as blocked whatever is set on it, so it
        // cannot tell "the seam wrote the flag here" from "the seam wrote it
        // somewhere else". Asserted in BOTH directions below, which is what
        // makes a revert to `null` red rather than merely undetected.
        $flagSink = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($flagSink, 'no socket pair: this test cannot observe where the seam writes');
        $this->assertTrue(
            stream_get_meta_data($flagSink[0])['blocked'],
            'control: a stream nobody has touched must report blocked, or the probe below reads nothing',
        );

        $tty = new Tty($flagSink[0], new PosixTermios($slaveFd));
        $tty->enableRawMode();

        try {
            $this->assertFalse(
                stream_get_meta_data($flagSink[0])['blocked'],
                'enableRawMode() did not clear O_NONBLOCK on the stream it was GIVEN, so it wrote the flag '
                    . "somewhere else - on a null stream that somewhere else is the runner's descriptor 0",
            );
            $this->assertTrue($this->isRaw($slavePath), 'setup: raw mode must be active before forking');

            $pid = $this->forkTracked();
            $this->assertNotSame(-1, $pid, 'fork failed - cannot exercise this path');

            if ($pid === 0) {
                exit(0);
            }

            $this->waitWithTimeout($pid, 5.0);

            $this->assertTrue(
                $this->isRaw($slavePath),
                'the real terminal was knocked out of raw mode by a plain exit() in the forked child - ' .
                'the candy-core-level PID-aware restore() fix did not hold',
            );
        } finally {
            $tty->restore();
            $this->assertTrue(
                stream_get_meta_data($flagSink[0])['blocked'],
                'restore() did not put O_NONBLOCK back on the stream it was given',
            );
            fclose($flagSink[0]);
            fclose($flagSink[1]);
            $libc->close($slaveFd);
            $pair->master()->close();
        }
    }
}
