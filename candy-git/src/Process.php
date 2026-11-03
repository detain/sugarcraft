<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * Runs an external command with a wall-clock timeout.
 *
 * The synchronous {@see Git} driver shells out on the UI thread, so a git
 * invocation that wedges — a credential or pager prompt, an index lock held by
 * another process, an unreachable remote — would otherwise freeze the whole TUI
 * indefinitely. Draining stdout/stderr through `stream_select` with a deadline
 * lets us `proc_terminate` the child once the budget is spent instead of
 * blocking on `stream_get_contents` forever.
 *
 * stdin is pumped through the SAME select loop rather than one blocking
 * `fwrite`: a child that never reads (an early `git apply` refusal, say) fills
 * the 64KiB pipe and a blocking write would wedge the UI thread OUTSIDE the
 * timeout budget entirely — the loop keeps the deadline authoritative over the
 * write phase too.
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

    private const CAPTURE_NOTICE = '[candy-git: output truncated at 4 MiB]';

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
     */
    public static function childEnv(): array
    {
        return array_merge(getenv() ?: [], [
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
     *
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool}
     */
    public static function run(array $argv, ?string $stdin = null, float $timeout = 30.0): array
    {
        // With no stdin payload the child still must NOT inherit our stdin:
        // the TUI leaves the terminal in raw mode, so an inherited fd 0 lets a
        // child (git's credential or editor machinery, say) read — and steal
        // — the user's keystrokes. /dev/null gives it an immediate EOF
        // (house shape: candy-code BackgroundSupervisor uses the same spec).
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($stdin !== null) {
            $spec[0] = ['pipe', 'r'];
        }
        $proc = proc_open($argv, $spec, $pipes, null, self::childEnv());
        if (!is_resource($proc)) {
            throw new \RuntimeException(Lang::t('git.spawn_failed'));
        }

        // EPIPE is expected when the child exits without draining us — @fwrite
        // surfaces it as a silent write failure and the child's own exit code
        // speaks for the outcome.
        $stdinHandle = null;
        $stdinOffset = 0;
        if ($stdin !== null) {
            stream_set_blocking($pipes[0], false);
            if ($stdin === '') {
                fclose($pipes[0]);
            } else {
                $stdinHandle = $pipes[0];
            }
        }

        $open = [];
        foreach ([1, 2] as $i) {
            stream_set_blocking($pipes[$i], false);
            $open[$i] = $pipes[$i];
        }

        $stdout      = '';
        $stderr      = '';
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $timedOut    = false;
        $abandoned   = false; // loop stopped while the child may still be live (timeout or EINTR)
        $deadline    = $timeout > 0 ? microtime(true) + $timeout : null;

        while ($open !== [] || $stdinHandle !== null) {
            $wait = null;
            if ($deadline !== null) {
                $wait = $deadline - microtime(true);
                if ($wait <= 0) {
                    $timedOut  = true;
                    $abandoned = true;
                    break;
                }
            }

            $read   = array_values($open);
            $write  = $stdinHandle !== null ? [$stdinHandle] : [];
            $except = null;
            if ($wait === null) {
                $ready = @stream_select($read, $write, $except, null);
            } else {
                $sec   = (int) $wait;
                $usec  = (int) (($wait - $sec) * 1_000_000);
                $ready = @stream_select($read, $write, $except, $sec, $usec);
            }

            // false = interrupted by a signal; stop draining and tear the child
            // down through the same bounded ladder a timeout gets — an ordinary
            // SIGWINCH (terminal resize) must never strand a live git child on
            // a proc_close that would block until the child exits by itself.
            if ($ready === false) {
                $abandoned = true;
                break;
            }
            if ($ready === 0) {
                $timedOut  = true;
                $abandoned = true;
                break;
            }

            foreach ($read as $stream) {
                $key   = array_search($stream, $open, true);
                $chunk = fread($stream, 8192);
                if ($chunk === '' || $chunk === false) {
                    if ($key !== false) {
                        fclose($open[$key]);
                        unset($open[$key]);
                    }
                    continue;
                }
                // A27: append up to the cap, discard the rest (keep draining
                // so the child finishes naturally), and remember that
                // truncation happened so the caller sees a marker.
                if ($key === 1) {
                    if (strlen($stdout) < self::MAX_CAPTURE) {
                        $stdout .= substr($chunk, 0, self::MAX_CAPTURE - strlen($stdout));
                    }
                    if (strlen($stdout) >= self::MAX_CAPTURE) {
                        $stdoutTruncated = true;
                    }
                } else {
                    if (strlen($stderr) < self::MAX_CAPTURE) {
                        $stderr .= substr($chunk, 0, self::MAX_CAPTURE - strlen($stderr));
                    }
                    if (strlen($stderr) >= self::MAX_CAPTURE) {
                        $stderrTruncated = true;
                    }
                }
            }

            if ($stdinHandle !== null && in_array($stdinHandle, $write, true)) {
                $n = @fwrite($stdinHandle, substr($stdin, $stdinOffset, 65536));
                if ($n === false || $n === 0) {
                    fclose($stdinHandle);
                    $stdinHandle = null;
                } else {
                    $stdinOffset += $n;
                    if ($stdinOffset >= strlen($stdin)) {
                        fclose($stdinHandle);
                        $stdinHandle = null;
                    }
                }
            }
        }

        if ($abandoned) {
            self::terminateBounded($proc);
        }

        if ($stdinHandle !== null) {
            fclose($stdinHandle);
        }

        foreach ($open as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $exit = proc_close($proc);

        if ($stdoutTruncated) {
            $stdout .= "\n" . self::CAPTURE_NOTICE;
        }
        if ($stderrTruncated) {
            $stderr .= "\n" . self::CAPTURE_NOTICE;
        }

        return [
            'stdout'   => $stdout,
            'stderr'   => $stderr,
            'exit'     => $exit,
            'timedOut' => $timedOut,
        ];
    }

    /**
     * Bounded SIGTERM -> grace -> SIGKILL ladder for a child we stopped waiting
     * on. SIGTERM first gives git (and any hook it spawned) a chance to clean
     * up lock files it took; only a child that ignores it — deliberately or
     * because it is wedged in uninterruptible I/O — is SIGKILLed after the
     * grace window. Polls instead of one-shotting proc_get_status: the exit
     * ordering around pipe-EOF makes any single liveness read racy (measured
     * in this fleet: a one-shot can still say "running" seconds after death),
     * and the loop is bounded by the grace deadline either way.
     *
     * @param resource $proc
     */
    private static function terminateBounded($proc): void
    {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            return; // already dead — proc_close will reap without blocking
        }

        proc_terminate($proc, 15);

        $grace = microtime(true) + self::TERMINATE_GRACE_SECONDS;
        while (microtime(true) < $grace) {
            usleep(10_000);
            if (!proc_get_status($proc)['running']) {
                return;
            }
        }

        proc_terminate($proc, 9);
    }
}
