<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Confirms that a PTY-spawned child whose parent exits without calling
 * `$child->wait()` is reaped within a couple of seconds — either by the
 * kernel (init reparents + waitpid-on-exit) or by candy-pty's
 * {@see \SugarCraft\Pty\Posix\ChildPollTrait::pollDestruct()} safety net.
 *
 * The test process itself cannot abandon its child (PHPUnit keeps the
 * runtime alive), so a small fixture subprocess does the abandon. We
 * spawn the fixture, capture the abandoned grandchild's PID from its
 * stdout, then poll until `/proc/<pid>` disappears (Linux) or until
 * `ps -p <pid>` reports no match (portable fallback). A residual zombie
 * (`State: Z` in `/proc/<pid>/status`) is also a failure — reaping
 * means the wait-status was collected, not just that the process
 * stopped running.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P5.5)
 */
final class OrphanedChildReapTest extends TestCase
{
    private const WALLCLOCK_BUDGET_SEC = 3.0;
    private const FIXTURE = __DIR__ . '/_fixtures/orphan-spawn.php';

    /**
     * POSIX + FFI prerequisites — the fixture itself opens a PTY, so
     * the test inherits all the same syscall constraints even though
     * the test body never touches `/dev/ptmx` directly. `posix_kill`
     * is the liveness probe of choice and lives in ext-posix.
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
            $this->markTestSkipped('ext-pcntl is required to fork the fixture child.');
        }
        if (!\extension_loaded('posix')) {
            $this->markTestSkipped('ext-posix is required to probe orphan liveness with kill(pid, 0).');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    public function testFixtureExitWithoutWaitLeavesNoLingeringChild(): void
    {
        $this->requirePtySyscalls();

        $procReadable = \is_dir('/proc') && \is_readable('/proc');
        $psPath = $this->locatePs();
        // `posix_kill($pid, 0)` is always available on POSIX (the
        // requirePtySyscalls() guard checks for ext-posix); we keep
        // /proc + ps as secondary signals for the zombie audit.

        $php = (string) (\PHP_BINARY ?: 'php');
        $this->assertFileExists(self::FIXTURE, 'orphan-spawn fixture missing');

        $start = \microtime(true);

        // proc_open with explicit pipes so we can read the PID and then
        // close the fixture cleanly via proc_close (which waits for the
        // fixture's php runtime, NOT its abandoned grandchild).
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = \proc_open(
            [$php, self::FIXTURE],
            $descriptors,
            $pipes,
            \dirname(self::FIXTURE),
        );
        $this->assertIsResource($proc, 'failed to launch orphan-spawn fixture');

        // Read the orphan PID line from fixture stdout.
        $pidLine = '';
        $readDeadline = \microtime(true) + 1.5;
        while (\microtime(true) < $readDeadline) {
            $chunk = \fread($pipes[1], 64);
            if (\is_string($chunk) && $chunk !== '') {
                $pidLine .= $chunk;
                if (\str_contains($pidLine, "\n")) {
                    break;
                }
            } else {
                \usleep(20_000);
            }
        }

        foreach ($pipes as $p) {
            if (\is_resource($p)) {
                \fclose($p);
            }
        }

        // proc_close blocks for the fixture's PHP runtime to exit. The
        // grandchild (sleep 0.5) is now orphaned and reparented to init.
        \proc_close($proc);

        $orphanPid = (int) \trim($pidLine);
        $this->assertGreaterThan(
            1,
            $orphanPid,
            \sprintf('fixture did not print a valid orphan PID (got %s)', \var_export($pidLine, true)),
        );

        // Poll up to 2 s for the orphan to disappear. The inner sleep is
        // 0.5 s; init should reap it shortly after.
        $reapDeadline = \microtime(true) + 2.0;
        $stillRunning = true;
        while (\microtime(true) < $reapDeadline) {
            if (!$this->processIsRunning($orphanPid, $procReadable, $psPath)) {
                $stillRunning = false;
                break;
            }
            \usleep(50_000);
        }

        $this->assertFalse(
            $stillRunning,
            \sprintf(
                'orphan PID %d still appears running 2 s after fixture exit',
                $orphanPid,
            ),
        );

        // Bonus: if we can read /proc/<pid>/status, fail on a residual
        // zombie marker. After reap the file is gone; in the rare race
        // where it lingers, anything other than State: Z is acceptable.
        if ($procReadable) {
            $statusPath = "/proc/{$orphanPid}/status";
            if (\is_readable($statusPath)) {
                $status = (string) @\file_get_contents($statusPath);
                if (\preg_match('/^State:\s*Z/m', $status) === 1) {
                    $this->fail(\sprintf(
                        'orphan PID %d is zombified after fixture exit; status: %s',
                        $orphanPid,
                        \trim($status),
                    ));
                }
            }
        }

        $elapsed = \microtime(true) - $start;
        $this->assertLessThan(
            self::WALLCLOCK_BUDGET_SEC,
            $elapsed,
            'OrphanedChildReapTest exceeded its 3 s wallclock budget',
        );
    }

    /**
     * Resolve `ps` from a small set of canonical locations so the
     * skip-guard doesn't depend on the test runner's `$PATH`.
     */
    private function locatePs(): ?string
    {
        foreach (['/usr/bin/ps', '/bin/ps'] as $candidate) {
            if (\is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * True iff $pid corresponds to a live, non-reaped process.
     *
     * `posix_kill($pid, 0)` is the canonical POSIX liveness probe —
     * `kill(pid, 0)` performs argument validation only and returns
     * 0 / true if the signal could have been sent. `/proc/<pid>` on
     * Linux is NOT a reliable substitute: after a child is reaped the
     * directory may linger for a tick with empty fields, while
     * `posix_kill` flips to ESRCH immediately. We accept `posix_kill`
     * returning true on EPERM (a permission-denied kill still means
     * the process exists) — for an orphan reparented to init this is
     * not normally a concern but we guard for completeness.
     *
     * `$procReadable` + `$psPath` are unused by the probe but reserved
     * for the zombie-audit branch below.
     */
    private function processIsRunning(int $pid, bool $procReadable, ?string $psPath): bool
    {
        unset($procReadable, $psPath);
        $ok = @\posix_kill($pid, 0);
        if ($ok) {
            return true;
        }
        // Differentiate ESRCH (gone) from EPERM (alive, just not ours).
        // errno values: ESRCH=3, EPERM=1 on Linux + Darwin.
        return \posix_get_last_error() === 1;
    }
}
