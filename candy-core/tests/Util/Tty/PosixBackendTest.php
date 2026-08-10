<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixTermios;
use PHPUnit\Framework\TestCase;

final class PosixBackendTest extends TestCase
{
    public function testSizeFallsBackToReasonableDefaults(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new PosixBackend($r);

        $prevCols = getenv('COLUMNS');
        $prevRows = getenv('LINES');
        putenv('COLUMNS');
        putenv('LINES');

        try {
            $size = $tty->size();
            // Verify structure
            $this->assertIsArray($size);
            $this->assertArrayHasKey('cols', $size);
            $this->assertArrayHasKey('rows', $size);
            // Verify reasonable positive dimensions (>= 80 cols, >= 24 rows)
            $this->assertGreaterThanOrEqual(80, $size['cols']);
            $this->assertGreaterThanOrEqual(24, $size['rows']);
        } finally {
            if ($prevCols !== false) {
                putenv('COLUMNS=' . $prevCols);
            }
            if ($prevRows !== false) {
                putenv('LINES='   . $prevRows);
            }
            fclose($r);
        }
    }

    /**
     * Env COLUMNS/LINES are honored as the FALLBACK when there is no live
     * tty to ioctl (the stream here is a memory stream, so isTty() is false).
     * On a real tty the kernel ioctl takes precedence over these — env is
     * often stale across resizes — but that path can't be exercised here.
     */
    public function testSizeHonorsEnvWhenNotTty(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new PosixBackend($r);
        $this->assertFalse($tty->isTty(), 'memory stream must not be a tty for this test');

        $prevCols = getenv('COLUMNS');
        $prevRows = getenv('LINES');
        putenv('COLUMNS=132');
        putenv('LINES=50');

        try {
            $size = $tty->size();
            $this->assertSame(132, $size['cols']);
            $this->assertSame(50, $size['rows']);
        } finally {
            putenv('COLUMNS' . ($prevCols === false ? '' : '=' . $prevCols));
            putenv('LINES'   . ($prevRows === false ? '' : '=' . $prevRows));
            fclose($r);
        }
    }

    public function testIsTtyFalseForMemoryStream(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new PosixBackend($r);
        $this->assertFalse($tty->isTty());
        fclose($r);
    }

    public function testEnableAndRestoreRawModeNoOpOnNonTty(): void
    {
        $r = fopen('php://memory', 'r+');
        $this->assertNotFalse($r);
        $tty = new PosixBackend($r);
        $tty->enableRawMode();
        $tty->restore();
        $this->assertFalse($tty->isTty());
        fclose($r);
    }

    public function testOpenTtyReturnsPairOrNull(): void
    {
        $result = PosixBackend::openTty();
        // CI sandboxes may not expose /dev/tty — accept either branch.
        if ($result === null) {
            $this->assertNull($result);
            return;
        }
        $this->assertCount(2, $result);
        [$in, $out] = $result;
        $this->assertIsResource($in);
        $this->assertIsResource($out);
        $this->assertNotSame($in, $out);
        fclose($in);
        fclose($out);
    }

    public function testOnResizeNoOpWithoutPcntl(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->assertFalse(PosixBackend::onResize(static fn () => null));
            return;
        }
        // On posix with pcntl available, the install should succeed.
        $installed = PosixBackend::onResize(static fn () => null);
        $this->assertTrue($installed);
        // Restore default handler so the test doesn't leak a closure.
        if (defined('SIGWINCH')) {
            \pcntl_signal(SIGWINCH, SIG_DFL);
        }
    }

    public function testDrainSignalsReturnsIntOrFalse(): void
    {
        $result = PosixBackend::drainSignals();
        // Returns int (0 or SIGNAL_RESIZE=2) when pcntl is available,
        // or false when pcntl_signal_dispatch does not exist.
        $this->assertTrue(\is_int($result) || $result === false);
    }

    public function testRawModeWithSttyFallbackOnRealPty(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('PosixBackend is POSIX-only.');
        }
        if (!is_readable('/dev/ptmx') || !is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!function_exists('posix_isatty')) {
            $this->markTestSkipped('posix_isatty is not available.');
        }

        // Check that stty can actually use /dev/fd/ for a real fd
        $testFd = fopen('php://memory', 'r+');
        if ($testFd === false) {
            $this->markTestSkipped('Could not open test stream');
        }
        $fd = (int) $testFd;
        fclose($testFd);
        $sttyTestPath = '/dev/fd/' . $fd;
        if (!is_readable($sttyTestPath) && !is_link($sttyTestPath)) {
            $this->markTestSkipped('/dev/fd/<n> is not accessible in this environment.');
        }

        $prevTermios = getenv('SUGARCRAFT_TERMIOS');
        putenv('SUGARCRAFT_TERMIOS=stty');
        try {
            $system = new PosixPtySystem();
            $pair = $system->open();
            $master = $pair->master();
            $slavePath = $pair->slave()->path();

            $slave = fopen($slavePath, 'r+');
            if ($slave === false) {
                $this->markTestSkipped('Could not open PTY slave path: ' . $slavePath);
            }

            try {
                $backend = new PosixBackend($slave);
                $backend->enableRawMode();

                $child = $pair->slave()->spawn(['/bin/cat']);
                $master->write("hello\n");
                $captured = '';
                $deadline = \microtime(true) + 2.0;
                while (\microtime(true) < $deadline) {
                    $chunk = $master->read(4096, 0.1);
                    if ($chunk === null || $chunk === '') {
                        \usleep(10_000);
                        continue;
                    }
                    $captured .= $chunk;
                    if (\str_contains($captured, "hello\n")) {
                        break;
                    }
                }
                $child->kill(\SIGTERM);
                $child->wait();

                $this->assertStringContainsString('hello', $captured, 'cat should have received input');
                $this->assertStringNotContainsString("\r", $captured, 'raw mode should have no CR from echo');
            } finally {
                $backend->restore();
                fclose($slave);
                $master->close();
            }
        } finally {
            if ($prevTermios === false) {
                putenv('SUGARCRAFT_TERMIOS');
            } else {
                putenv('SUGARCRAFT_TERMIOS=' . $prevTermios);
            }
        }
    }

    /**
     * Regression: a `pcntl_fork()`'d child inherits a COPY of a raw-mode
     * PosixBackend, with $saved already populated. Termios settings live
     * on the shared kernel TTY device, not per-process - before this fix,
     * a plain `exit()` in that child ran PHP's normal shutdown sequence,
     * destructing the inherited PosixBackend and applying $saved (the
     * PARENT's pre-raw-mode termios) onto the REAL, shared terminal - i.e.
     * a forked child that does nothing but exit() would silently knock the
     * live parent process's terminal out of raw mode. Any consumer of this
     * class that ever forks while raw mode is active (sugar-crush's async
     * backend completion and tool-call execution both do) is exposed.
     * Fixed by recording the PID that called enableRawMode() and skipping
     * the real restore syscall when restore()/the destructor fires from a
     * DIFFERENT pid - see restore()'s docblock. Proven here with a real
     * PTY and a real fork(), asserting raw mode survives a plain exit()
     * in the child with no special handling required at all.
     */
    public function testChildProcessExitingDoesNotResetTheParentsRawMode(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('PosixBackend is POSIX-only.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required for termios FFI.');
        }
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required to fork a real child.');
        }
        if (!is_readable('/dev/ptmx') || !is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }

        $pair = (new PosixPtySystem())->open();
        $slavePath = $pair->slave()->path();

        $libc = Libc::lib();
        $slaveFd = $libc->open($slavePath, 0x0002 /* O_RDWR */);
        if ($slaveFd < 0) {
            $this->markTestSkipped('Could not open slave PTY path: ' . $slavePath);
        }

        // Injected Termios (real fd, obtained via candy-pty's own FFI
        // open()) rather than fopen() + PosixBackend's (int)-cast fd
        // resolution - that cast is PHP's internal resource ID, not the OS
        // fd, and only coincides with the real fd for a process's original
        // STDIN/STDOUT. Irrelevant to what this test is verifying.
        $backend = new PosixBackend(null, new PosixTermios($slaveFd));
        $backend->enableRawMode();

        try {
            $this->assertTrue($this->isRaw($slavePath), 'setup: raw mode must be active before forking');

            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed - cannot exercise this path');

            if ($pid === 0) {
                // Child inherits the SAME $backend object. A PLAIN exit() -
                // no special helper needed now that the fix lives here.
                exit(0);
            }

            $status = 0;
            pcntl_waitpid($pid, $status);

            $this->assertTrue(
                $this->isRaw($slavePath),
                'the real terminal was knocked out of raw mode by the forked child exiting',
            );
        } finally {
            $backend->restore();
            $libc->close($slaveFd);
            $pair->master()->close();
        }
    }

    private function isRaw(string $slavePath): bool
    {
        // BSD/macOS stty takes the device flag lowercase (-f); GNU/Linux
        // coreutils uses uppercase (-F). Using the wrong one silently
        // fails (empty output), which reads as "not raw" regardless of
        // the real terminal state - not what this helper is checking.
        $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
        $out = trim((string) shell_exec('stty ' . $flag . ' ' . escapeshellarg($slavePath) . ' -a 2>/dev/null'));

        return str_contains($out, '-icanon') && str_contains($out, '-echo');
    }
}
