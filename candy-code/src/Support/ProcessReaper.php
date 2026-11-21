<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use React\EventLoop\LoopInterface;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

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
 * ({@see \SugarCraft\Code\MCP\StdioMcpServer::stop()} and the streaming backend's
 * escalation, each with its own private `waitForExit()` and its own copy of the three
 * constants), and E366's sweep found three more call sites needing it. Five
 * hand-rolled copies of a signal-escalation ladder is how one of them ends up
 * with the poll interval or the grace budget subtly different, so the ladder
 * lives here once.
 *
 * THE TWO EXISTING COPIES ARE MIGRATED — E407 folded both
 * {@see \SugarCraft\Code\MCP\StdioMcpServer::stop()} and the streaming backend's
 * escalation onto the ladder here (their private `waitForExit()`s and grace constants are
 * gone; the budgets they documented are THIS class's constants now), alongside
 * E366's newer consumers. The stdio transport took {@see terminateAndClose()}
 * until phase-2a moved it into `sugarcraft/sugar-mcp-client`, where the teardown now
 * goes through sugar-core's BoundedShutdown — the {@see \SugarCraft\Code\MCP\StdioMcpServer}
 * adapter contributes no ladder of its own. The streaming backend now runs its child
 * through sugar-async's ChildRun, so its kill ladder is the shared one there.
 *
 * E676 FOLDED THE LAST THREE PRIVATE LADDERS. ScriptHook,
     * StatusLineCommand and BackgroundSessionRunner now escalate through
     * {@see escalate()} — callables at the seam, because one family drives a
 * `proc_open()` handle, two a containment-wrapped child on a wall-clock
 * deadline, and one a `pcntl_fork()`ed pid with a `WNOHANG` poll. The
 * sequences are one; the budgets stay where they were pinned.
 *
 * WHAT THIS DELIBERATELY IS NOT: a detached-watchdog spawner. The suite's
 * {@see \SugarCraft\Code\Tests\Integration\BinCandyCodeDispatchTest} arms an
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
     * A caller that closes its own handle and reads the exit status out of
     * `proc_close()`'s return needs this shape: giving it {@see terminateAndClose()}
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
        if (self::isGone($process, $groupPid)) {
            return true;
        }

        // E676: the rung sequence lives in ONE method now — this ladder and
        // every folded family ride {@see escalate()}; the class defaults are
        // the budgets this call site always had.
        return self::escalate(
            static function (int $signal) use ($process, $groupPid): void {
                self::signal($process, $groupPid, $signal);
            },
            static function () use ($process, $groupPid): bool {
                return self::isGone($process, $groupPid);
            },
        );
    }

    /**
     * THE ladder, single-sourced by E676: signal 15, bounded wait, signal 9,
     * bounded wait — expressed over CALLABLES so the `proc_open()` children of
     * {@see terminateAndAwaitExit()}, the deadline-polling expiry paths of
     * {@see \SugarCraft\Code\Hooks\ScriptHook} and
     * {@see \SugarCraft\Code\Config\StatusLineCommand}, and the
     * `pcntl_fork()`ed worker of
     * {@see \SugarCraft\Code\Background\BackgroundSessionRunner} share ONE
     * escalation sequence instead of each keeping a private copy. Three
     * hand-rolled ladders is one too many: a copy is where a rung drifts.
     *
     * NOT EVERY TEARDOWN: {@see ProcessContainment::killTree()} and
     * {@see ProcessContainment::killTreeAsync()} escalate through sugar-core's
     * `BoundedShutdown::escalate()`/`escalateAsync()` instead, so a change to
     * the rung order here must be made there too.
     *
     * THE BUDGETS STAY WITH THE CALLERS. ScriptHook and StatusLineCommand
     * expire on a 0.5 s grace and BackgroundSessionRunner on 2.0 s, each
     * pinned by its own family's tests; consolidating the BODY must not
     * rewrite those numbers, so only the sequence, the poll and the literal-9
     * doctrine are shared here.
     *
     * Returns whether the child is gone by the end of the budgets.
     *
     * @param callable(int):mixed $signal delivers one rung signal (15 or 9);
     *        its return value is ignored — rung helpers that report success
     *        (terminatePid's bool) are the callers' business, not the ladder's
     * @param callable():bool $isGone a cheap, non-blocking exited?-poll
     * @param callable():void|null $onEscalate fired once BETWEEN the rungs,
     *        when SIGTERM did not land — for families that log the escalation
     */
    public static function escalate(
        callable $signal,
        callable $isGone,
        float $terminateGrace = self::TERMINATE_GRACE_SECONDS,
        float $killGrace = self::KILL_GRACE_SECONDS,
        ?callable $onEscalate = null,
    ): bool {
        $signal(15);

        if (self::waitBounded($isGone, $terminateGrace)) {
            return true;
        }

        if ($onEscalate !== null) {
            $onEscalate();
        }

        // Signal 9 as an INTEGER LITERAL, never the `SIGKILL` constant:
        // that constant is defined by ext-pcntl, and naming an optional
        // extension's symbol on a shutdown path would make the shutdown
        // path itself fatal where the extension is absent.
        $signal(9);
        // The result is reported, not acted on — after signal 9 the only way
        // to still be running is an uninterruptible kernel wait, and each
        // caller's reap is then the least-bad option left (see the class
        // docblock for why no watchdog covers this).
        return self::waitBounded($isGone, $killGrace);
    }

    /**
     * The same ladder as {@see escalate()}, driven by loop timers instead of
     * `usleep()`: the TERM and KILL waits each tick on `$loop` at the poll
     * cadence, so a wedged child costs the loop NOTHING while it waits. The
     * gateway recycles hosts on its own event loop; the sync ladder would
     * freeze every other connection's frames for up to both budgets. CLI call
     * sites keep the sync variant — they have no loop to yield to.
     *
     * Same rung order, same literal-9 doctrine. `$done` receives whether the
     * child is gone by the end of the budgets.
     *
     * @param callable(int):mixed $signal
     * @param callable():bool $isGone
     * @param callable(bool):void|null $done
     * @param callable():void|null $onEscalate
     */
    public static function escalateAsync(
        LoopInterface $loop,
        callable $signal,
        callable $isGone,
        ?callable $done = null,
        float $terminateGrace = self::TERMINATE_GRACE_SECONDS,
        float $killGrace = self::KILL_GRACE_SECONDS,
        ?callable $onEscalate = null,
    ): void {
        $finish = static function (bool $gone) use ($done): void {
            if ($done !== null) {
                $done($gone);
            }
        };

        $signal(15);
        $killed = false;
        $deadline = self::monotonic() + $terminateGrace;
        $timer = null;
        $timer = $loop->addPeriodicTimer(self::POLL_INTERVAL_US / 1_000_000, static function () use (&$timer, &$killed, &$deadline, $loop, $signal, $isGone, $finish, $killGrace, $onEscalate): void {
            if ($isGone()) {
                $loop->cancelTimer($timer);
                $finish(true);

                return;
            }
            if (self::monotonic() < $deadline) {
                return;
            }
            if ($killed) {
                $loop->cancelTimer($timer);
                $finish($isGone());

                return;
            }
            if ($onEscalate !== null) {
                $onEscalate();
            }
            $signal(9);
            $killed = true;
            $deadline = self::monotonic() + $killGrace;
        });
    }

    /**
     * Async twin of {@see terminateAndClose()}: ladder on loop timers, then
     * the reap once the child is gone. `$then` gets the exit status.
     *
     * A child that survives signal 9 within its budget is NOT reaped with a
     * blocking `proc_close()`: that parks the loop thread on exactly the
     * uninterruptible kernel wait the ladder could not break, freezing every
     * other connection the gateway serves. The handle is polled by
     * {@see reapWhenExited()} until the kernel releases the child.
     *
     * @param callable(?int):void|null $then
     */
    public static function terminateAndCloseAsync(LoopInterface $loop, mixed $process, ?int $groupPid = null, ?callable $then = null): void
    {
        $finish = static function (?int $status) use ($then): void {
            if ($then !== null) {
                $then($status);
            }
        };
        if (!\is_resource($process)) {
            $finish(null);

            return;
        }
        // A leader that exited on SIGTERM can leave a TERM-ignoring descendant
        // in its group; isGone() keeps the ladder open for that case. The probe
        // runs on the loop every tick, so it skips the /proc walk.
        if (self::isGone($process, $groupPid, false)) {
            $finish(\proc_close($process));

            return;
        }
        self::escalateAsync(
            $loop,
            static function (int $signal) use ($process, $groupPid): void {
                self::signal($process, $groupPid, $signal);
            },
            static fn (): bool => self::isGone($process, $groupPid, false),
            static function (bool $gone) use ($loop, $process, $finish): void {
                // Only a poll that saw the exit may take the blocking reap: then
                // proc_close() cannot wait. Anything else is deferred to the loop.
                if (!$gone) {
                    self::reapWhenExited($loop, $process, $finish);

                    return;
                }
                $finish(\is_resource($process) ? \proc_close($process) : null);
            },
        );
    }

    /**
     * Reap a child on loop timers, never blocking: answer at once when it has
     * already exited, otherwise poll every {@see POLL_INTERVAL_US} and
     * `proc_close()` the tick it is seen gone.
     *
     * The reap half of {@see terminateAndCloseAsync()} for a child the ladder
     * could not stop. A synchronous `proc_close()` there waits on the child with
     * no bound. A non-resource argument answers null, matching the sync contract.
     *
     * @param callable(?int):void|null $then
     */
    public static function reapWhenExited(LoopInterface $loop, mixed $process, ?callable $then = null): void
    {
        $finish = static function (?int $status) use ($then): void {
            if ($then !== null) {
                $then($status);
            }
        };
        if (!\is_resource($process)) {
            $finish(null);

            return;
        }
        $exitCode = null;
        if (self::exitedWithCode($process, $exitCode)) {
            $finish(self::closeReaped($process, $exitCode));

            return;
        }

        $timer = null;
        $timer = $loop->addPeriodicTimer(
            self::POLL_INTERVAL_US / 1_000_000,
            static function () use (&$timer, $loop, $process, $finish): void {
                // A teardown that ran while this timer was armed may have closed
                // the handle; the reap must not run on a consumed resource.
                if (!\is_resource($process)) {
                    $loop->cancelTimer($timer);
                    $finish(null);

                    return;
                }
                $exitCode = null;
                if (!self::exitedWithCode($process, $exitCode)) {
                    return;
                }

                $loop->cancelTimer($timer);
                $finish(self::closeReaped($process, $exitCode));
            },
        );
    }

    /**
     * Whether the child has exited, capturing its status from the poll that
     * saw the exit. A signal death reads -1 here and `proc_close()` then
     * reports -1 as well (the poll consumed the status); a normal exit is
     * reported as its code by both. -1 is the sentinel the callers branch on.
     *
     * @param resource $process
     */
    private static function exitedWithCode($process, ?int &$exitCode): bool
    {
        $status = \proc_get_status($process);
        if ($status['running'] === true) {
            return false;
        }

        $exitCode = (int) $status['exitcode'];

        return true;
    }

    /**
     * Release the handle of a child already seen gone. The poll's status wins
     * when it captured one; `proc_close()` cannot block here.
     *
     * @param resource $process
     */
    private static function closeReaped($process, ?int $exitCode): ?int
    {
        $reaped = \proc_close($process);

        return $exitCode !== null && $exitCode >= 0 ? $exitCode : $reaped;
    }

    /**
     * Monotonic seconds for every grace deadline. The wall clock (microtime)
     * would let an NTP step stretch or cut the TERM/KILL window, the same
     * reason sugar-core's BoundedShutdown measures on hrtime().
     */
    private static function monotonic(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    /**
     * Poll $isGone until it answers true or the budget runs out.
     */
    private static function waitBounded(callable $isGone, float $budgetSeconds): bool
    {
        $deadline = self::monotonic() + $budgetSeconds;

        do {
            if ($isGone()) {
                return true;
            }
            \usleep(self::POLL_INTERVAL_US);
        } while (self::monotonic() < $deadline);

        return $isGone();
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
     * and get out of the way. {@see \SugarCraft\Code\Background\BackgroundSupervisor::spawnSession()}
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
        return self::waitBounded(
            static function () use ($process): bool {
                return !self::isRunning($process);
            },
            $budgetSeconds,
        );
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

    /**
     * "Is the child gone?" — the leader has exited AND, when the caller named
     * the group it leads, that group is empty. The leader alone is not enough:
     * a descendant that ignores SIGTERM outlives its wrapper, and declaring the
     * child gone then would end the ladder before the signal 9 rung reaches it.
     * Same rule as {@see \SugarCraft\Core\Util\Proc\BoundedShutdown}'s ladders.
     * $walkProc false is the loop-safe probe: see BoundedShutdown::groupEmpty().
     *
     * @param resource $process
     */
    private static function isGone($process, ?int $groupPid, bool $walkProc = true): bool
    {
        if (self::isRunning($process)) {
            return false;
        }

        return $groupPid === null || BoundedShutdown::groupEmpty($groupPid, $walkProc);
    }
}
