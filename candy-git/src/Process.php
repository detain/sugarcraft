<?php

declare(strict_types=1);

namespace SugarCraft\Git;

use SugarCraft\Async\ChildPump;
use SugarCraft\Async\Clock;
use SugarCraft\Async\DrainResult;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

/**
 * Runs an external command with a wall-clock timeout.
 *
 * The synchronous {@see Git} driver shells out on the UI thread, so a git
 * invocation that wedges — a credential or pager prompt, an index lock held by
 * another process, an unreachable remote — would otherwise freeze the whole TUI
 * indefinitely. The POSIX arm drains stdout/stderr through sugar-async's
 * {@see ChildPump} with a deadline, and stops a child that outlives it with the
 * sugar-core {@see BoundedShutdown} ladder instead of blocking on
 * `stream_get_contents` forever.
 *
 * stdin is pumped through the SAME select loop rather than one blocking
 * `fwrite`: a child that never reads (an early `git apply` refusal, say) fills
 * the 64KiB pipe and a blocking write would wedge the UI thread OUTSIDE the
 * timeout budget entirely — the pump keeps the deadline authoritative over the
 * write phase too.
 *
 * The Windows arm keeps its temp-file ladder here: stdin there is a file the
 * child reads, which the pipe-based pump cannot take, so it stays until the
 * Windows runtime check that gates the P1-5 consumers.
 *
 * Mirrors jesseduffield/lazygit pkg/utils exec command-runner — capture + bounded execution of git. */
final class Process
{
    /** How long a SIGTERMed child gets to exit before we escalate to SIGKILL. */
    public const TERMINATE_GRACE_SECONDS = 2.0;

    /**
     * A27: hard cap on bytes of child output accumulated per stream. A
     * runaway (or pathological) git invocation must not be able to balloon
     * the app's memory before the deadline fires. The head bytes are kept —
     * diff headers and error lines come first — and one visible notice is
     * appended when a stream overflowed the cap.
     */
    public const MAX_CAPTURE = 4 * 1024 * 1024;

    /**
     * Environment variables that redirect git at another repository or
     * object store (candy-git-041). Stripped from every child; -C alone
     * does not outvote them, and hooks inherit them by design.
     */
    public const GIT_REDIRECT_VARS = [
        'GIT_DIR',
        'GIT_WORK_TREE',
        'GIT_INDEX_FILE',
        'GIT_COMMON_DIR',
        'GIT_OBJECT_DIRECTORY',
        'GIT_ALTERNATE_OBJECT_DIRECTORIES',
        'GIT_NAMESPACE',
        'GIT_PREFIX',
    ];

    public const CAPTURE_NOTICE = '[candy-git: output truncated at 4 MiB]';

    /**
     * candy-git-006: an EINTR (stream_select() returning false) is almost
     * always an ordinary SIGWINCH — the child is healthy and the deadline is
     * not spent, so we keep waiting against the SAME absolute deadline. Only a
     * signal storm that preempts the drain this many times in a row gives up
     * and falls through the kill ladder with an honest `interrupted` marker;
     * the cap keeps that pathological lane bounded instead of spinning forever.
     */
    private const MAX_CONSECUTIVE_EINTRS = 32;

    /**
     * A non-positive timeout keeps its historical meaning, "no deadline". The
     * pump always carries a deadline, so that lane gets one a year out: far
     * past any child this runs, and still a bounded number for the select wait.
     */
    private const UNBOUNDED_SECONDS = 31_536_000.0;

    /**
     * Environment handed to every child: the parent's environment plus
     * git-specific overrides. Each override closes a real way a repo- or
     * user-controlled setting could block or hijack a synchronous git call
     * made from inside the TUI:
     *
     * - GIT_TERMINAL_PROMPT=0  — never prompt for credentials on our tty
     *   (a prompt would wedge the UI until the timeout kills the call).
     * - GIT_PAGER=cat          — never pipe output into an interactive pager.
     * - GIT_EDITOR / GIT_SEQUENCE_EDITOR=true — merge --continue, stash apply
     *   conflicts and rebase --continue must not claim the terminal; `true`
     *   accepts whatever message git prepared.
     * - LC_ALL / LANG=C        — the porcelain parsers in {@see Git} expect
     *   git's canonical English byte shapes; on a localized machine they are
     *   not negotiable.
     *
     * candy-git-041: the parent's GIT_ REDIRECT family is stripped before the
     * overrides land. `git -C <cwd>` does NOT outvote GIT_DIR/GIT_INDEX_FILE/
     * GIT_WORK_TREE in the environment — and git itself exports those into
     * hook children. Launched from a hook or wrapper, every operation (every
     * stage, discard, drop, reset) would silently act on ANOTHER repository
     * while the TUI browsed this one. GIT_CONFIG_GLOBAL/GIT_CONFIG_SYSTEM
     * deliberately survive the strip: they name the operator's own config
     * files, a choice the operator made in their shell, not a redirect a
     * hook inherited.
     *
     * @param array<string,string>|null $inherit parent environment to derive
     *                                           from; null reads the real one
     */
    public static function childEnv(?array $inherit = null): array
    {
        $env = $inherit ?? (getenv() ?: []);
        foreach (self::GIT_REDIRECT_VARS as $name) {
            unset($env[$name]);
        }

        return array_merge($env, [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_PAGER' => 'cat',
            'GIT_EDITOR' => 'true',
            'GIT_SEQUENCE_EDITOR' => 'true',
            'LC_ALL' => 'C',
            'LANG' => 'C',
        ]);
    }

    /**
     * @param list<string> $argv    Command + args passed straight to proc_open (no shell).
     * @param string|null  $stdin   Pumped incrementally into the child's non-blocking stdin, closed when fully
     *                              written (or on EPIPE). Null attaches /dev/null: the child sees an
     *                              immediate EOF and can never read (or block on) our terminal.
     * @param float        $timeout Seconds before the child is killed; <= 0 disables the timeout.
     * @param Platform|null $platform OS seam; null = the host. Windows takes the polled temp-file ladder.
     *
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool, interrupted: bool, truncated: bool}
     */
    public static function run(array $argv, ?string $stdin = null, float $timeout = 30.0, ?Platform $platform = null): array
    {
        $platform ??= Platform::new();
        if ($platform->isWindows()) {
            return self::runPolled($argv, $stdin, $timeout, $platform);
        }

        // With no stdin payload the child still must NOT inherit our stdin:
        // the TUI leaves the terminal in raw mode, so an inherited fd 0 lets a
        // child (git's credential or editor machinery, say) read — and steal
        // — the user's keystrokes. /dev/null gives it an immediate EOF
        // (house shape: candy-code BackgroundSupervisor uses the same spec).
        $spec = [0 => ['file', $platform->nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($stdin !== null) {
            $spec[0] = ['pipe', 'r'];
        }
        $proc = @proc_open($argv, $spec, $pipes, null, self::childEnv());
        if (!is_resource($proc)) {
            throw self::spawnFailure();
        }

        $clock    = Clock::monotonicSeconds(...);
        $deadline = $clock() + ($timeout > 0 ? $timeout : self::UNBOUNDED_SECONDS);

        $pump = ChildPump::new([1 => $pipes[1], 2 => $pipes[2]])
            ->withDeadline($deadline)
            ->withCap(self::MAX_CAPTURE)
            ->withClock($clock)
            ->withSelectRetries(self::MAX_CONSECUTIVE_EINTRS);
        if ($stdin !== null) {
            // An empty payload closes the pipe at once, so the child sees EOF.
            $pump = $pump->withStdin($pipes[0], $stdin);
        }

        try {
            $r = $pump->pump();
        } catch (\Throwable $e) {
            self::closeAll($pipes);
            BoundedShutdown::terminateBounded($proc, self::TERMINATE_GRACE_SECONDS);

            throw $e;
        }
        // Pipes go before any reap: the reap waits on the exit, and our ends
        // are ours to close first.
        self::closeAll($pipes);

        // candy-git-007: pipe EOF is not child death. A child that redirects its
        // own descriptors away closes our capture pipes at once while the process
        // keeps living, so after a clean EOF the reap spends whatever of the
        // absolute deadline remains. Any other ending has no budget left to spend.
        // The reap waits without signalling; a child still running then goes
        // through the TERM -> grace -> KILL ladder. terminateBounded() is
        // idempotent on a handle the first call already reaped, so it runs
        // unconditionally: every path out of here reaps the child.
        $budget  = $r->reason === DrainResult::CLOSED ? max(0.0, $deadline - $clock()) : 0.0;
        $reaped  = BoundedShutdown::reapIfExited($proc, $budget);
        $stopped = BoundedShutdown::terminateBounded($proc, self::TERMINATE_GRACE_SECONDS);
        $exit    = $reaped ?? $stopped ?? -1;

        $timedOut    = $r->reason === DrainResult::EXPIRED
            || ($r->reason === DrainResult::CLOSED && $reaped === null);
        $interrupted = $r->reason === DrainResult::RETRY_BUDGET_GONE;

        // Each overflowing stream gets its own notice, so a caller reading
        // stderr sees the marker on stderr; the flag says the same in shape.
        $stdout = $r->buffer(1);
        $stderr = $r->buffer(2);
        if ($r->droppedBytes(1) > 0) {
            $stdout .= "\n" . self::CAPTURE_NOTICE;
        }
        if ($r->droppedBytes(2) > 0) {
            $stderr .= "\n" . self::CAPTURE_NOTICE;
        }

        return [
            'stdout'      => $stdout,
            'stderr'      => $stderr,
            'exit'        => $exit,
            'timedOut'    => $timedOut,
            'interrupted' => $interrupted,
            // candy-git-040: callers must be able to tell a complete answer
            // from a head-kept one WITHOUT string-matching the notice.
            'truncated'   => $r->truncated(),
        ];
    }

    /**
     * Windows stop: there is no SIGTERM there, so the whole tree is killed
     * through the Platform seam (git spawns helpers and hooks), then a bounded
     * liveness wait, then one last proc_terminate.
     *
     * @param resource $proc
     */
    private static function terminateWindows($proc, Platform $platform): void
    {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            return; // already dead — proc_close will reap without blocking
        }

        $platform->killTree($status['pid']);
        $grace = microtime(true) + self::TERMINATE_GRACE_SECONDS;
        while (microtime(true) < $grace) {
            if (!proc_get_status($proc)['running']) {
                return;
            }
            usleep(10_000);
        }
        proc_terminate($proc);
    }

    /**
     * Windows ladder: `stream_select` cannot poll proc_open pipes there, so the
     * child writes to temp files and the parent polls `proc_get_status` with a
     * short sleep. Same contract and result shape as the pipe path: bounded by
     * the deadline, output head-capped at MAX_CAPTURE with one notice, stdin
     * delivered as a file (the child sees EOF after the payload).
     *
     * @param list<string> $argv
     *
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool, interrupted: bool, truncated: bool}
     */
    private static function runPolled(array $argv, ?string $stdin, float $timeout, Platform $platform): array
    {
        $dir = sys_get_temp_dir();
        $outFile = tempnam($dir, 'cgo');
        $errFile = tempnam($dir, 'cge');
        $inFile = $stdin !== null ? tempnam($dir, 'cgi') : false;
        $cleanup = static function () use ($outFile, $errFile, $inFile): void {
            foreach ([$outFile, $errFile, $inFile] as $f) {
                if (is_string($f)) {
                    @unlink($f);
                }
            }
        };
        if ($outFile === false || $errFile === false || ($stdin !== null && $inFile === false)) {
            $cleanup();
            throw self::spawnFailure('cannot create capture files in ' . $dir);
        }
        if ($stdin !== null) {
            file_put_contents((string) $inFile, $stdin);
        }

        $spec = [
            0 => ['file', is_string($inFile) ? $inFile : $platform->nullDevice(), 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ];
        // The seam answers the difference (Platform docblock): a bare argv[0]
        // must reach proc_open the way cmd.exe would see it, or a git.cmd shim
        // is a CreateProcess error 2 on Windows. Linux-injected ladders resolve
        // nothing (their ';' PATH split matches no candidate) - argv unchanged.
        $argv = $platform->resolveSpawnProgram($argv);
        $proc = @proc_open($argv, $spec, $pipes, null, self::childEnv());
        if (!is_resource($proc)) {
            $cleanup();
            throw self::spawnFailure();
        }

        $timedOut = false;
        $exit = null;
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                // proc_get_status reports the real exit code exactly once.
                $exit = $status['exitcode'];
                break;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                $timedOut = true;
                self::terminateWindows($proc, $platform);
                break;
            }
            usleep(10_000);
        }
        $closed = proc_close($proc);
        $exit = $exit !== null && $exit !== -1 ? $exit : $closed;

        $capHit = false;
        $read   = static function (string $file) use (&$capHit): string {
            $h = @fopen($file, 'rb');
            if ($h === false) {
                return '';
            }
            $data = (string) stream_get_contents($h, self::MAX_CAPTURE + 1);
            fclose($h);
            if (strlen($data) > self::MAX_CAPTURE) {
                $data  = substr($data, 0, self::MAX_CAPTURE) . "\n" . self::CAPTURE_NOTICE;
                $capHit = true;
            }

            return $data;
        };
        $result = [
            'stdout'      => $read($outFile),
            'stderr'      => $read($errFile),
            'exit'        => $exit,
            'timedOut'    => $timedOut,
            // The polled ladder has no select() to interrupt: a signal cannot
            // strand this lane, only the deadline can. The key is present for
            // shape parity with the POSIX return.
            'interrupted' => false,
            'truncated'   => $capHit,
        ];
        $cleanup();

        return $result;
    }

    /** @param array<int, resource> $pipes */
    private static function closeAll(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /** The spawn failure carries the OS reason so a launch problem is not mistaken for "git is missing". */
    private static function spawnFailure(?string $reason = null): \RuntimeException
    {
        $reason ??= trim((string) (error_get_last()['message'] ?? ''));

        return new \RuntimeException(Lang::t('git.spawn_failed', ['reason' => $reason !== '' ? $reason : 'unknown']));
    }
}
