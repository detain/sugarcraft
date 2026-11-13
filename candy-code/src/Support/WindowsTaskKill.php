<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Async\ChildPump;
use SugarCraft\Async\Clock;

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

        $run = self::$runner ?? static fn (array $argv): bool => self::runTreeKill($argv);

        // Git-for-Windows bash first, when the host has one. Its fork
        // emulation leaves backgrounded commands with no living Windows
        // parent, so taskkill's walk cannot reach them. Measured on Windows
        // 11: `sleep 40 & sleep 40 & wait` under a 2 s Bash timeout left both
        // sleep.exe running, their parents being fork stubs that had already
        // exited. The MSYS group kill reaches them; it also runs the taskkill
        // walk itself, first and while bash is still alive, for native
        // descendants. A pid that is no MSYS process falls through to the
        // plain walk below.
        // The real runtime, not the injectable family: MSYS and its /proc
        // winpid map exist only on an actual Windows host.
        $bash = self::$msysBashOverride ?? (\PHP_OS_FAMILY === 'Windows' ? WindowsShell::bash() : '');
        if ($bash !== '' && (bool) $run(self::msysGroupKillArgv($pid, $bash))) {
            return true;
        }

        return (bool) $run(self::treeArgv($pid));
    }

    /**
     * Run under Git-for-Windows bash with $1 = the Windows pid. Finds the MSYS
     * process carrying that winpid and records its process group BEFORE
     * anything dies, because the /proc entry goes with the process. It then
     * runs taskkill's tree walk (natives still linked to a living parent) and
     * SIGKILLs the MSYS group (members whose Windows parent is a dead fork
     * stub). Exit 0 means the group was found and signalled; exit 1 means no
     * MSYS process has that pid. Only shell builtins sit on the lookup path,
     * so scanning /proc spawns nothing per entry.
     */
    private const MSYS_GROUP_KILL_SCRIPT = 'w=$1; for d in /proc/[0-9]*; do '
        . 'read -r x < "$d/winpid" 2>/dev/null || continue; '
        . '[ "$x" = "$w" ] || continue; '
        . 'read -r g < "$d/pgid" 2>/dev/null || exit 1; '
        . 'MSYS_NO_PATHCONV=1 taskkill /T /F /PID "$w" >/dev/null 2>&1; '
        . 'kill -9 -- "-$g" 2>/dev/null; '
        . 'exit 0; done; exit 1';

    /**
     * Test-only: the bash the MSYS step runs under, '' to skip the step, or
     * null for the host's own answer (Windows + a located Git bash).
     */
    private static ?string $msysBashOverride = null;

    /**
     * Pin the MSYS step's bash ('' disables it) or restore the host answer
     * with null, so routing pins mean the same thing on every host.
     */
    public static function setMsysBashForTesting(?string $bash): void
    {
        self::$msysBashOverride = $bash;
    }

    /**
     * The argv for the MSYS group kill, run under $bash with the pid as $1.
     * See {@see MSYS_GROUP_KILL_SCRIPT}.
     *
     * @return non-empty-list<string>
     */
    public static function msysGroupKillArgv(int $pid, string $bash): array
    {
        return [$bash, '-c', self::MSYS_GROUP_KILL_SCRIPT, 'msys-group-kill', (string) $pid];
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

        // S-L02 (R4): drain-and-discard to EOF under the child bound — taskkill's
        // verdict is its exit status, its bytes are nobody's business. A wedged
        // killer (deadline passed with a pipe still open) is stopped; the exit
        // status below then reports the failure honestly.
        $drain = ChildPump::drain(
            [1 => $pipes[1], 2 => $pipes[2]],
            Clock::monotonicSeconds() + self::childBoundSeconds(),
            0,
            discard: [1, 2],
        );
        if (!$drain->closed()) {
            \proc_terminate($process, 9);
        }

        foreach ([$pipes[1], $pipes[2]] as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }

        return \proc_close($process) === 0;
    }
}
