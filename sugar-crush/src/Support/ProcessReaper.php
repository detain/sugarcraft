<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * Bounded shutdown for a `proc_open()` child: SIGTERM, a polled grace period,
 * then signal 9 — and only then `proc_close()`.
 *
 * WHY THIS EXISTS AS A SHARED CLASS RATHER THAN A FOURTH COPY. `proc_close()`
 * WAITS. A `proc_terminate()` immediately followed by `proc_close()` therefore
 * hands the caller's deadline to a child that is free to ignore SIGTERM, and
 * MEASURED on this host (PHP 8.3.6) against a child running
 * `pcntl_signal(SIGTERM, fn () => null)` and then sleeping 8s, that pair
 * returned after **7.77s** — it did NOT orphan the child, it blocked for the
 * child's whole remaining lifetime. Two places in this package had already
 * grown the escalation independently
 * ({@see \SugarCraft\Crush\MCP\StdioMcpServer::stop()} and
 * {@see \SugarCraft\Crush\Backend\StreamingCommandBackend::terminateAndReap()},
 * each with its own private `waitForExit()` and its own copy of the three
 * constants), and E366's sweep found three more call sites needing it. Five
 * hand-rolled copies of a signal-escalation ladder is how one of them ends up
 * with the poll interval or the grace budget subtly different, so the ladder
 * lives here once.
 *
 * THE TWO EXISTING COPIES ARE MIGRATED — E407 folded both
 * {@see \SugarCraft\Crush\MCP\StdioMcpServer::stop()} and
 * {@see \SugarCraft\Crush\Backend\StreamingCommandBackend::terminateAndReap()}
 * onto the ladder here (their private `waitForExit()`s and grace constants are
 * gone; the budgets they documented are THIS class's constants now), alongside
 * E366's newer consumers. StdioMcpServer takes {@see terminateAndClose()};
 * StreamingCommandBackend takes {@see terminateAndAwaitExit()}, because its
 * caller reaps the handle itself to read the child's exit status.
 *
 * WHAT THIS DELIBERATELY IS NOT: a detached-watchdog spawner. The suite's
 * {@see \SugarCraft\Crush\Tests\Integration\BinSugarcrushDispatchTest} arms an
 * external watchdog with pid-reuse checking before its own `proc_terminate(9)`,
 * and that is right THERE — a test harness must not be wedged by a child that
 * survives signal 9, and it can afford to spawn a process to guarantee it.
 * Library teardown cannot: forking a watchdog on every `disconnect()` would put
 * a second process spawn on the shutdown path of code whose problem is already
 * that it spawns processes. After signal 9 the only way to still be running is
 * an uninterruptible kernel wait, and `proc_close()` is then the least-bad
 * option left — there is no non-blocking reap available without ext-pcntl, and
 * abandoning the handle would leak the zombie the reap exists to prevent.
 */
final class ProcessReaper
{
    /**
     * Shutdown escalation budgets — since E407 the ONLY copies in the package
     * (the two earlier private sets these matched are deleted): one second for
     * a well-behaved child to honour SIGTERM, one more after signal 9, and a
     * 5ms poll so a prompt exit is not rounded up to the whole budget.
     */
    public const TERMINATE_GRACE_SECONDS = 1.0;
    public const KILL_GRACE_SECONDS = 1.0;
    public const POLL_INTERVAL_US = 5000;

    /**
     * SIGTERM, poll, signal 9, poll, `proc_close()`.
     *
     * Returns the child's exit status as `proc_close()` reports it, or null when
     * $process is not a live process resource — so a caller that wants the exit
     * code (a streaming provider deciding whether to throw) and a caller that
     * only wants the process gone (a `disconnect()`) can share one method.
     *
     * IDEMPOTENT BY CONTRACT: a non-resource argument is a no-op returning null,
     * because every caller here is on a teardown path that may run twice — a
     * `disconnect()` invoked explicitly and then again from `__destruct()`.
     *
     * @param mixed $process the value a `proc_open()` call returned
     * @param int|null $groupPid E673: the process group a containment-wrapped
     *        child leads (from {@see ProcessContainment::groupId()}, computed
     *        by the caller WHILE the child is alive — a dead pid answers
     *        nothing). When non-null the ladder signals `-groupPid` instead
     *        of the wrapper's own pid, so descendants die with it; null keeps
     *        the pre-containment direct-child behaviour.
     */
    public static function terminateAndClose(mixed $process, ?int $groupPid = null): ?int
    {
        if (!\is_resource($process)) {
            return null;
        }

        self::terminateAndAwaitExit($process, $groupPid);

        return \proc_close($process);
    }

    /**
     * SIGTERM, poll, signal 9, poll — and STOP THERE: never `proc_close()`.
     *
     * THE VARIANT FOR THE CALLER THAT REAPS ITSELF.
     * {@see \SugarCraft\Crush\Backend\StreamingCommandBackend::finish()} closes
     * the handle on BOTH the expired and the completed path and reads the exit
     * status out of `proc_close()`'s return; giving it {@see terminateAndClose()}
     * would double-close (a TypeError on the consumed resource) and hide the
     * status. So the escalation lives here and the reap stays there.
     *
     * Returns whether the child is gone by the end of the budgets — true also
     * for a non-resource argument, which is "nothing is running" rather than
     * "nothing matched", keeping the idempotent-teardown contract of
     * {@see terminateAndClose()} intact underneath it.
     *
     * @param mixed $process the value a `proc_open()` call returned
     * @param int|null $groupPid see {@see terminateAndClose()} — E673.
     */
    public static function terminateAndAwaitExit(mixed $process, ?int $groupPid = null): bool
    {
        if (!\is_resource($process)) {
            return true;
        }

        // Already exited on its own — the overwhelmingly common case. Skip
        // straight out so a normal completion pays no signal at all
        // and no part of the escalation budget.
        if (!self::isRunning($process)) {
            return true;
        }

        self::signal($process, $groupPid, 15);

        if (self::waitForExit($process, self::TERMINATE_GRACE_SECONDS)) {
            return true;
        }

        // Signal 9 as an INTEGER LITERAL, never the `SIGKILL` constant:
        // that constant is defined by ext-pcntl, and naming an optional
        // extension's symbol on a shutdown path would make the shutdown
        // path itself fatal where the extension is absent.
        self::signal($process, $groupPid, 9);
        // The result is reported, not acted on — after signal 9 the only way
        // to still be running is an uninterruptible kernel wait, and each
        // caller's reap is then the least-bad option left (see the class
        // docblock for why no watchdog covers this).
        return self::waitForExit($process, self::KILL_GRACE_SECONDS);
    }

    /**
     * One rung of the ladder: group signal when the caller named a group this
     * child leads (E673 — `kill(-pgid)` reaches wrapper AND descendants, the
     * kernel-proof half after which even an un-forwardable 9 cannot orphan a
     * grandchild), direct `proc_terminate()` otherwise.
     *
     * @param resource $process
     */
    private static function signal($process, ?int $groupPid, int $signal): void
    {
        if ($groupPid !== null && \function_exists('posix_kill') && @\posix_kill(-$groupPid, $signal)) {
            return;
        }

        \proc_terminate($process, $signal);
    }

    /**
     * Reap a child that has ALREADY EXITED, and NEVER signal one that has not.
     *
     * The counterpart to {@see terminateAndClose()}, for the one shape where
     * signalling would be actively wrong: a launcher whose whole job is to fork
     * and get out of the way. {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor::spawnSession()}
     * has exactly that — a `php -r` process that double-forks a detached daemon
     * and exits. It always exits within milliseconds, so a plain `proc_close()`
     * would in practice be free; but "in practice" is a race, and if the reap
     * ever landed before the second fork, `terminateAndClose()` would SIGTERM
     * the very process that is in the middle of creating the session.
     *
     * So: wait WITHOUT signalling, up to $budgetSeconds. If the child exited,
     * `proc_close()` it — that call cannot block, because the wait already
     * established the child is gone — and return its status. If it has NOT
     * exited, return null and LEAVE THE HANDLE ALONE. Dropping the handle is
     * safe and is what happens next: MEASURED on this host (PHP 8.3.6), the
     * `proc_open()` resource destructor reaps an already-exited child instantly
     * (state `Z` -> `GONE`) and abandons a still-running one in 0.000s without
     * waiting. For a launcher that is the correct outcome either way — the thing
     * that must survive is the daemon, and the daemon is not this process.
     *
     * @param mixed $process the value a `proc_open()` call returned
     * @return int|null the exit status, or null if the child was still running
     */
    public static function reapIfExited(mixed $process, float $budgetSeconds = self::TERMINATE_GRACE_SECONDS): ?int
    {
        if (!\is_resource($process)) {
            return null;
        }

        if (!self::waitForExit($process, $budgetSeconds)) {
            return null;
        }

        return \proc_close($process);
    }

    /**
     * Poll `proc_get_status()` until the child is gone or the budget runs out;
     * true if it exited. A bounded poll rather than a blocking wait, for the
     * reason this class exists at all: an unflagged wait is precisely the thing
     * being replaced.
     *
     * @param resource $process
     */
    public static function waitForExit($process, float $budgetSeconds): bool
    {
        $deadline = \microtime(true) + $budgetSeconds;

        do {
            if (!self::isRunning($process)) {
                return true;
            }
            \usleep(self::POLL_INTERVAL_US);
        } while (\microtime(true) < $deadline);

        return !self::isRunning($process);
    }

    /**
     * `proc_get_status()['running']`, guarded.
     *
     * The guard is not defensive padding: `proc_get_status()` on a resource that
     * `proc_close()` already consumed is a TypeError, and this class is called
     * from `__destruct()` paths where a double teardown is normal.
     *
     * @param resource $process
     */
    private static function isRunning($process): bool
    {
        if (!\is_resource($process)) {
            return false;
        }

        return \proc_get_status($process)['running'] === true;
    }
}
