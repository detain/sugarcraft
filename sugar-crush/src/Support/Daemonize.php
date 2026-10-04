<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * The one daemonize sequence, in the two shapes its two callers need
 * (Appendix O §4.6; roadmap O-4a).
 *
 * THE SEQUENCE: `umask(0o077)` → fork → the parent leaves → `posix_setsid()`
 * (or `posix_setpgid(0, 0)` where that fails) → fork → the parent leaves. The
 * first fork makes the child a non-leader so `setsid()` succeeds; `setsid()`
 * drops the controlling terminal and the spawner's session, so a SIGHUP to that
 * session cannot reach the daemon; the second fork makes the daemon a
 * NON-leader of its new session, which is what keeps it from ever acquiring a
 * controlling terminal again by opening one. Both forks are load-bearing
 * ({@see \SugarCraft\Crush\Tests\Sessions\BackgroundSupervisorReapTest} pins
 * the outcome, not the shape).
 *
 * TWO SHAPES, ONE SEQUENCE — a move out of
 * {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor}, not a second copy:
 *
 *  - {@see code()}: the sequence as PHP source, for the `php -r` launcher the
 *    supervisor spawns for `/bg`. Its stdio is set by the spawn's descriptor
 *    spec, so the code needs no redirection.
 *  - {@see detach()}: the sequence run IN THIS PROCESS, for `sugarcrush serve
 *    --detach`. The original process does not exit here: it gets control back
 *    so it can wait for the daemon to report its URL and print it, and the
 *    daemon runs a closure the caller hands in. The intermediate child leaves
 *    through {@see ForkedChild::exitNow()} rather than `exit()`, because it
 *    carries a copy of the whole object graph and a shutdown sequence there
 *    could act on the original's resources. The daemon gets its stdio moved
 *    onto `/dev/null` (stdin) and the log (stdout, stderr) — the same
 *    redirection the supervisor's spawn spec makes.
 *
 * Also here, because a daemon is known by it: a process IDENTITY — the pid
 * plus its kernel start time ({@see startTime()}), so a pid the kernel has
 * recycled reads as a different process ({@see isSameProcess()}).
 */
final class Daemonize
{
    /** Every file the daemon creates is this user's alone, from its first byte. */
    public const UMASK = 0o077;

    /** `O_WRONLY | O_CREAT | O_APPEND` on Linux (every ABI this runs on). */
    private const LOG_OPEN_FLAGS = 0o1 | 0o100 | 0o2000;

    /** `O_RDONLY`. */
    private const NULL_OPEN_FLAGS = 0;

    private function __construct()
    {
    }

    /**
     * The sequence as PHP source for a `php -r` launcher: each parent exits 0,
     * a failed fork exits 1, and the code that follows runs in the daemon.
     */
    public static function code(): string
    {
        return \sprintf(
            '
umask(0o%03o);
$pid = pcntl_fork();
if ($pid < 0) { exit(1); }
if ($pid > 0) { exit(0); }
posix_setsid() >= 0 || posix_setpgid(0, 0);
$pid = pcntl_fork();
if ($pid < 0) { exit(1); }
if ($pid > 0) { exit(0); }
',
            self::UMASK,
        );
    }

    /**
     * Why {@see detach()} cannot run here, or null when it can.
     *
     * pcntl for the forks, posix for the session, and FFI for the stdio move:
     * PHP has no `dup2()`, and closing STDOUT/STDERR to let `fopen()` reuse the
     * numbers would leave the `STDERR` constant a closed resource that every
     * later `fwrite(STDERR, …)` in the codebase throws on.
     */
    public static function unavailableReason(): ?string
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            return 'ext-pcntl is missing';
        }
        if (!\function_exists('posix_setsid') || !\function_exists('posix_getpid')) {
            return 'ext-posix is missing';
        }
        if (self::libc() === null) {
            return 'ext-ffi is missing or disabled (ffi.enable)';
        }

        return null;
    }

    /**
     * Run the sequence in this process: $daemon runs in the daemon — stdin
     * `/dev/null`, stdout and stderr appending to $logPath — and its return
     * value is the daemon's exit code. In the ORIGINAL process this returns
     * once the intermediate child has forked the daemon and been reaped.
     *
     * Nothing of the original's stdio survives into the daemon: a terminal the
     * daemon still held would keep an `ssh` session open and turn every later
     * write into a SIGHUP risk.
     *
     * @param \Closure(): int $daemon
     *
     * @throws \RuntimeException in the original process when the first fork
     *         fails or {@see unavailableReason()} names a missing extension
     */
    public static function detach(string $logPath, \Closure $daemon): void
    {
        $missing = self::unavailableReason();
        if ($missing !== null) {
            throw new \RuntimeException('cannot detach: ' . $missing);
        }

        \umask(self::UMASK);
        $first = \pcntl_fork();
        if ($first === -1) {
            throw new \RuntimeException('cannot detach: fork failed');
        }
        if ($first === 0) {
            self::becomeDaemon($logPath, $daemon);
        }

        // The intermediate exits as soon as it has forked the daemon; waiting
        // here keeps it from lingering as this process's zombie.
        \pcntl_waitpid($first, $status);
    }

    /**
     * Point stdin at `/dev/null` and stdout + stderr at $logPath (appending,
     * created 0600). Called by {@see detach()} and again after the log is
     * rotated, so the daemon writes to the fresh file rather than the renamed
     * one. Returns false — changing nothing it could not complete — when the
     * log cannot be opened.
     */
    public static function redirectStdio(string $logPath): bool
    {
        $libc = self::libc();
        if ($libc === null) {
            return false;
        }

        $log = $libc->open($logPath, self::LOG_OPEN_FLAGS, 0o600);
        if ($log < 0) {
            return false;
        }
        $null = $libc->open('/dev/null', self::NULL_OPEN_FLAGS);

        if ($null >= 0) {
            $libc->dup2($null, 0);
            if ($null > 2) {
                $libc->close($null);
            }
        }
        $libc->dup2($log, 1);
        $libc->dup2($log, 2);
        if ($log > 2) {
            $libc->close($log);
        }

        return true;
    }

    /**
     * The intermediate child: leave the spawner's session, fork the daemon,
     * and leave without running the inherited shutdown sequence.
     *
     * @param \Closure(): int $daemon
     */
    private static function becomeDaemon(string $logPath, \Closure $daemon): never
    {
        if (\posix_setsid() < 0 && \function_exists('posix_setpgid')) {
            @\posix_setpgid(0, 0);
        }
        $second = \pcntl_fork();
        if ($second === 0) {
            // Inherited output buffers hold the ORIGINAL's pending output,
            // which the original prints itself; flushed here they would land
            // in the daemon's log a second time.
            while (\ob_get_level() > 0) {
                \ob_end_clean();
            }
            self::redirectStdio($logPath);
            self::finish(self::run($daemon, $logPath));
        }

        ForkedChild::exitNow($second === -1 ? 1 : 0);
    }

    /**
     * $daemon's exit code. A throw is written to $logPath and is exit 1, never
     * unwound out of the daemon: it would land in the CALLER's frames — the
     * code that runs in the original process after {@see detach()} returns —
     * and run them a second time here.
     *
     * @param \Closure(): int $daemon
     */
    private static function run(\Closure $daemon, string $logPath): int
    {
        try {
            return $daemon();
        } catch (\Throwable $e) {
            // Appended to the log the daemon's stderr already points at.
            @\file_put_contents($logPath, 'daemon failed: ' . $e::class . ': ' . $e->getMessage() . "\n", \FILE_APPEND);

            return 1;
        }
    }

    /**
     * The daemon's end: a plain `exit()`, ON PURPOSE. From the second fork on,
     * the daemon is the long-lived process — its shutdown sequence (the
     * destructors, the registered shutdown functions) is the server's real
     * shutdown, the one `bin/sugarcrush`'s own `exit($code)` would have run
     * had it not detached. It is the original process's exit, not this one,
     * that has nothing left to do.
     */
    private static function finish(int $code): never
    {
        exit($code);
    }

    /**
     * The kernel start time of $pid (clock ticks since boot, `/proc/<pid>/stat`
     * field 22), or null when procfs cannot answer. With the pid it is a
     * process identity: the allocator recycles numbers, never start times.
     */
    public static function startTime(int $pid): ?int
    {
        return ProcessTree::stat($pid)['startTicks'] ?? null;
    }

    /**
     * Whether $pid is still the process that started at $startTime.
     *
     * A zombie (or a dying `X`) has done all it will ever do and counts as
     * gone. Where procfs cannot answer a start time, signal 0 is the witness;
     * with neither, nothing on this host can show the pid alive.
     */
    public static function isSameProcess(int $pid, ?int $startTime): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $stat = ProcessTree::stat($pid);
        if ($stat !== null && \in_array($stat['state'], ['Z', 'X'], true)) {
            return false;
        }
        if ($startTime !== null && $stat !== null && $stat['startTicks'] !== null) {
            return $stat['startTicks'] === $startTime;
        }
        if ($stat !== null) {
            return true;
        }

        return \function_exists('posix_kill') && @\posix_kill($pid, 0);
    }

    private static function libc(): ?\FFI
    {
        static $libc = false;
        if ($libc !== false) {
            return $libc;
        }
        if (!\extension_loaded('ffi')) {
            return $libc = null;
        }

        try {
            return $libc = \FFI::cdef('int open(const char *pathname, int flags, ...); int dup2(int oldfd, int newfd); int close(int fd);');
        } catch (\Throwable) {
            return $libc = null;
        }
    }
}
