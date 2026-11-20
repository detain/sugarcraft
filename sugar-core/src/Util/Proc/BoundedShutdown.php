<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util\Proc;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\Core\Lang;

/**
 * Bounded shutdown ladder for a `proc_open()` child: SIGTERM, a polled grace
 * period, signal 9, a bounded confirmation poll — and only then the reap.
 *
 * WHY THIS EXISTS. `proc_close()` WAITS. A `proc_terminate()` immediately
 * followed by `proc_close()` therefore hands the caller's deadline to a child
 * that is free to ignore SIGTERM — MEASURED on PHP 8.3.6 against a child
 * running `pcntl_signal(SIGTERM, fn () => null)` and then sleeping, the pair
 * returned only after the child's whole remaining lifetime (7.77s for an 8s
 * sleeper). The other wrong shape — dropping the handle with no close at all
 * — ABANDONS: PHP's resource destructor reaps an exited child instantly but
 * never waits for a running one, orphaning it with every descriptor above 2
 * the parent held at spawn. This class is the third shape, and the ladder is
 * what makes it bounded: TERM gives a well-behaved child its chance, signal 9
 * catches every child that can still be caught, the confirmation poll keeps
 * the promise honest, and the reap that follows cannot block on a child the
 * poll already saw die.
 *
 * ONE LADDER, MANY HANDLES. {@see escalate()} expresses the rung sequence
 * over callables, which is what lets the same sequence serve a `proc_open()`
 * resource, a containment-wrapped process group, and a `pcntl_fork()`ed pid
 * whose liveness is a `WNOHANG` poll — the three shapes that previously grew
 * five separate private ladders downstream (candy-code's `ProcessReaper`
 * absorbed four of them; sugar-dashboard's `ExternalModule::terminateBounded()`
 * and sugar-video's `BoundedReaper` were documented per-lib copies of the
 * same rungs). Callers bring their own budgets; the sequence never varies.
 *
 * SIGNALS ARE INTEGER LITERALS, never the `SIGTERM`/`SIGKILL` constants:
 * those are defined by ext-pcntl, and naming an optional extension's symbol
 * on a shutdown path would make the shutdown path itself fatal on a build
 * without it. `proc_terminate()` and `posix_kill()` take the numbers
 * directly.
 *
 * DEADLINES RUN ON `hrtime()` — the monotonic clock. An NTP step during a
 * teardown must not stretch the escalation window (or shorten it to zero),
 * which is the sugar-video copy's recorded lesson, kept here once.
 *
 * WHAT THIS DELIBERATELY IS NOT: a detached-watchdog spawner. Library
 * teardown cannot afford to fork a watchdog per shutdown — that puts a
 * second process spawn on the exit path of code whose problem is already
 * that it spawns processes. After signal 9 the only way to still be running
 * is an uninterruptible kernel wait, and `proc_close()` is then the least-bad
 * option left: there is no non-blocking reap without ext-pcntl, and
 * abandoning the handle leaks the zombie the reap exists to prevent. A test
 * harness that must not be wedged by such a child is a different contract
 * and may still arm its own external watchdog.
 */
final class BoundedShutdown
{
    /** Seconds a SIGTERM'd child gets before the ladder escalates to 9. */
    public const TERMINATE_GRACE_SECONDS = 1.0;

    /** Window to confirm the SIGKILL landed (9 is uncatchable; a leash, not a hope). */
    public const KILL_GRACE_SECONDS = 1.0;

    /** Poll granularity: fine enough that a prompt exit is not rounded up to the whole budget. */
    public const POLL_INTERVAL_US = 5000;

    /**
     * Seconds between exit polls while {@see reapWhenExited()} waits on a child
     * the ladder could not stop. Coarse on purpose: a stuck child is rare and a
     * prompt reap is not worth a 200Hz wakeup on a loop that is serving others.
     */
    public const REAP_POLL_SECONDS = 0.25;

    /**
     * When each group last had a live member seen by a /proc walk, keyed by pgid.
     * A walk costs tens of milliseconds on a busy host and the async ladders ask
     * on every 5 ms tick, so the answer is reused for REAP_POLL_SECONDS.
     *
     * @var array<int, float>
     */
    private static array $groupScannedAt = [];

    private function __construct()
    {
    }

    /**
     * The full teardown: TERM, bounded poll, 9, bounded poll, reap — and the
     * child's exit status.
     *
     * The status is captured from `proc_get_status()` at the moment the poll
     * observes the exit, NOT from `proc_close()`: a status poll that has seen
     * the child die of a SIGNAL consumes that status, and `proc_close()` then
     * reports -1 (measured, PHP 8.3.6 — the same reason candy-code's clipboard
     * spawner reads its exit code out of the poll). A normal exit is reported
     * as its code by both.
     *
     * THE -1 IS A SENTINEL, NOT A CODE. A child killed by a signal reads -1
     * from this method when the ladder polled it, and the number of the signal
     * (9 for a SIGKILL) from a `proc_close()` that no poll preceded. Callers
     * treat -1 as "died of a signal, or unknown", never as an exit code; the
     * ladder keeps it because callers across the tree branch on that value.
     * When the poll never observed an exit — the uninterruptible kernel-wait
     * case — the status falls back to `proc_close()`'s own report, which is
     * honest because that call will have waited out the child.
     *
     * IDEMPOTENT BY CONTRACT: a non-resource argument returns null instead of
     * throwing, because every caller is on a teardown path that may run twice
     * — an explicit stop followed by a `__destruct()` stop.
     *
     * @param mixed $process the value a `proc_open()` call returned
     * @param int|null $groupPid the process group a containment-wrapped child
     *        leads ({@see groupId()}, computed WHILE the child is alive — a
     *        dead pid answers nothing). Non-null makes each rung signal
     *        `-groupPid` so descendants die with the wrapper; null keeps the
     *        direct-child behaviour.
     */
    public static function terminateBounded(
        mixed $process,
        float $graceSeconds = self::TERMINATE_GRACE_SECONDS,
        float $killGraceSeconds = self::KILL_GRACE_SECONDS,
        ?int $groupPid = null,
    ): ?int {
        if (!\is_resource($process)) {
            return null;
        }

        $exitCode = null;

        // Already gone on its own — the overwhelmingly common case. Skip
        // straight to the reap so a normal completion pays no signal at all
        // and none of the escalation budget.
        if (!self::gone($process, $groupPid, $exitCode)) {
            self::escalate(
                static function (int $signal) use ($process, $groupPid): void {
                    self::signal($process, $groupPid, $signal);
                },
                static function () use ($process, $groupPid, &$exitCode): bool {
                    return self::gone($process, $groupPid, $exitCode);
                },
                $graceSeconds,
                $killGraceSeconds,
            );
        }

        $reaped = \proc_close($process);

        return $exitCode ?? $reaped;
    }

    /**
     * TERM, bounded poll, 9, bounded poll — and STOP THERE: never reap.
     *
     * THE VARIANT FOR THE CALLER THAT REAPS ITSELF. Anything that closes the
     * handle on both its expired and its completed path (a streaming backend
     * reading the status out of `proc_close()`) would double-close on
     * {@see terminateBounded()} — a TypeError on the consumed resource — so
     * the escalation lives here and the reap stays there.
     *
     * Returns whether the child is gone by the end of the budgets; true also
     * for a non-resource argument, which is "nothing is running" rather than
     * "nothing matched" — the idempotent-teardown contract carried through.
     *
     * @param mixed $process the value a `proc_open()` call returned
     * @param int|null $groupPid see {@see terminateBounded()}.
     */
    public static function terminateAndAwaitExit(
        mixed $process,
        float $graceSeconds = self::TERMINATE_GRACE_SECONDS,
        float $killGraceSeconds = self::KILL_GRACE_SECONDS,
        ?int $groupPid = null,
    ): bool {
        if (!\is_resource($process)) {
            return true;
        }

        $exitCode = null;
        if (self::gone($process, $groupPid, $exitCode)) {
            return true;
        }

        return self::escalate(
            static function (int $signal) use ($process, $groupPid): void {
                self::signal($process, $groupPid, $signal);
            },
            static function () use ($process, $groupPid): bool {
                $code = null;

                return self::gone($process, $groupPid, $code);
            },
            $graceSeconds,
            $killGraceSeconds,
        );
    }

    /**
     * THE ladder, expressed over callables so every handle shape shares one
     * rung sequence: signal 15, bounded wait, signal 9, bounded wait.
     *
     * A `proc_open()` resource, a containment-wrapped child on a wall-clock
     * deadline, and a `pcntl_fork()`ed worker polled with `WNOHANG` are the
     * same THREE sentences in different nouns — and three hand-rolled ladders
     * is one too many: a copy is where a rung drifts. THE BUDGETS STAY WITH
     * THE CALLERS (a hook expiring on 0.5s and a worker on 2.0s are product
     * decisions); only the sequence, the poll and the literal-signal doctrine
     * are shared here.
     *
     * @param callable(int):void $signal delivers one rung signal (15 or 9)
     * @param callable():bool $isGone a cheap, non-blocking exited?-poll
     * @param callable():void|null $onEscalate fired once BETWEEN the rungs,
     *        when SIGTERM did not land — for families that log the escalation
     *
     * @return bool whether the child is gone by the end of the budgets. The
     *         result is reported, not acted on: after signal 9 the only way
     *         to still be running is the kernel-wait case the class doc-block
     *         reserves for caller-side judgement.
     */
    public static function escalate(
        callable $signal,
        callable $isGone,
        float $terminateGrace = self::TERMINATE_GRACE_SECONDS,
        float $killGraceSeconds = self::KILL_GRACE_SECONDS,
        ?callable $onEscalate = null,
    ): bool {
        $signal(15);

        if (self::waitBounded($isGone, $terminateGrace)) {
            return true;
        }

        if ($onEscalate !== null) {
            $onEscalate();
        }

        // Signal 9 as an INTEGER LITERAL, never the `SIGKILL` constant — see
        // the class doc-block's ext-pcntl rule.
        $signal(9);

        return self::waitBounded($isGone, $killGraceSeconds);
    }

    /**
     * {@see escalate()} driven by loop timers instead of `usleep()`: each wait
     * ticks on $loop at the poll cadence, so a wedged child costs the loop
     * nothing while it waits, provided $isGone itself is cheap: it runs on the
     * loop every tick. A gateway that recycles hosts on its own event loop
     * cannot afford the sync ladder, which would freeze every other connection
     * for up to both budgets. CLI call sites keep the sync variant: they have
     * no loop to yield to.
     *
     * Same rung order and the same literal-9 doctrine. $done receives whether
     * the child is gone by the end of the budgets.
     *
     * @param callable(int):void $signal
     * @param callable():bool $isGone
     * @param callable(bool):void|null $done
     * @param callable():void|null $onEscalate
     * @return TimerInterface the poll timer driving the ladder. A caller that settles
     *                        before the ladder does must cancel it: the poll only stops
     *                        itself once $isGone holds or the kill budget is spent, so
     *                        an unchecked handle keeps the loop armed for up to one poll.
     */
    public static function escalateAsync(
        LoopInterface $loop,
        callable $signal,
        callable $isGone,
        ?callable $done = null,
        float $terminateGrace = self::TERMINATE_GRACE_SECONDS,
        float $killGraceSeconds = self::KILL_GRACE_SECONDS,
        ?callable $onEscalate = null,
    ): TimerInterface {
        $signal(15);
        $killed = false;
        $deadline = self::now() + $terminateGrace;
        $timer = null;
        $timer = $loop->addPeriodicTimer(
            self::POLL_INTERVAL_US / 1_000_000,
            static function () use (&$timer, &$killed, &$deadline, $loop, $signal, $isGone, $done, $killGraceSeconds, $onEscalate): void {
                if ($isGone()) {
                    $loop->cancelTimer($timer);
                    if ($done !== null) {
                        $done(true);
                    }

                    return;
                }
                if (self::now() < $deadline) {
                    return;
                }
                if ($killed) {
                    $loop->cancelTimer($timer);
                    if ($done !== null) {
                        $done($isGone());
                    }

                    return;
                }
                if ($onEscalate !== null) {
                    $onEscalate();
                }
                // Signal 9 as an INTEGER LITERAL, never the `SIGKILL` constant — see
                // the class doc-block's ext-pcntl rule.
                $signal(9);
                $killed = true;
                $deadline = self::now() + $killGraceSeconds;
            },
        );

        return $timer;
    }

    /**
     * Async {@see terminateBounded()}: the ladder on loop timers, then the reap
     * once the child is gone. $then receives the exit status, captured the same
     * way the sync call captures it: from the poll that saw the exit, falling
     * back to `proc_close()` only when the poll never did.
     *
     * A child that survives signal 9 within its budget is NOT reaped with a
     * blocking `proc_close()` — that would park the loop thread on exactly the
     * uninterruptible kernel wait the ladder cannot break. The handle is kept
     * and polled by {@see reapWhenExited()} until the kernel releases the
     * child, so the loop keeps serving every other connection in the meantime.
     * Trade-off: that poll is a loop timer, so a child that never dies holds
     * the loop open; a caller that must exit regardless cancels its own work.
     *
     * The group probe on this path is {@see groupEmpty()} with `$walkProc`
     * false: one `kill(-pgid, 0)` per tick and never a /proc walk. A group whose
     * members linger as zombies (no reaper collects them) therefore reads
     * non-empty and runs the full budget, then falls through to the reap poll.
     * The price is that latency, paid on a timer, never a loop freeze.
     *
     * An already-exited child is answered at once with no signal spent, and a
     * non-resource argument answers null, matching the sync contract.
     *
     * @param callable(?int):void|null $then
     */
    public static function terminateBoundedAsync(
        LoopInterface $loop,
        mixed $process,
        ?callable $then = null,
        float $graceSeconds = self::TERMINATE_GRACE_SECONDS,
        float $killGraceSeconds = self::KILL_GRACE_SECONDS,
        ?int $groupPid = null,
    ): void {
        $finish = static function (?int $status) use ($then): void {
            if ($then !== null) {
                $then($status);
            }
        };

        if (!\is_resource($process)) {
            $finish(null);

            return;
        }

        // Reap in every branch: the status poll only captures the exit code, it
        // does not release the handle, and a skipped proc_close() leaks it.
        $exitCode = null;
        if (self::gone($process, $groupPid, $exitCode, false)) {
            $reaped = \proc_close($process);
            $finish($exitCode ?? $reaped);

            return;
        }

        self::escalateAsync(
            $loop,
            static function (int $signal) use ($process, $groupPid): void {
                self::signal($process, $groupPid, $signal);
            },
            static function () use ($process, $groupPid, &$exitCode): bool {
                return self::gone($process, $groupPid, $exitCode, false);
            },
            static function (bool $gone) use ($loop, $process, &$exitCode, $finish): void {
                // Only a poll that saw the exit may take the blocking reap: then
                // proc_close() cannot wait. Anything else is deferred to the loop.
                if (!$gone) {
                    self::reapWhenExited($loop, $process, $finish);

                    return;
                }

                $reaped = \is_resource($process) ? \proc_close($process) : null;
                $finish($exitCode ?? $reaped);
            },
            $graceSeconds,
            $killGraceSeconds,
        );
    }

    /**
     * Reap a child on loop timers, never blocking: answer at once when it has
     * already exited, otherwise poll it every {@see REAP_POLL_SECONDS} and
     * `proc_close()` it the tick it is seen gone.
     *
     * This is the reap half of {@see terminateBoundedAsync()} for a child the
     * ladder could not stop. A synchronous `proc_close()` there would wait on
     * the child with no bound. The status reported is the one the poll captured,
     * matching the sync contract; a non-resource argument answers null.
     *
     * @param callable(?int):void|null $then
     */
    public static function reapWhenExited(
        LoopInterface $loop,
        mixed $process,
        ?callable $then = null,
        float $pollSeconds = self::REAP_POLL_SECONDS,
    ): void {
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
        if (self::exited($process, $exitCode)) {
            $reaped = \proc_close($process);
            $finish($exitCode ?? $reaped);

            return;
        }

        $timer = null;
        $timer = $loop->addPeriodicTimer(
            $pollSeconds,
            static function () use (&$timer, $loop, $process, $finish): void {
                // Another teardown may have closed the handle while this timer was
                // armed. exited() answers true for that, so without this guard the
                // proc_close() below would run on a consumed resource and throw.
                if (!\is_resource($process)) {
                    $loop->cancelTimer($timer);
                    $finish(null);

                    return;
                }

                $code = null;
                if (!self::exited($process, $code)) {
                    return;
                }

                $loop->cancelTimer($timer);
                $reaped = \proc_close($process);
                $finish($code ?? $reaped);
            },
        );
    }

    /** Monotonic seconds: the deadline clock every ladder variant shares. */
    private static function now(): float
    {
        return \hrtime(true) / 1_000_000_000;
    }

    /**
     * Poll $isGone until it answers true or the budget runs out. One poll
     * always happens before the first sleep and one after the last, so a
     * $budgetSeconds of 0.0 is "ask twice, sleep never in between".
     */
    public static function waitBounded(callable $isGone, float $budgetSeconds): bool
    {
        $deadline = \hrtime(true) / 1_000_000_000 + $budgetSeconds;

        do {
            if ($isGone()) {
                return true;
            }
            // An exhausted budget goes straight to the closing poll: sleeping
            // first would charge a zero-budget probe a full interval it never asked for.
            if (\hrtime(true) / 1_000_000_000 >= $deadline) {
                break;
            }
            \usleep(self::POLL_INTERVAL_US);
        } while (true);

        return $isGone();
    }

    /**
     * Poll the child up to $seconds for an exit, in bounded ticks — the
     * hasExited semantics every copy grew. Never signals; a false answer
     * means "still running when the budget ended", not "ungoneable".
     *
     * A non-resource argument answers true: nothing is running.
     *
     * @param mixed $process the value a `proc_open()` call returned
     */
    public static function hasExited(mixed $process, float $seconds): bool
    {
        if (!\is_resource($process)) {
            return true;
        }

        return self::waitBounded(
            static function () use ($process): bool {
                $code = null;

                return self::exited($process, $code);
            },
            $seconds,
        );
    }

    /**
     * Reap a child that has ALREADY EXITED, and NEVER signal one that has not.
     *
     * For the launcher shape — a process whose whole job is to fork a daemon
     * and get out of the way. It always exits within milliseconds, but "in
     * practice" is a race, and a ladder run against it mid-fork would SIGTERM
     * the very process creating the session. So: wait WITHOUT signalling up
     * to $budgetSeconds; if the child exited, `proc_close()` it (that call
     * cannot block — the wait established the child is gone) and return its
     * status. If it has not, return null and LEAVE THE HANDLE ALONE: PHP's
     * resource destructor reaps an exited child instantly and abandons a
     * running one in 0.000s without waiting, which for a launcher is the
     * correct outcome either way — the thing that must survive is the daemon,
     * and the daemon is not this process.
     *
     * @param mixed $process the value a `proc_open()` call returned
     *
     * @return int|null the exit status, or null if the child was still running
     */
    public static function reapIfExited(mixed $process, float $budgetSeconds = self::TERMINATE_GRACE_SECONDS): ?int
    {
        if (!\is_resource($process)) {
            return null;
        }

        if (!self::hasExited($process, $budgetSeconds)) {
            return null;
        }

        // The handle still goes through proc_close() — that call is the reap.
        // The status comes from the poll (a signal death reads -1, see
        // terminateBounded()) and proc_close() cannot block here, because the
        // bounded wait already established the child is gone.
        $exitCode = null;
        self::exited($process, $exitCode);
        $reaped = \proc_close($process);

        return $exitCode ?? $reaped;
    }

    /**
     * The process group a containment-wrapped child leads, or null when the
     * ladder must treat it as a plain direct child.
     *
     * The discriminator is MEASURED from the kernel (`posix_getpgid(child)
     * === child`) rather than tracked in a flag: a setsid-wrapped child IS
     * the group leader and everything it forks runs in that group, so
     * `kill(-pgid)` reaches wrapper and descendants alike — while an
     * UNWRAPPED child inherits the PHP process's own group, and firing a
     * group kill at it would signal THIS process. A stale bookkeeping flag
     * is how a containment mechanism grows into a self-kill.
     *
     * MUST BE COMPUTED WHILE THE CHILD IS ALIVE — a dead pid answers nothing.
     *
     * @param mixed $process the value a `proc_open()` call returned
     */
    public static function groupId(mixed $process): ?int
    {
        if (!\is_resource($process) || !\function_exists('posix_getpgid')) {
            return null;
        }

        // is_resource() above is the whole defense: a live resource yields
        // the fixed-shape status array with 'pid' always keyed.
        $status = \proc_get_status($process);
        $pid = (int) $status['pid'];
        if ($pid <= 0) {
            return null;
        }

        return \posix_getpgid($pid) === $pid ? $pid : null;
    }

    /**
     * Signal a child by PID, reaching its group when it leads one and itself
     * otherwise — the `proc_open()`-free twin of the rungs inside
     * {@see terminateAndAwaitExit()}.
     *
     * The pty path holds a library handle, not a proc resource, so there is
     * nothing for an `is_resource` gate to accept; the group-first order still
     * matters because the shell is not the program — killing only the shell
     * leaves the actual child running.
     *
     * @param int $signal an INTEGER signal number (15, 9, ...), never a
     *                  pcntl constant — same optional-extension doctrine.
     */
    public static function terminatePid(int $pid, int $signal = 15): bool
    {
        if ($pid <= 0 || !\function_exists('posix_kill')) {
            return false;
        }

        if (\function_exists('posix_getpgid') && \posix_getpgid($pid) === $pid && @\posix_kill(-$pid, $signal)) {
            return true;
        }

        return @\posix_kill($pid, $signal);
    }

    /**
     * The bounded ladder for a bare pid: {@see terminatePid()} rungs with a
     * {@see pidExited()} poll between them.
     *
     * Returns whether the process is gone by the end of the budgets. Without
     * ext-pcntl the liveness probe is a signal-0 test, which cannot tell a
     * ZOMBIE from a live child — a fork'd caller without pcntl must reap via
     * its own waitpid; this method reports "still answering signals", the
     * honest ceiling available to it.
     */
    public static function terminatePidBounded(
        int $pid,
        float $graceSeconds = self::TERMINATE_GRACE_SECONDS,
        float $killGraceSeconds = self::KILL_GRACE_SECONDS,
    ): bool {
        if ($pid <= 0) {
            throw new \InvalidArgumentException(Lang::t('proc.pid_positive', ['pid' => $pid]));
        }

        if (self::pidExited($pid)) {
            return true;
        }

        return self::escalate(
            static function (int $signal) use ($pid): void {
                self::terminatePid($pid, $signal);
            },
            static function () use ($pid): bool {
                return self::pidExited($pid);
            },
            $graceSeconds,
            $killGraceSeconds,
        );
    }

    /**
     * Whether a pid is gone. With ext-pcntl the probe is a `WNOHANG` waitpid,
     * which DOUBLES as the non-blocking reap for a direct child (a reaped pid
     * is gone by definition); a child of someone else answers `ECHILD` and
     * falls through to the signal-0 test. Without pcntl, the signal-0 test
     * alone — see {@see terminatePidBounded()} for its zombie caveat.
     */
    public static function pidExited(int $pid): bool
    {
        if ($pid <= 0) {
            throw new \InvalidArgumentException(Lang::t('proc.pid_positive', ['pid' => $pid]));
        }

        if (\function_exists('pcntl_waitpid')) {
            $status = 0;
            $reaped = \pcntl_waitpid($pid, $status, \WNOHANG);
            if ($reaped === $pid) {
                return true;
            }
            if ($reaped === 0) {
                return false;
            }
            // -1: not our direct child (or already reaped) — ask the kernel
            // whether the pid still takes signals instead.
        }

        if (!\function_exists('posix_kill')) {
            // No way to observe this pid from here; report "not gone" so a
            // ladder stays conservative rather than claiming a death it
            // cannot see.
            return false;
        }

        return !@\posix_kill($pid, 0);
    }

    /**
     * One rung on a proc handle: group signal when the caller named a group
     * this child leads (even an un-forwardable 9 cannot then orphan a
     * grandchild), direct `proc_terminate()` otherwise — and the group kill
     * falling through to the direct one when posix is absent or refused.
     *
     * @param mixed $process the value a `proc_open()` call returned
     */
    private static function signal(mixed $process, ?int $groupPid, int $signal): void
    {
        if ($groupPid !== null && \function_exists('posix_kill') && @\posix_kill(-$groupPid, $signal)) {
            return;
        }

        \proc_terminate($process, $signal);
    }

    /**
     * "Is the child gone?" for a handle, and for a containment-wrapped child
     * also "is its process group empty?".
     *
     * The leader exiting is not enough. A descendant that ignores SIGTERM
     * outlives its wrapper, and declaring the child gone at that point would
     * end the ladder before the signal 9 rung — the only rung that reaches it.
     * A non-resource is gone by definition, so a handle another teardown
     * already closed never keeps a poll alive.
     *
     * @param mixed $process the value a `proc_open()` call returned
     * @param bool $walkProc see {@see groupEmpty()}; false on the loop-driven path
     */
    private static function gone(mixed $process, ?int $groupPid, ?int &$exitCode, bool $walkProc = true): bool
    {
        if (!\is_resource($process)) {
            return true;
        }

        if (!self::exited($process, $exitCode)) {
            return false;
        }

        return $groupPid === null || self::groupEmpty($groupPid, $walkProc);
    }

    /**
     * Whether no live member remains in the process group $groupPid.
     *
     * Public so a sibling ladder (candy-code's ProcessReaper) applies the same
     * group rule instead of its own leader-only check. An empty group answers
     * at once; a non-empty one is re-scanned at most every
     * {@see REAP_POLL_SECONDS}, and between scans it answers "not empty".
     * The ladder therefore notices a drained group up to one poll cadence late,
     * which is the price of not walking /proc on every 5 ms tick.
     *
     * With $walkProc false the /proc walk is skipped entirely: the answer is the
     * kernel's `kill(-pgid, 0)` alone, so a zombie member counts as live until
     * its parent reaps it. That is the probe a loop-driven ladder can afford.
     * A full /proc walk costs tens of milliseconds on a busy host, and on the
     * event loop it would freeze every other connection once per cadence.
     */
    public static function groupEmpty(int $groupPid, bool $walkProc = true): bool
    {
        if (!\function_exists('posix_kill') || !@\posix_kill(-$groupPid, 0)) {
            unset(self::$groupScannedAt[$groupPid]);

            return true;
        }

        if (!$walkProc) {
            return false;
        }

        $now = self::now();
        if (isset(self::$groupScannedAt[$groupPid]) && $now - self::$groupScannedAt[$groupPid] < self::REAP_POLL_SECONDS) {
            return false;
        }

        if (self::noLiveMemberScanned($groupPid)) {
            unset(self::$groupScannedAt[$groupPid]);

            return true;
        }

        self::$groupScannedAt[$groupPid] = $now;

        return false;
    }

    /**
     * The /proc half of {@see groupEmpty()}: no member outside states Z and X.
     *
     * `kill(-pgid, 0)` answers true for a ZOMBIE as well as a live process, and
     * a zombie waiting on a reaper that is slow to act would otherwise hold the
     * ladder open for its whole budget. So members in state Z (or X) are
     * skipped when /proc can be read; without /proc the probe stays conservative
     * and counts them.
     */
    private static function noLiveMemberScanned(int $groupPid): bool
    {
        $stats = @\glob('/proc/[0-9]*/stat');
        if ($stats === false || $stats === []) {
            return false;
        }

        foreach ($stats as $statFile) {
            $stat = @\file_get_contents($statFile);
            if ($stat === false) {
                continue;
            }
            // "pid (comm) state ppid pgrp ..." — comm may hold spaces and parens,
            // so the match anchors on the LAST ") " before the fixed-shape tail.
            if (\preg_match('/^\d+ \(.*\) ([A-Za-z]) \d+ (\d+) /s', $stat, $m) === 1
                && (int) $m[2] === $groupPid
                && $m[1] !== 'Z'
                && $m[1] !== 'X'
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * "Is the child gone?" with the exit code captured on the way out of it.
     *
     * The is_resource guard is not defensive padding: `proc_get_status()` on
     * a resource `proc_close()` already consumed is a TypeError, and this
     * class is called from `__destruct()` paths where a double teardown is
     * normal. It is also the ONLY defense the poll needs: once the resource
     * is proven live, proc_get_status() answers the full fixed-shape array
     * (a mid-reap poll says running=false with exitcode filled, it never
     * drops an offset), so the keys are read directly.
     */
    private static function exited(mixed $process, ?int &$exitCode): bool
    {
        if (!\is_resource($process)) {
            return true;
        }

        $status = \proc_get_status($process);
        if ($status['running'] === true) {
            return false;
        }

        $exitCode = (int) $status['exitcode'];

        return true;
    }
}
