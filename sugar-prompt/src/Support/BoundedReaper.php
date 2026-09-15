<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Support;

/**
 * Bounded SIGTERM→poll→SIGKILL→poll teardown for a `pcntl_fork()`ed child pid.
 *
 * E711 measured the failure shape this exists to close: the spinner animates
 * while a forked worker runs the action, and the animation loop's only exit
 * was "the child reaped". A worker wedged on anything — a pipe nobody drains,
 * a lock held by a dead peer — kept the parent spinning forever, and the
 * signal handlers that looked like the escape hatch made it worse: they sent
 * SIGTERM and then called an UNSFLAGGED `pcntl_waitpid()`, which hands the
 * parent's fate to a child that is free to ignore SIGTERM. The spinner child
 * is the only production `pcntl_fork()` outside sugar-crush (phase-4 pattern
 * sweep §3/§5), and sugar-crush's canonical `Support\ProcessReaper` is
 * unreachable from here: a prompt component must not drag an agent runtime
 * into its dependency tree for one helper. The per-package copy is the
 * established precedent — sugar-reel (`Support\BoundedReaper`) and sugar-dash
 * (`ExternalModule::terminateBounded()`) each keep their own for the same
 * reason stated in the same numbers.
 *
 * THE LADDER. Send TERM, poll with `WNOHANG` in 10 ms ticks, send 9
 * (uncatchable), poll again, and STOP THERE. Signal numbers are integer
 * literals because ext-pcntl's constants are not what gates this path —
 * ext-POSIX is the genuinely uncertain dependency, and
 * {@see self::signal()} is the one place that doubt is expressed.
 *
 * NEVER AN UNSFLAGGED WAIT, ON ANY PATH. A `pcntl_waitpid()` without
 * `WNOHANG` is exactly the wedge this class exists to remove (round-66's
 * r66 lesson: a wait-style loop can only hang, never fail). When the last
 * bounded poll runs out, the child is in an uninterruptible kernel wait or
 * this build has no ext-POSIX to signal with at all; the only honest answer
 * is "not reaped" (null), never a hope expressed as a blocking call.
 */
final class BoundedReaper
{
    /** Seconds a forked child gets to honour SIGTERM before signal 9. */
    public const TERM_SECONDS = 1.0;

    /** Window to confirm a SIGKILL landed (9 cannot be caught; this is a leash, not a hope). */
    public const KILL_SECONDS = 1.0;

    /** Poll granularity in microseconds. */
    private const TICK_MICROS = 10_000;

    /** Integer signal literals — see the class docblock for why not the constants. */
    private const SIGTERM_NUMBER = 15;
    private const SIGKILL_NUMBER = 9;

    private function __construct()
    {
    }

    /**
     * TERM, bounded poll, KILL, bounded poll — returning the child's raw
     * wait status as soon as a `WNOHANG` reap catches it.
     *
     * The status is REPORTED, not interpreted: callers that care whether the
     * child exited or was signalled run it through `pcntl_wifexited()` /
     * `pcntl_wtermsig()` themselves, so the ladder stays one mechanism for
     * every family of caller. A "reaped elsewhere" result (`waitpid` answers
     * -1: not ours any more) is reported as gone with a zero status — the
     * spin's contract is "the child is no longer running", and after -1
     * there is nothing left to learn.
     *
     * @return ?int the wait status if the child exited within the two
     *         budgets, or null when it survived the whole ladder (only an
     *         uninterruptible wait, or no ext-POSIX to signal with, does
     *         that) — in which case the caller must decide between
     *         abandoning the pid and hanging, and abandoning is the half
     *         this library will do.
     */
    public static function escalatePid(int $pid, float $termSeconds = self::TERM_SECONDS, float $killSeconds = self::KILL_SECONDS): ?int
    {
        self::signal($pid, self::SIGTERM_NUMBER);
        $status = self::pollReap($pid, $termSeconds);
        if ($status !== null) {
            return $status;
        }

        self::signal($pid, self::SIGKILL_NUMBER);

        return self::pollReap($pid, $killSeconds);
    }

    /**
     * Deliver one rung's signal — or deliver nothing at all, loudly bounded:
     * `posix_kill()` is ext-POSIX while the fork that made this child is
     * ext-pcntl, and the two are separately compilable (the same doubt
     * sugar-crush's `BackgroundSessionRunner::signalWorker()` states). In a
     * build with only the latter there is nothing to signal the child WITH —
     * which is exactly the build in which an unflagged wait would hang
     * forever, so the guard and the bounded ladder are one fix seen from
     * two sides.
     */
    private static function signal(int $pid, int $signal): void
    {
        if (\function_exists('posix_kill') === TRUE) {
            @\posix_kill($pid, $signal);
        }
    }

    /**
     * Poll `pcntl_waitpid(WNOHANG)` until the child is reaped, is gone, or
     * the budget runs out; the wait status in the first two cases, null in
     * the third.
     */
    private static function pollReap(int $pid, float $seconds): ?int
    {
        $deadline = \microtime(true) + $seconds;
        do {
            $status = 0;
            $check = @\pcntl_waitpid($pid, $status, \WNOHANG);
            if ($check === $pid) {
                return $status;
            }
            if ($check === -1) {
                return 0;
            }
            \usleep(self::TICK_MICROS);
        } while (\microtime(true) < $deadline);

        return null;
    }
}
