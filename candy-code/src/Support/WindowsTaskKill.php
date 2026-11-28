<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Async\ChildPump;
use SugarCraft\Async\WindowsPipes;
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
 * default runner bounds each killer child so a wedged one can never extend
 * a teardown past its declared window: the taskkill child at
 * {@see CHILD_BOUND_SECONDS}, the MSYS group-kill bash (when the host has
 * Git bash) at {@see msysStepBoundSeconds()}. A synchronous tree() can
 * therefore wait up to the sum of the two when the MSYS step fails and the
 * plain walk follows. These are walls the killer children get, not a
 * blanket timeout on anything user-facing.
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
     * Bound on the MSYS step's PowerShell child query, enforced inside the
     * script by MSYS timeout(1) because PHP's own bound cannot reach that
     * grandchild on Windows (see {@see MSYS_GROUP_KILL_SCRIPT}). It is a cold
     * powershell.exe and WMI start, so it gets the same allowance as a whole
     * killer child; the bash it runs under is bounded by
     * {@see msysStepBoundSeconds()}, which adds this bound on top of
     * {@see CHILD_BOUND_SECONDS} so bash startup, `ps -W`, the query, the
     * taskkill and the group kill all fit before the bash is judged wedged.
     */
    public const CHILD_QUERY_BOUND_SECONDS = 5;

    /**
     * Test-only argv runner; null runs the real short-lived child. The seam
     * is called as `(array $argv, float $bound)` and answers bool — "did the
     * tree die" — which is exactly what the production runner derives from
     * the child's exit status. When the MSYS step is active (a Git bash on a
     * Windows host, or {@see setMsysBashForTesting()}), the first call
     * carries {@see self::msysGroupKillArgv()} with
     * {@see msysStepBoundSeconds()}, and {@see self::treeArgv()} with
     * {@see childBoundSeconds()} follows only when that call answered false;
     * with the step off, the only call is the treeArgv() one.
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
     * The bound the default runner enforces on the MSYS group-kill bash: the
     * child bound for the script's own work plus
     * {@see CHILD_QUERY_BOUND_SECONDS} for the PowerShell query it may wait
     * on. A bound of the child bound alone let a slow bash start plus a
     * query run to its own timeout end the bash before its taskkill and
     * group-kill lines, which silently fell back to the plain walk.
     */
    public static function msysStepBoundSeconds(): float
    {
        return self::childBoundSeconds() + self::CHILD_QUERY_BOUND_SECONDS;
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

        $run = self::$runner ?? static fn (array $argv, float $bound): bool => self::runTreeKill($argv, $bound);

        // Git-for-Windows bash first, when the host has one. Its fork
        // emulation leaves backgrounded commands with no living Windows
        // parent, so taskkill's walk cannot reach them. Measured on Windows
        // 11: `sleep 40 & sleep 40 & wait` under a 2 s Bash timeout left both
        // sleep.exe running, their parents being fork stubs that had already
        // exited. The MSYS group kill reaches them; it also runs the taskkill
        // walk itself, first and while bash is still alive, for native
        // descendants. The pid may be Git's native bin\bash.exe launcher, so
        // the step also looks one Windows level down for the MSYS bash it
        // started. A pid with no MSYS process at or under it falls through
        // to the plain walk below.
        // The real runtime, not the injectable family: MSYS and its /proc
        // winpid map exist only on an actual Windows host.
        $bash = self::$msysBashOverride ?? (\PHP_OS_FAMILY === 'Windows' ? WindowsShell::bash() : '');
        if ($bash !== '' && (bool) $run(self::msysGroupKillArgv($pid, $bash), self::msysStepBoundSeconds())) {
            return true;
        }

        return (bool) $run(self::treeArgv($pid), self::childBoundSeconds());
    }

    /**
     * Run under Git-for-Windows bash with $1 = the Windows pid. Finds the MSYS
     * process carrying that winpid and records its process group BEFORE
     * anything dies, because the /proc entry goes with the process. It then
     * runs taskkill's tree walk (natives still linked to a living parent) and
     * SIGKILLs the MSYS group (members whose Windows parent is a dead fork
     * stub). Exit 0 means the group was found and signalled; exit 1 means
     * neither the pid nor any Windows child of it is an MSYS process. Only
     * shell builtins sit on the /proc lookup, so the scan spawns nothing per
     * entry.
     *
     * The child step exists because the pid is often not the MSYS bash. The
     * bash.exe a plain Windows PATH finds is Git for Windows' `bin\bash.exe`,
     * a native launcher that starts `usr\bin\bash.exe` as its child and
     * waits; the launcher has no /proc entry, so a pid-only lookup missed,
     * the plain taskkill walk ran instead, and the backgrounded sleeps of the
     * windows-smoke timeout leg survived (CI run 45110506050). The 2026-11-13
     * measurement passed because that host's PATH found `usr\bin\bash.exe`
     * directly. MSYS /proc carries no Windows parent pid, so the launcher's
     * children are asked of Windows (PowerShell CIM query) once.
     *
     * That query is a cold powershell.exe and WMI start, and the
     * killTreeAsync() Windows arm waits on this script synchronously, so it
     * runs only when the pid is a native `bash.exe`: MSYS `ps -W` lists every
     * Windows process with its image path, and a native kill whose image is
     * anything else (an MCP server, a php engine child) exits 1 after that
     * one cheap MSYS spawn, never starting powershell.exe. The pid is matched
     * in the WINPID column only (the fourth number, after an optional status
     * letter), so a non-bash pid that appears as the PPID of a native
     * bash.exe child does not start the query either. The query also
     * runs under `timeout -s KILL` at {@see CHILD_QUERY_BOUND_SECONDS}: when
     * PHP's bound fires, proc_terminate() reaches only this bash on Windows,
     * never its powershell.exe grandchild, so the grandchild carries its own
     * bound, enforced by MSYS timeout(1), which outlives a terminated bash.
     * PHP bounds this bash at {@see msysStepBoundSeconds()}, the query bound
     * plus {@see CHILD_BOUND_SECONDS}, so a query that runs to its timeout
     * still leaves the script time to reach its taskkill and group kill.
     */
    private const MSYS_GROUP_KILL_SCRIPT = 'w=$1; g=; '
        . 'lookup() { for d in /proc/[0-9]*; do '
        . 'read -r x < "$d/winpid" 2>/dev/null || continue; '
        . '[ "$x" = "$1" ] || continue; '
        . 'read -r g < "$d/pgid" 2>/dev/null; return; done; }; '
        . 'lookup "$w"; '
        . 'if [ -z "$g" ]; then n=; '
        . 'while IFS= read -r l; do l=${l%$\'\r\'}; '
        . 'read -r -a f <<< "$l"; '
        . 'case ${f[0]} in *[!0-9]*) f=("${f[@]:1}");; esac; '
        . '[ "${f[3]}" = "$w" ] || continue; '
        . 'case $l in *\'\\bash.exe\') n=1;; esac; break; '
        . 'done <<< "$(ps -W 2>/dev/null)"; '
        . 'if [ -n "$n" ]; then '
        . 'for c in $(MSYS_NO_PATHCONV=1 timeout -s KILL ' . self::CHILD_QUERY_BOUND_SECONDS . ' '
        . 'powershell.exe -NoLogo -NoProfile -NonInteractive -Command '
        . '"Get-CimInstance Win32_Process -Filter \'ParentProcessId=$w\' | ForEach-Object ProcessId" '
        . '</dev/null 2>/dev/null); do '
        . 'lookup "${c%$\'\r\'}"; [ -n "$g" ] && break; done; fi; fi; '
        . '[ -n "$g" ] || exit 1; '
        . 'MSYS_NO_PATHCONV=1 taskkill /T /F /PID "$w" >/dev/null 2>&1; '
        . 'kill -9 -- "-$g" 2>/dev/null; '
        . 'exit 0';

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
     * @param ?float $bound seconds the child may live before it is
     *     terminated; null is {@see childBoundSeconds()}
     */
    private static function runTreeKill(array $argv, ?float $bound = null): bool
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
        $drain = ChildPump::new([1 => $pipes[1], 2 => $pipes[2]])
            ->withDeadline(Clock::monotonicSeconds() + ($bound ?? self::childBoundSeconds()))
            ->withCap(0)
            ->withDiscard([1, 2])
            ->withRunning(WindowsPipes::runningProbe($process))
            ->pump();
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
