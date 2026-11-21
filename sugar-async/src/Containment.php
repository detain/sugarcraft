<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * The process-group kill ladder for a child started behind a containment wrapper
 * (a `setsid` session leader, or any child that leads its own group).
 *
 * A wrapped child IS its group leader, so `kill(-pgid)` reaches the wrapper and
 * every descendant it forked. An unwrapped child inherits this process's group,
 * and a group kill would signal this process, so the group is only targeted when
 * the kernel says the child leads one ({@see groupId()}: `posix_getpgid(pid) ===
 * pid`, measured, never tracked in a flag).
 *
 * Every rung uses integer signal numbers, and the bounded ladders are the
 * sugar-core {@see BoundedShutdown} ones, so a rung or budget change lands here
 * and in {@see ChildRun} at once. candy-code's ProcessContainment delegates its
 * group-level work to this class; its /proc tree walk stays with the app.
 *
 * Mirrors the containment doctrine of candy-code's ProcessContainment, promoted
 * to the library so every process runner shares one ladder.
 */
final class Containment
{
    /**
     * The group id to signal for a child, or null when it leads no group.
     *
     * Only reliable WHILE THE CHILD IS ALIVE: proc_get_status() reaps a dead
     * leader, after which the group reads as absent even though its members live.
     * Callers that signal later record the id at spawn time and pass it as
     * `$groupPid` to the other methods.
     *
     * @param mixed $process the value a proc_open() call returned
     */
    public static function groupId(mixed $process): ?int
    {
        return BoundedShutdown::groupId($process);
    }

    /**
     * Signal the group when there is one, else the direct child. True when the
     * signal was delivered to the group or the child.
     *
     * @param mixed $process the value a proc_open() call returned
     */
    public static function signal(mixed $process, ?int $groupPid, int $signal): bool
    {
        if ($groupPid !== null && \function_exists('posix_kill') && @\posix_kill(-$groupPid, $signal)) {
            return true;
        }

        return \is_resource($process) && @\proc_terminate($process, $signal);
    }

    /**
     * Signal a child in a way that reaches its descendants: the group when the
     * child leads one (measured now), else proc_terminate().
     *
     * @param mixed $process the value a proc_open() call returned
     */
    public static function terminate(mixed $process, int $signal = 15): bool
    {
        if (!\is_resource($process)) {
            return false;
        }

        return self::signal($process, self::groupId($process), $signal);
    }

    /**
     * The TERM, bounded poll, KILL, bounded poll ladder, then the reap. Blocks the
     * caller; see {@see BoundedShutdown::terminateBounded()} for the contract. A
     * non-null `$groupPid` makes every rung signal the group, and the child counts
     * as gone only once the group is empty too.
     *
     * @param mixed $process the value a proc_open() call returned
     */
    public static function terminateBounded(
        mixed $process,
        ?int $groupPid = null,
        float $graceSeconds = BoundedShutdown::TERMINATE_GRACE_SECONDS,
        float $killGraceSeconds = BoundedShutdown::KILL_GRACE_SECONDS,
    ): ?int {
        return BoundedShutdown::terminateBounded($process, $graceSeconds, $killGraceSeconds, $groupPid);
    }

    /**
     * The loop-driven ladder: the same rungs as {@see terminateBounded()} on loop
     * timers, never blocking. `$then` receives the exit status, or null when the
     * child cannot be reaped yet.
     *
     * @param mixed $process the value a proc_open() call returned
     * @param null|callable(?int): void $then
     */
    public static function terminateBoundedAsync(
        LoopInterface $loop,
        mixed $process,
        ?int $groupPid = null,
        ?callable $then = null,
        float $graceSeconds = BoundedShutdown::TERMINATE_GRACE_SECONDS,
        float $killGraceSeconds = BoundedShutdown::KILL_GRACE_SECONDS,
    ): void {
        BoundedShutdown::terminateBoundedAsync($loop, $process, $then, $graceSeconds, $killGraceSeconds, $groupPid);
    }
}
