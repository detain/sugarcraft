<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Support;

/**
 * Bounded SIGTERM→poll→SIGKILL teardown for a `proc_open()` handle.
 *
 * E366 measured the two failure shapes this exists to close, on PHP 8.3.6 /
 * Linux 6.8 against a direct child that installs a no-op SIGTERM handler:
 * `proc_terminate()` immediately followed by `proc_close()` BLOCKS —
 * `proc_close()` waits for the child, so it hands the caller's shutdown
 * deadline to ffplay, mpv or ffmpeg, which is by definition somebody else's
 * binary — and dropping a handle whose child is still live without any
 * close at all ABANDONS it: PHP's resource destructor reaps an exited child
 * but never waits for a running one, and the orphan keeps every descriptor
 * above 2 that the parent held when it spawned. sugar-reel had both shapes:
 * `AudioPlayer::stop()/pause()/resume()` terminated-then-closed with no
 * escalation and no destructor at all, and `FfmpegDecoder::close()` was a
 * bare `proc_close()` behind a pipe that only the child's own exit drains.
 *
 * THE LADDER. Send TERM, poll in 10 ms ticks, send 9 (uncatchable), confirm
 * the exit. Signal numbers are literals because sugar-reel does not require
 * ext-pcntl and `proc_terminate()` takes the number directly. After
 * {@see terminateNow()} or {@see terminateAfterGrace()} returns, the child
 * is exited on every path the escalation can reach, so the caller's
 * `proc_close()` reaps rather than waits — that call remains the contract
 * for "and forget its exit status".
 *
 * WHY A COPY RATHER THAN THE CANONICAL LADDER: the tree's shared one lives
 * in sugar-crush (`Support\ProcessReaper`), and sugar-reel neither requires
 * sugar-crush nor may — a media component must not drag an agent runtime
 * into its dependency tree for one helper. The same argument and the same
 * numbers are spelled out at `sugar-dash`'s `ExternalModule::terminateBounded()`;
 * this class exists so the two sugar-reel consumers share ONE copy rather
 * than growing their own.
 */
final class BoundedReaper
{
    /** Seconds a child gets to exit on its own once its pipes are closed. */
    public const GRACE_SECONDS = 1.5;

    /** Seconds between SIGTERM and escalation to SIGKILL. */
    public const TERM_SECONDS = 1.0;

    /** Window to confirm a SIGKILL landed (9 cannot be caught; this is a leash, not a hope). */
    public const KILL_SECONDS = 1.0;

    /** Poll granularity in microseconds. */
    private const TICK_MICROS = 10_000;

    private const SIGTERM = 15;
    private const SIGKILL = 9;

    private function __construct()
    {
    }

    /**
     * TERM, bounded poll, KILL, confirm — no natural-exit grace first.
     *
     * For callers whose whole contract IS "stop now": an audio player being
     * paused or restarted has nothing to wait politely for.
     *
     * @param resource $process
     */
    public static function terminateNow($process): void
    {
        self::terminate($process, 0.0);
    }

    /**
     * Give the child a bounded window to exit on its own — its stdin or a
     * just-closed output pipe is the polite signal — then escalate.
     *
     * For callers that reach the child through a pipe the child drains, so
     * closing the pipe already says "leave"; the grace is only for how long
     * we believe it.
     *
     * @param resource $process
     */
    public static function terminateAfterGrace($process, float $graceSeconds = self::GRACE_SECONDS): void
    {
        self::terminate($process, $graceSeconds);
    }

    /**
     * @param resource $process
     */
    private static function terminate($process, float $graceSeconds): void
    {
        if ($graceSeconds > 0.0 && self::hasExited($process, $graceSeconds)) {
            return;
        }

        @proc_terminate($process, self::SIGTERM);
        if (!self::hasExited($process, self::TERM_SECONDS)) {
            @proc_terminate($process, self::SIGKILL);
            self::hasExited($process, self::KILL_SECONDS);
        }
    }

    /**
     * Poll the child up to $seconds for an exit, in ticks.
     *
     * `proc_get_status()` reaps internally once the child is gone, so a
     * false 'running' here means `proc_close()` cannot block afterwards.
     *
     * @param resource $process
     */
    public static function hasExited($process, float $seconds): bool
    {
        $deadline = \microtime(true) + $seconds;
        do {
            if (!(bool) (\proc_get_status($process)['running'] ?? false)) {
                return true;
            }
            \usleep(self::TICK_MICROS);
        } while (\microtime(true) < $deadline);

        return !(bool) (\proc_get_status($process)['running'] ?? false);
    }
}
