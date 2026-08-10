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
 * path). This file isolates the underlying mechanism against a real PTY:
 * a forked child that inherits a raw-mode-enabled Tty object and exits via
 * a plain `exit()` restores the terminal's ORIGINAL (cooked/echo) settings
 * on the way out, because that inherited object's destructor fires during
 * PHP's normal shutdown sequence and termios lives on the shared kernel TTY
 * device, not per-process. {@see ForkedChild::exitNow()} is the fix.
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
    private const O_RDWR = 0x0002;

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
        // coreutils uses uppercase (-F). Using the wrong one silently
        // fails (empty output), which reads as "not raw" regardless of
        // the real terminal state.
        $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
        $out = trim((string) shell_exec('stty ' . $flag . ' ' . escapeshellarg($slavePath) . ' -a 2>/dev/null'));

        // A raw-mode tty reports "-icanon -echo" (leading dash = disabled);
        // cooked mode reports the bare "icanon echo" flags instead.
        return str_contains($out, '-icanon') && str_contains($out, '-echo');
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

        $tty = new Tty(null, new PosixTermios($slaveFd));
        $tty->enableRawMode();

        try {
            $this->assertTrue($this->isRaw($slavePath), 'setup: raw mode must be active before forking');

            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed - cannot exercise this path');

            if ($pid === 0) {
                // Child inherits the SAME $tty object (raw mode already
                // enabled, $saved already populated) - exactly the shape
                // EngineBackend::runCompleteInChild()/Chat::forkToolCalls()
                // are in when they end. Using the fix here, not a bare exit().
                ForkedChild::exitNow(0);
            }

            $status = 0;
            pcntl_waitpid($pid, $status);

            $this->assertTrue(
                $this->isRaw($slavePath),
                'the real terminal was knocked out of raw mode by the forked child exiting - ' .
                'ForkedChild::exitNow() failed to bypass the destructor that undoes it',
            );
        } finally {
            $tty->restore();
            $libc->close($slaveFd);
            $pair->master()->close();
        }
    }

    /**
     * Documents the bug this whole file guards against: without the fix, a
     * forked child that ends with a PLAIN exit() DOES clobber the parent's
     * real terminal, because its inherited Tty object's destructor restores
     * the pre-raw-mode termios onto the shared TTY device. This is exactly
     * what EngineBackend::runCompleteInChild()/Chat::forkToolCalls() did
     * before switching to ForkedChild::exitNow().
     */
    public function testPlainExitInAForkedChildDoesClobberRawModeUnfixed(): void
    {
        $this->requirePtySyscalls();

        $pair = (new PosixPtySystem())->open();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();
        $slaveFd = $libc->open($slavePath, self::O_RDWR);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        $tty = new Tty(null, new PosixTermios($slaveFd));
        $tty->enableRawMode();

        try {
            $this->assertTrue($this->isRaw($slavePath), 'setup: raw mode must be active before forking');

            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed - cannot exercise this path');

            if ($pid === 0) {
                exit(0);
            }

            $status = 0;
            pcntl_waitpid($pid, $status);

            $this->assertFalse(
                $this->isRaw($slavePath),
                'expected the OLD, unfixed exit() pattern to clobber raw mode - ' .
                'if this fails, the underlying PHP/OS behaviour this whole fix relies on has changed',
            );
        } finally {
            // Raw mode is already gone at this point (that's what this test
            // demonstrates) - nothing to restore explicitly, but Tty's own
            // destructor will still fire and try to; unset it FIRST, while
            // $slaveFd is still open, so that lands cleanly instead of
            // throwing from a destructor against an already-closed fd.
            unset($tty);
            $libc->close($slaveFd);
            $pair->master()->close();
        }
    }
}
