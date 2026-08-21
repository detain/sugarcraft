<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * The temp files a forked tool child hands its result back through, and the
 * only place that knows how they are named, created and cleaned up.
 *
 * Two independent dispatchers use this IPC — {@see \SugarCraft\Crush\Runtime}'s
 * concurrent groups (`sc_runtime_tool_*.bin`, `serialize()`d) and
 * {@see \SugarCraft\Crush\Chat}'s own tool batches (`sc_chat_tool_*.json`) —
 * and both had the same two problems, which is why the fix lives here rather
 * than twice over in each of them.
 *
 * **They were world-readable.** `file_put_contents()` creates at 0666 minus
 * the ambient umask, i.e. 0664 on a normal box, and the payload is a whole
 * {@see \SugarCraft\Crush\Tools\ToolResult}: file bodies, grep hits, fetched
 * pages. `/tmp` is world-listable, so an unguessable filename is not a
 * substitute for a mode. {@see write()} creates at 0600 by narrowing the umask
 * around the create rather than `chmod()`-ing afterwards — the chmod ordering
 * leaves a window in which the payload is on disk and readable.
 *
 * **They leaked forever on cancel.** Each dispatcher unlinks a payload when it
 * collects it, and that is the ONLY unlink either of them has. It never runs
 * when the completion child is SIGKILLed out from under the group — an
 * Escape-Escape cancel, or {@see \SugarCraft\Crush\Backend\EngineBackend}'s
 * idle timeout — because the orphaned tool grandchildren keep running and
 * write payloads nobody is left to collect. {@see sweep()} is the reaper of
 * last resort for exactly those — and, since it is the only reaper this
 * program has, for {@see HOOK_PAYLOAD_PREFIX} as well.
 *
 * @see \SugarCraft\Crush\Tools\ParallelSafe for the orphan lifecycle itself
 */
final class ToolIpcFiles
{
    /** {@see \SugarCraft\Crush\Runtime::executeConcurrently()}'s payloads. */
    public const RUNTIME_PREFIX = 'sc_runtime_tool_';

    /** {@see \SugarCraft\Crush\Chat::forkToolCalls()}'s payloads. */
    public const CHAT_PREFIX = 'sc_chat_tool_';

    /**
     * {@see \SugarCraft\Crush\Hooks\ScriptHook}'s payload files — the same
     * class of leak from a different direction, which is why they are swept
     * here rather than given a reaper of their own.
     *
     * These are NOT written by this class: `ScriptHook` makes them with
     * `tempnam()` and deletes them in a `finally` that covers every in-process
     * exit it has, the timeout and the SIGKILL escalation included (measured:
     * zero left behind over a full suite run). What no `finally` covers is the
     * process dying under it — a SIGKILL to sugar-crush itself, or a PHP fatal
     * — and what is stranded then is a 0600 copy of the tool call's arguments
     * in a world-listable directory, with nothing that would ever reap it.
     */
    public const HOOK_PAYLOAD_PREFIX = 'crush-hook-payload-';

    /**
     * Written first and renamed into place, because rename(2) is atomic within
     * a filesystem: a child SIGKILLed mid-write must not leave a
     * half-serialized payload the parent could read back as a truncated
     * result.
     */
    private const PARTIAL_SUFFIX = '.partial';

    /**
     * How old an abandoned payload must be before {@see sweep()} will remove
     * it.
     *
     * The cutoff has one job: never delete a file belonging to a LIVE run,
     * including another sugar-crush process on the same box, whose files this
     * process cannot tell apart from its own. Age is the only signal available
     * for that, and the margin is deliberately enormous relative to the real
     * window. A payload exists from the instant its child finishes writing
     * until its parent reaps it — microseconds in the normal case, and bounded
     * above in the worst case by the dispatcher that owns it:
     * {@see \SugarCraft\Crush\Runtime}'s 90s group deadline,
     * {@see \SugarCraft\Crush\Chat}'s 30s tool timeout, and above both
     * `EngineBackend::COMPLETE_TIMEOUT_SECONDS` (120s of silence). An hour is
     * ~30x the largest of those, so a file this old cannot belong to anything
     * still waiting for it, while nothing short of an hour is ever gained by
     * sweeping sooner — these are bytes in `/tmp`, not a resource under
     * contention.
     */
    private const STALE_AFTER_SECONDS = 3600;

    /**
     * S_IFMT / S_IFREG, spelled out because PHP exposes neither: a planted
     * symlink or a directory wearing one of our prefixes is left strictly
     * alone. We are cleaning up after ourselves, not policing `/tmp`.
     */
    private const STAT_TYPE_MASK = 0o170000;

    private const STAT_REGULAR_FILE = 0o100000;

    /**
     * {@see sweepOnce()}'s latch. Per-process, and a forked child gets a fresh
     * copy — harmless, because a sweep is idempotent and the child never
     * reaches the startup path that calls it.
     */
    private static bool $swept = false;

    /**
     * Paths {@see reserve()} has handed out since {@see recordReservations()}
     * armed the ledger, or null when it is not armed -- which is production,
     * always, so a long session pays nothing for this at all.
     *
     * A payload's name is `prefix . bin2hex(random_bytes(8))`: it carries no
     * pid, no run id, nothing that ties a file to the process that reserved
     * it. {@see STALE_AFTER_SECONDS} records that as a deliberate constraint
     * rather than an oversight -- `sweep()` attributes by AGE precisely
     * because identity is not recoverable from a file lying in a shared
     * `/tmp`.
     *
     * The one place identity does exist is the parent that chose the name,
     * and that is here. A ledger written at reservation time is therefore the
     * only way a caller can ask "did the payloads *I* reserved get collected"
     * instead of the much weaker "did any `sc_chat_tool_*` file appear in the
     * temp dir while I was running", which a sibling process on the same box
     * answers yes to.
     *
     * @var list<string>|null
     */
    private static ?array $reserved = null;

    /**
     * Arm or disarm the reservation ledger, clearing it either way.
     *
     * This exists for the test suite, in the same spirit as {@see sweep()}'s
     * `$dir` parameter: {@see \SugarCraft\Crush\Tests\ChatTest} strands a
     * payload if any of its tests drops the Cmd that collects one, and the
     * only honest way to detect that is to compare against the paths this
     * process actually reserved.
     *
     * Off by default and never armed in production: nothing on a real run
     * calls this, so `reserve()` keeps appending to nothing.
     */
    public static function recordReservations(bool $record): void
    {
        self::$reserved = $record ? [] : null;
    }

    /**
     * Of the paths reserved since the ledger was armed, those still on disk --
     * either the payload itself or the `.partial` a child SIGKILLed mid-write
     * leaves beside it.
     *
     * Attribution is by identity, not by window. A file this process did not
     * reserve cannot enter this list whatever else is happening in the temp
     * dir, so a concurrent `sugar-crush` run (or a sibling test lane) is
     * structurally incapable of turning this into a false positive. The
     * converse residual is worth stating: this can only see payloads whose
     * path came from {@see reserve()} IN THIS PROCESS. That covers every one
     * of them today -- the two dispatchers are the only callers
     * ({@see \SugarCraft\Crush\Chat::forkToolCalls()},
     * {@see \SugarCraft\Crush\Runtime::executeConcurrently()}) and both go
     * through here -- and a `pcntl_fork()` child is covered too, because the
     * parent reserves the name before the fork. A payload written by a
     * SEPARATE process this suite spawns is not, and that is the deliberate
     * trade: those are exactly the files nothing can distinguish from another
     * developer's.
     *
     * @return list<string>
     */
    public static function strandedReservations(): array
    {
        $stranded = [];

        foreach (self::$reserved ?? [] as $path) {
            foreach ([$path, $path . self::PARTIAL_SUFFIX] as $candidate) {
                if (file_exists($candidate)) {
                    $stranded[] = $candidate;
                }
            }
        }

        sort($stranded);

        return $stranded;
    }

    /**
     * Reserve a payload path. Nothing is created here: the name is chosen in
     * the PARENT, before the fork, so both sides agree on it, and only the
     * child ever writes.
     *
     * The path is recorded when {@see recordReservations()} has armed the
     * ledger, so a caller can later ask which of its OWN payloads went
     * uncollected -- see {@see strandedReservations()}.
     */
    public static function reserve(string $prefix, string $extension): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8)) . '.' . $extension;

        if (self::$reserved !== null) {
            self::$reserved[] = $path;
        }

        return $path;
    }

    /**
     * Write one payload, privately and atomically. Runs in the forked child.
     *
     * The umask narrowing (rather than a `chmod()` after the fact) is what
     * makes 0600 true for the whole life of the bytes; `rename()` preserves
     * the mode, so the visible file is 0600 too. Changing the umask is
     * process-global, but this runs in a child whose entire remaining job is
     * this write — and it is restored regardless, so the in-process fallback
     * path a fork failure degrades to cannot inherit it either.
     */
    public static function write(string $file, string $payload): bool
    {
        $partial = $file . self::PARTIAL_SUFFIX;

        $previous = umask(0o077);

        try {
            if (@file_put_contents($partial, $payload) === false) {
                return false;
            }
        } finally {
            umask($previous);
        }

        return @rename($partial, $file);
    }

    /**
     * Drop a payload and any partial sibling left beside it — the normal,
     * successful end of one child's IPC, called by whoever collected it.
     */
    public static function discard(string $file): void
    {
        @unlink($file);
        @unlink($file . self::PARTIAL_SUFFIX);
    }

    /**
     * Sweep at process start, at most once. Wired into
     * {@see \SugarCraft\Crush\Cli\Bootstrap::backend()} /
     * {@see \SugarCraft\Crush\Cli\Bootstrap::backendFor()}, which every real
     * run passes through exactly once.
     *
     * Startup is the only moment with a guarantee worth having: the files that
     * leak are the ones whose owning process was killed, so the owner can
     * never clean them up itself and no amount of in-run diligence would find
     * them.
     *
     * $dir exists for the test suite. Production passes nothing and sweeps the
     * real `sys_get_temp_dir()`; a suite whose many test files all reach
     * {@see \SugarCraft\Crush\Cli\Bootstrap::backend()} would otherwise have
     * whichever of them ran first spend the process's one sweep on the
     * developer's actual `/tmp`. Spending the latch up front on a throwaway
     * directory (tests/bootstrap.php) leaves nothing for a test to spend on the
     * real one. `TMPDIR` cannot do this job — PHP resolves and caches
     * `sys_get_temp_dir()` once per process.
     */
    public static function sweepOnce(?string $dir = null): int
    {
        if (self::$swept) {
            return 0;
        }

        self::$swept = true;

        return self::sweep($dir);
    }

    /**
     * Remove abandoned payloads from $dir, returning how many were unlinked.
     *
     * All three prefixes, and the `.partial` siblings of the two this class
     * writes, because a cancel strands either dispatcher's shape and a killed
     * process strands a hook's. Deliberately conservative: only regular files, only ones
     * this uid owns, only ones older than $olderThanSeconds — a sweep that
     * deleted a live run's payload would turn a leak into a lost tool result.
     *
     * The uid compared is the EFFECTIVE one, because that is the uid the kernel
     * itself checks when the `unlink()` below actually runs: under setuid the
     * real uid would have this filter accepting files the syscall then refuses,
     * and skipping files the syscall would happily remove. The filter is
     * skipped entirely on a posix-less build — it is a courtesy to other users
     * of a shared `/tmp`, not the safety property. That is carried by the three
     * constraints which always apply: our own prefix, older than the cutoff,
     * and a regular file by `lstat()` (so a symlink is never followed and a
     * planted one is never removed).
     */
    public static function sweep(?string $dir = null, ?int $olderThanSeconds = null): int
    {
        $dir ??= sys_get_temp_dir();
        $cutoff = $olderThanSeconds ?? self::STALE_AFTER_SECONDS;
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $now = time();

        $removed = 0;
        foreach ([self::RUNTIME_PREFIX, self::CHAT_PREFIX, self::HOOK_PAYLOAD_PREFIX] as $prefix) {
            foreach (glob($dir . '/' . $prefix . '*') ?: [] as $path) {
                $stat = @lstat($path);
                if ($stat === false || ($stat['mode'] & self::STAT_TYPE_MASK) !== self::STAT_REGULAR_FILE) {
                    continue;
                }

                if ($uid !== null && $stat['uid'] !== $uid) {
                    continue;
                }

                // A future mtime (clock skew, a restored backup) reads as
                // age <= 0 and is left alone rather than treated as ancient.
                if ($now - (int) $stat['mtime'] < $cutoff) {
                    continue;
                }

                if (@unlink($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
