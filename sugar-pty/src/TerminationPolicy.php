<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Util\Proc\BoundedShutdown;
use SugarCraft\Pty\Posix\PosixChild;

/**
 * How a PTY child is torn down: a first signal (SIGHUP by default), a grace
 * period, SIGKILL, a confirmation window, and whether each signal reaches the
 * child's whole process group.
 *
 * WHY SIGHUP FIRST. A PTY child is told its terminal is gone by a hangup, not
 * by a TERM: SIGHUP is what the kernel itself delivers to the session when the
 * master closes, so it is the signal a well-behaved interactive program
 * (shell, editor, pager) is written to clean up on. SIGTERM stays available
 * through {@see withSignal()} for a caller whose child expects it.
 *
 * WHY GROUP REACH IS ON BY DEFAULT. The PTY spawn path makes the child a
 * session leader, and the child is usually a shell, not the program the user
 * ran. Signalling only the shell leaves its foreground job running; the group
 * signal reaches both. A child that leads no group (a plain `proc_open()`
 * child in the parent's group) falls back to the single-pid signal, so the
 * flag never reaches the caller's own group.
 *
 * ONE LADDER. The rung sequence is not reimplemented here: {@see apply()}
 * runs sugar-core's {@see BoundedShutdown::escalate()} and {@see applyAsync()}
 * runs {@see BoundedShutdown::escalateAsync()} (upstream plan decision D2,
 * option A). The ladder names its first rung 15; this policy delivers its own
 * first signal on that rung, and the literal 9 on the second.
 *
 * No charmbracelet upstream: the teardown sequence is original work for
 * sugar-pty's own child handles.
 */
final class TerminationPolicy
{
    /** Hangup: what the kernel sends a session whose terminal went away. */
    public const SIGHUP = 1;

    /** "No such process": the only kill(-pgid, 0) failure that means an empty group. */
    private const ERRNO_ESRCH = 3;

    /**
     * When each group last had a live member seen by the libc arm's /proc
     * walk, keyed by pgid.
     *
     * @var array<int, float>
     */
    private static array $groupScannedAt = [];

    public function __construct(
        public readonly int $signal = self::SIGHUP,
        public readonly float $graceSeconds = BoundedShutdown::TERMINATE_GRACE_SECONDS,
        public readonly float $killGraceSeconds = BoundedShutdown::KILL_GRACE_SECONDS,
        public readonly bool $groupReach = true,
    ) {
        $max = self::maxSignal();
        if ($signal < 1 || $signal > $max) {
            throw new \InvalidArgumentException(Lang::t('termination.signal_invalid', [
                'signal' => $signal,
                'max' => $max,
            ]));
        }
        self::assertBudget('graceSeconds', $graceSeconds);
        self::assertBudget('killGraceSeconds', $killGraceSeconds);
    }

    /** The default policy: SIGHUP, the core grace budgets, group reach on. */
    public static function new(): self
    {
        return new self();
    }

    /** The first signal, sent before the grace period starts. */
    public function withSignal(int $signal): self
    {
        return $this->mutate(['signal' => $signal]);
    }

    /** Seconds the child gets after the first signal before SIGKILL. */
    public function withGraceSeconds(float $seconds): self
    {
        return $this->mutate(['graceSeconds' => $seconds]);
    }

    /** Seconds allowed to confirm the SIGKILL landed. */
    public function withKillGraceSeconds(float $seconds): self
    {
        return $this->mutate(['killGraceSeconds' => $seconds]);
    }

    /** Whether each signal goes to the child's process group. */
    public function withGroupReach(bool $groupReach): self
    {
        return $this->mutate(['groupReach' => $groupReach]);
    }

    /**
     * Run the policy against $child, blocking for at most the two budgets.
     *
     * An already-gone child is answered at once with no signal spent. With
     * group reach, a group left holding only unreaped zombies reads gone
     * where /proc can be read; without /proc (Darwin) a zombie counts as a
     * live member, and the budgets run out to false.
     *
     * A signal the kernel refuses (EPERM, as for a root-owned child of an
     * unprivileged caller) does not throw: the child stays up, so the
     * budgets run out to false.
     *
     * @return bool whether the child (and, with group reach, its group) is
     *              gone by the end of the budgets
     */
    public function apply(PosixChild $child): bool
    {
        $isGone = $this->goneProbe($child, walkProc: true);
        if ($isGone()) {
            return true;
        }

        return BoundedShutdown::escalate(
            $this->deliverer($child),
            $isGone,
            $this->graceSeconds,
            $this->killGraceSeconds,
        );
    }

    /**
     * {@see apply()} on loop timers: the wait costs the loop nothing.
     *
     * Cancelling the returned promise cancels the ladder's poll timer; a
     * signal already sent is not taken back.
     *
     * ZOMBIES COUNT AS LIVE HERE. With group reach, the group probe is
     * `kill(-pgid, 0)` alone, never the /proc walk {@see apply()} uses
     * ({@see BoundedShutdown::groupEmpty()} with `$walkProc` false). A group
     * whose only survivors are unreaped zombies (no init reaps orphans, as
     * when PHP runs as PID 1 in a container) therefore reads non-empty: the
     * full grace and kill budgets run and the promise resolves false, where
     * apply() answers true for the same child at once on a host whose /proc
     * can be read (Linux, with or without ext-posix). The same trade-off
     * {@see BoundedShutdown::terminateBoundedAsync()} documents: latency
     * paid on a timer, never a loop freeze. A caller that must tell a
     * zombie-only group from a live one re-checks with
     * `BoundedShutdown::groupEmpty($child->pid())` after a false.
     *
     * A refused signal never throws, here or from a loop timer: it resolves
     * false once the budgets run out, as {@see apply()} returns false.
     *
     * @return PromiseInterface<bool> resolves with whether the child (and,
     *                                with group reach, its group) reads gone
     *                                by the end of the budgets; false also
     *                                covers a group left holding only zombies
     */
    public function applyAsync(PosixChild $child, ?LoopInterface $loop = null): PromiseInterface
    {
        $loop ??= Loop::get();
        // The loop-driven probe skips the /proc walk: a walk costs tens of
        // milliseconds and would run on every poll tick (BoundedShutdown::groupEmpty).
        $isGone = $this->goneProbe($child, walkProc: false);

        /** @var TimerInterface|null $timer */
        $timer = null;
        $deferred = new Deferred(static function (callable $resolve, callable $reject) use (&$timer, $loop): void {
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            $reject(new \RuntimeException(Lang::t('termination.async_cancelled')));
        });

        if ($isGone()) {
            $deferred->resolve(true);

            return $deferred->promise();
        }

        $timer = BoundedShutdown::escalateAsync(
            $loop,
            $this->deliverer($child),
            $isGone,
            static function (bool $gone) use ($deferred): void {
                $deferred->resolve($gone);
            },
            $this->graceSeconds,
            $this->killGraceSeconds,
        );

        return $deferred->promise();
    }

    /**
     * The ladder's signal callable. Rung 15 carries this policy's first
     * signal; rung 9 is the literal 9 (the ladder's ext-pcntl doctrine).
     *
     * The group signal is sent only while the group still exists, which is
     * also what keeps it safe after the leader is reaped: Linux does not hand
     * a pid out again while a live group still uses it as its id.
     *
     * @return callable(int):void
     */
    private function deliverer(PosixChild $child): callable
    {
        $first = $this->signal;
        $groupReach = $this->groupReach;

        return static function (int $rung) use ($child, $first, $groupReach): void {
            $signal = $rung === 9 ? 9 : $first;
            $pid = $child->pid();
            if ($groupReach && (!$child->exited() || !self::groupEmpty($pid, false))
                && Libc::kill(-$pid, $signal)
            ) {
                return;
            }
            // PosixChild::kill() keeps its post-reap pid-reuse guard.
            try {
                $child->kill($signal);
            } catch (PtyException) {
                // A refused signal (EPERM: a child this uid may not signal)
                // reads as "not gone", never as a throw: the ladder keeps
                // probing and the budgets run out to false. In applyAsync()
                // this callable runs on a loop timer, where a throw would
                // escape $loop->run().
            }
        };
    }

    /**
     * The ladder's exited?-poll: the child alone, or with group reach also
     * an empty group, so a grandchild that survives the first signal still
     * gets the SIGKILL rung after the shell itself has exited.
     *
     * @return callable():bool
     */
    private function goneProbe(PosixChild $child, bool $walkProc): callable
    {
        $groupReach = $this->groupReach;

        return static function () use ($child, $groupReach, $walkProc): bool {
            if (!$child->exited()) {
                return false;
            }

            return !$groupReach || self::groupEmpty($child->pid(), $walkProc);
        };
    }

    /**
     * {@see BoundedShutdown::groupEmpty()}, or the libc probe when ext-posix
     * is absent.
     *
     * Core's probe answers "empty" outright without `posix_kill()`, while this
     * policy's signals still reach the group through {@see Libc::kill()} over
     * FFI. Trusting that answer would end the ladder as soon as the leader
     * exits and spare a grandchild that ignored the first signal.
     */
    private static function groupEmpty(int $groupPid, bool $walkProc): bool
    {
        if (\function_exists('posix_kill')) {
            return BoundedShutdown::groupEmpty($groupPid, $walkProc);
        }

        if (self::groupEmptyViaLibc($groupPid)) {
            unset(self::$groupScannedAt[$groupPid]);

            return true;
        }

        return $walkProc && self::noLiveMemberScannedThrottled($groupPid);
    }

    /**
     * The /proc half of the libc arm, so apply() reads a zombie-only group
     * as gone with or without ext-posix, as core's probe does. Throttled per
     * group like core's walk: the sync ladder asks on every 5 ms tick and a
     * walk costs tens of milliseconds on a busy host.
     */
    private static function noLiveMemberScannedThrottled(int $groupPid): bool
    {
        $now = \hrtime(true) / 1_000_000_000;
        if (isset(self::$groupScannedAt[$groupPid])
            && $now - self::$groupScannedAt[$groupPid] < BoundedShutdown::REAP_POLL_SECONDS
        ) {
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
     * No member of $groupPid outside states Z and X, read from /proc. Without
     * /proc the answer is false: a zombie then counts as live.
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
     * The ext-posix-less arm of the group probe: libc `kill(-pgid, 0)`.
     *
     * Only ESRCH means no member is left; EPERM is a live member this uid
     * cannot signal. There is no /proc walk here, so an unreaped zombie
     * member counts as live, the same conservative reading
     * {@see applyAsync()} documents; {@see apply()} adds the walk on top.
     *
     * @internal public only so the arm is testable on hosts that DO load
     *           ext-posix, where the policy never reaches it.
     * @throws PtyException if libc cannot be loaded
     */
    public static function groupEmptyViaLibc(int $groupPid): bool
    {
        return !Libc::killViaLibc(-$groupPid, 0) && Libc::errno() === self::ERRNO_ESRCH;
    }

    /**
     * The host's highest signal number: SIGRTMAX on Linux, NSIG - 1 on
     * Darwin. An out-of-range signal fails here, not as a kill() error at
     * teardown time.
     *
     * Any other family (the BSDs, Solaris) gets 31, the range every POSIX
     * host shares: their ceilings differ per OS (OpenBSD stops at 32), and a
     * too-high guess would let a signal through that kill() then refuses,
     * which the ladder reads as "not gone" until both budgets run out.
     */
    private static function maxSignal(string $osFamily = \PHP_OS_FAMILY): int
    {
        return match ($osFamily) {
            'Linux' => 64,
            default => 31,
        };
    }

    /** @param array<string, mixed> $changes */
    private function mutate(array $changes): self
    {
        return new self(...\array_merge(\get_object_vars($this), $changes));
    }

    private static function assertBudget(string $name, float $seconds): void
    {
        if (!\is_finite($seconds) || $seconds < 0.0) {
            throw new \InvalidArgumentException(Lang::t('termination.budget_invalid', [
                'name' => $name,
                'seconds' => $seconds,
            ]));
        }
    }
}
