<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The Windows tree-kill: `taskkill /T /F /PID <pid>` as a short-lived child.
 *
 * WHY THIS EXISTS (C-TUI-6.a). Every kill path in {@see ProcessContainment}
 * ends in `posix_kill()` behind a `function_exists` guard, and ext-posix is
 * absent on stock Windows builds — so the guards returned early and NOTHING
 * was signalled: `terminate()` fell to `proc_terminate()`, which on Windows
 * only reaches the direct child handle, and a `Bash` command's whole
 * process tree — the `bash.exe` it exec'd, every compiler, test runner or
 * `git push` it started — outlived its "killed" parent. {@see killTree()}'s
 * freeze-walk needs `/proc` and process groups, both POSIX concepts;
 * {@see ProcessTree} is Linux-only by construction, and this file never
 * consults it.
 *
 * `taskkill /T /F` is the Windows primitive that matches what the POSIX
 * group kill MEANS on Linux: `/T` walks the child tree — the one thing
 * `proc_terminate` cannot do — and `/F` forces the close, the semantic floor
 * of SIGKILL. There is no second rung to escalate through, and $signal is
 * not consulted: Windows offers no tree-reaching soft signal a console child
 * can act on, so promising a graceful TERM before the force would be a
 * ladder made of one rung. Callers get the whole truth from one shot —
 * which is why the grace parameter of the kill paths costs nothing here
 * (no waiting happens; the kill is already maximal).
 *
 * The taskkill child runs through {@see ProcessContainment::spawnSpec()}:
 * on a Windows host the detach probe returns no prefix (there is no
 * `setsid(1)` to probe), so the argv rides the array form UNCHANGED — which
 * is exactly the shape PHP hands CreateProcess, quoted by its rules, never
 * through cmd.exe. {@see self::tree()} is the public entry point; the
 * default runner is bounded ({@see CHILD_BOUND_SECONDS}) so a wedged
 * taskkill can never extend a teardown past its declared window — a wall
 * the taskkill child gets, not a blanket timeout on anything user-facing.
 *
 * The class is seam-testable as data on any host — {@see self::tree()}
 * routes through {@see self::setRunnerForTesting()} — so the argv table and
 * the refusal edges EXEC on Linux, and the real-child path is exercised
 * against two binaries that exist HERE (`/bin/true`, `/bin/false` stand in
 * for taskkill's exit-code contract) without pretending to be Windows.
 */
final class WindowsTaskKill
{
    /**
     * Upper bound on the taskkill child's own life: drain windows × poll is
     * how long a teardown may wait on the killer ITSELF before it is
     * terminated and the kill judged failed. taskkill answers in
     * milliseconds; this exists for the wedged-host edge, bounded by
     * declaration rather than grown from a timer nobody owns.
     */
    public const CHILD_BOUND_SECONDS = 5.0;

    /**
     * Test-only argv runner; null runs the real short-lived child. The seam
     * receives the {@see self::treeArgv()} list and answers bool — "did the
     * tree die" — which is exactly what the production runner derives from
     * taskkill's exit status.
     */
    private static ?\Closure $runner = null;

    /**
     * Test-only override of {@see CHILD_BOUND_SECONDS} so the wedge leg can
     * EXEC on a tick scale instead of costing the suite seconds; null keeps
     * the declared bound.
     */
    private static ?float $boundOverride = null;

    /**
     * Point the child bound at $seconds (tests), or at null to restore the
     * declared {@see CHILD_BOUND_SECONDS}.
     */
    public static function setChildBoundForTesting(?float $seconds): void
    {
        self::$boundOverride = $seconds;
    }

    /**
     * The bound the default runner enforces — the declared constant unless
     * a test has re-set it.
     */
    public static function childBoundSeconds(): float
    {
        return self::$boundOverride ?? self::CHILD_BOUND_SECONDS;
    }

    /**
     * The argv that kills $pid and every descendant: taskkill's own tree
     * walk, force-close, one pid. Built as a LIST so the spawn never passes
     * through a shell — the pid is an argument, never part of a command
     * string that would need quoting at all.
     *
     * @return non-empty-list<string>
     */
    public static function treeArgv(int $pid): array
    {
        return ['taskkill', '/T', '/F', '/PID', (string) $pid];
    }

    /**
     * Route the kill through $runner (tests: record argv, answer bool), or
     * pass null to run the real child again.
     */
    public static function setRunnerForTesting(?\Closure $runner): void
    {
        self::$runner = $runner;
    }

    /**
     * Kill $pid's whole tree — the Windows twin of the group kill
     * {@see ProcessContainment::terminate()} performs on POSIX.
     *
     * Refuses $pid <= 0 WITHOUT touching the runner: a kill(0) means
     * "every process the caller may signal" on POSIX and something
     * similarly vast is not what a teardown ever meant here.
     *
     * @return bool true when the tree kill reported success
     */
    public static function tree(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $runner = self::$runner;
        if ($runner !== null) {
            return (bool) $runner(self::treeArgv($pid));
        }

        return self::runTreeKill(self::treeArgv($pid));
    }

    /**
     * The default runner: spawn argv through spawnSpec (array form, no
     * shell), drain both output pipes with a deadline, and answer from the
     * exit status. `proc_close()` runs in THIS function — the short-lived
     * classification the child-lifetime guards look for — on every leg,
     * including the terminated one, so no path leaves the killer child
     * unreaped behind it.
     *
     * @param non-empty-list<string> $argv
     */
    private static function runTreeKill(array $argv): bool
    {
        $spawn = ProcessContainment::spawnSpec($argv);
        $process = @\proc_open($spawn, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!\is_resource($process)) {
            return false;
        }

        // Nothing reads stdin; close it at once so a child that expects one
        // sees EOF rather than a pipe held open by the teardown.
        \fclose($pipes[0]);

        $deadline = \hrtime(true) + (int) (self::childBoundSeconds() * 1_000_000_000);
        $open = [$pipes[1], $pipes[2]];
        while ($open !== []) {
            $read = [];
            foreach ($open as $index => $stream) {
                if (\feof($stream)) {
                    \fclose($stream);
                    unset($open[$index]);

                    continue;
                }
                $read[] = $stream;
            }
            if ($open === []) {
                break;
            }
            if (\hrtime(true) >= $deadline) {
                // Wedged killer: stop it, drain-close, and let the exit
                // status below report the failure honestly.
                \proc_terminate($process, 9);
                break;
            }
            $write = null;
            $except = null;
            if (@\stream_select($read, $write, $except, 0, 100_000) > 0) {
                foreach ($read as $stream) {
                    // Reading to EOF keeps the child unblocked on its
                    // (small) output; the bytes themselves are nobody's
                    // business — taskkill's verdict is its exit status.
                    \fread($stream, 8192);
                }
            }
        }

        foreach ($open as $stream) {
            \fclose($stream);
        }

        return \proc_close($process) === 0;
    }
}
