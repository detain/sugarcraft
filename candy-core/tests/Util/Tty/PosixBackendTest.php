<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use SugarCraft\Core\Util\Tty\PosixBackend;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixTermios;
use SugarCraft\Pty\TermiosFactory;
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

    /**
     * `enableRawMode()` driven through candy-pty's `stty` TERMIOS backend on a
     * real pty, end to end: cooked, then raw, then cooked again.
     *
     * ## This test did not run for the whole of its life, and the gate that
     * stopped it was an instance of the defect family it sits inside
     *
     * WHAT THE GATE SAID: open a `php://memory` stream, cast it to `int`,
     * `fclose()` it, and skip unless `/dev/fd/<that int>` is readable or a
     * symlink. TWO independent reasons it could never answer yes, and a round
     * that fixes only the first will watch the test go on skipping:
     *
     *   1. `(int) $stream` is PHP's RESOURCE ID, not a file descriptor. MEASURED
     *      on this box, PHP 8.3.6: the cast answered 15 while the process's
     *      lowest free descriptor was 4, and `/dev/fd/15` was neither readable
     *      nor a link. This is the same cast `size()` and `enableRawMode()` were
     *      both fixed for, which is what makes the gate a member of the family.
     *   2. the handle was `fclose()`d on the line BEFORE the path was probed, so
     *      even a correct descriptor would have named a closed one.
     *
     * WHAT IS TRUE NOW: the probe resolves a GENUINE descriptor with
     * {@see PosixBackend::descriptorForStream()} and holds the handle open
     * across the `is_readable()`/`is_link()` call. MEASURED, same box and
     * version, same run as the numbers above: real fd 4, `/dev/fd/4` readable
     * and a link, gate open.
     *
     * ## Why the probe is a `tmpfile()` and not `/dev/null`
     *
     * `descriptorForStream()`'s second arm identifies a descriptor naming the
     * SAME DEVICE as the stream and prefers the lowest match -- it says so in
     * its own doc-block. `/dev/null` is the most-shared device on the box, so
     * with that as the probe the answer is whatever OTHER `/dev/null`
     * descriptor happens to be lower. MEASURED, PHP 8.3.6: with the process's
     * stdin `< /dev/null` -- which is how CI runs it and how this suite's own
     * child harnesses spawn `phpunit` -- `descriptorForStream(fopen('/dev/null',
     * 'r'))` answered **0**, i.e. stdin's descriptor, with `/dev/null` present
     * on fds `0,4`. With stdin a pipe it answered 4, the probe's own.
     *
     * That made the "handle stays open" half of the repair above non-load-
     * bearing under exactly the shape CI uses: the descriptor being stat()ed
     * was not the one being closed. The gate still ANSWERED correctly there
     * (verified: `< /dev/null`, `OK (1 test, 9 assertions)`) -- it was right
     * for the wrong reason, which is the state a guard is in just before it
     * silently stops being right at all. A `tmpfile()` has an inode nothing
     * else in the process holds, so the resolution is unambiguous under both
     * stdin shapes (MEASURED: fd 5 in both), and the identity is now ASSERTED
     * rather than assumed wherever procfs can settle it.
     *
     * WHY THERE IS STILL A GATE AT ALL: `SttyTermios` addresses the terminal as
     * `stty -F /dev/fd/<n>` (`-f` on Darwin), so a host whose `/dev/fd` is not a
     * live view of the calling process's descriptor table cannot run this at
     * all. That is a real portability condition, unlike the one it replaces.
     *
     * ## What it asserts, and why the `stty -a` reading rather than the echo
     *
     * The original body asserted only that `/bin/cat` echoed `hello` back with
     * no CR in it. That is evidence, but it is indirect: it cannot tell "raw
     * mode was applied" from "the flags happened to suit". The device is read
     * directly instead, with {@see SttyReading}'s whole-word matcher, at three
     * points -- before, raw, restored -- so both transitions are asserted and
     * the reading is taken through a path (`stty -a` on the slave PATH) that
     * shares no code with the mechanism under test (`stty` on `/dev/fd/<n>`).
     * `SttyReading::cookedFixture()` goes through the same matcher in the same
     * test, because a matcher mutated to match nothing reports "not raw"
     * forever and every absence-shaped assertion here would stay green.
     *
     * The third reading is the one that mattered: it is what caught
     * `PosixBackend::restore()` calling `apply()` on the snapshot, which is a
     * no-op under this backend and left the terminal raw. See that method's
     * doc-block for the measurement.
     *
     * `TermiosFactory::which()` is asserted first so the test cannot silently
     * become an FFI test with an `stty` name if the env var is ever ignored.
     */
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
        if (!is_executable('/bin/cat')) {
            $this->markTestSkipped('/bin/cat is not available to echo through the slave.');
        }

        // Can `stty` address a descriptor of THIS process as /dev/fd/<n>?
        // A genuine descriptor, and the handle stays open until after the
        // probe - see the doc-block for the two ways the old gate got this
        // wrong and why fixing either one alone changes nothing.
        //
        // tmpfile() rather than /dev/null: descriptorForStream()'s same-device
        // arm prefers the LOWEST descriptor naming the device, and under
        // `< /dev/null` that is stdin, not the probe. See the doc-block.
        $probe = tmpfile();
        $this->assertIsResource($probe);
        $probeUri = stream_get_meta_data($probe)['uri'] ?? null;

        try {
            $probeFd = PosixBackend::descriptorForStream($probe);
            $probePath = '/dev/fd/' . $probeFd;
            clearstatcache(true, $probePath);
            $fdDirectoryIsLive = $probeFd !== null
                && (is_readable($probePath) || is_link($probePath));

            // The claim above is that the resolved descriptor is the PROBE's.
            // Where procfs can settle that, settle it rather than assert it in
            // prose: this is what /dev/null could not have satisfied.
            if ($fdDirectoryIsLive && is_string($probeUri) && is_dir('/proc/self/fd')) {
                self::assertSame(
                    $probeUri,
                    readlink('/proc/self/fd/' . $probeFd),
                    'the gate probe resolved a descriptor belonging to something else. '
                    . 'descriptorForStream() prefers the lowest descriptor naming the same device, '
                    . 'so a probe on a shared device answers with a stranger fd and the '
                    . '"hold the handle open" half of this gate stops meaning anything.',
                );
            }
        } finally {
            fclose($probe);
        }

        if (!$fdDirectoryIsLive) {
            // A guard must go red on what it cannot account for rather than
            // skip it. On Linux `/dev/fd` is a symlink to `/proc/self/fd`
            // (MEASURED on this box: `readlink('/dev/fd')` is
            // `/proc/self/fd`), so with procfs mounted the probe above CANNOT
            // legitimately answer no -- if it does, the probe is broken and
            // the whole point of this test is that a broken probe is how it
            // spent its life not running.
            if (PHP_OS_FAMILY === 'Linux' && is_dir('/proc/self/fd') && is_dir('/dev/fd')) {
                self::fail(
                    'the /dev/fd probe answered no on a Linux host with procfs mounted and /dev/fd '
                    . 'present. That is the probe, not the host: on Linux /dev/fd is a symlink to '
                    . '/proc/self/fd and a descriptor held open is always visible through it. '
                    . 'Suspect a resource-id cast where a descriptor belongs, or a handle closed '
                    . 'before the path is stat()ed - this test skipped for its entire life on '
                    . 'exactly those two mistakes.',
                );
            }

            $this->markTestSkipped('/dev/fd/<n> is not a live view of this process on this host.');
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

            $backend = null;

            try {
                $slaveFd = PosixBackend::descriptorForStream($slave);
                $this->assertNotNull($slaveFd, 'no descriptor resolved for the pty slave');
                $this->assertSame(
                    'SttyTermios',
                    TermiosFactory::which($slaveFd),
                    'SUGARCRAFT_TERMIOS=stty did not select the stty backend - this test would be '
                    . 'exercising the FFI path under an stty name',
                );

                // Known-positive control for the matcher every assertion below
                // depends on: a synthetic COOKED reading carrying the negated
                // ECHONL/ECHOPRT lookalikes. A matcher that matches nothing
                // calls this raw; a substring matcher calls it raw too.
                $this->assertFalse(
                    SttyReading::isRaw(SttyReading::cookedFixture()),
                    'the raw-mode matcher reported a cooked reading as raw - every other assertion '
                    . 'in this test is worthless until that is fixed',
                );

                $this->assertFalse(
                    SttyReading::isRaw(SttyReading::of($slavePath)),
                    'a freshly opened pty slave was already in raw mode',
                );

                $backend = new PosixBackend($slave);
                $backend->enableRawMode();

                $this->assertTrue(
                    SttyReading::isRaw(SttyReading::of($slavePath)),
                    'enableRawMode() through the stty backend did not clear ICANON and ECHO',
                );

                $child = $pair->slave()->spawn(['/bin/cat']);
                $master->write("hello\n");
                $captured = '';
                // Generous rather than tight: this is a liveness bound on a
                // forked child on a box that runs several suites at once, not
                // a performance budget. The loop exits as soon as the line
                // arrives, which is ~10 ms in practice.
                $deadline = \microtime(true) + 5.0;
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

                $backend->restore();

                $this->assertFalse(
                    SttyReading::isRaw(SttyReading::of($slavePath)),
                    'restore() left the terminal in raw mode. Under the stty backend, apply() on a '
                    . 'current() snapshot is a no-op - PosixBackend::restore() must call restore().',
                );
            } finally {
                // Idempotent: restore() returns immediately once $saved is null,
                // so the in-test restore above is not undone or repeated.
                $backend?->restore();
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
