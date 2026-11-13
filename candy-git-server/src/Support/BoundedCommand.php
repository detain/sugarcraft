<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Support;

/**
 * Synchronous argv-only subprocess runner with a hard deadline.
 *
 * Every short-lived git/ssh-keygen call the lib makes used to run through
 * \exec() with a hand-interpolated shell string: no timeout (a wedged git
 * froze the whole tick), stderr merged into stdout by `2>&1` (parsers saw
 * progress noise), and shell metacharacters only survivable via
 * escapeshellarg discipline that any future caller could forget. This
 * runner takes the argument VECTOR directly — no shell is involved, so
 * quoting is not a correctness concern — drains both pipes under
 * stream_select against a wall-clock deadline, and escalates
 * TERM → KILL → bounded reaper wait in a finally so the child never
 * outlives the call (the same reap contract the long-lived git services
 * follow in Git\StdioPipe and Git\StatelessRpc).
 *
 * Output is stdout-only on success; stderr is appended on failure so
 * error messages keep the context they were quoted from before.
 *
 * S16 (findings 030, 031): the pump now carries the byte cap its
 * long-lived siblings already had — stdout keeps its HEAD (a data read
 * that overflowed is flagged so callers refuse the partial bytes rather
 * than serve a silent prefix), stderr keeps its TAIL (a git fatal line
 * arrives last, and the decisive text must survive a flood of progress
 * noise). runRaw() skips the line-split entirely: callers who treat
 * stdout as BYTES (Repo::readFile serving blob content) get them
 * exactly, CRLF included, no round-trip through the line vocabulary.
 *
 * Kill-tree boundary (S15): the TERM→KILL escalation signals THIS direct
 * child only — a git that spawned helpers is not signalled transitively.
 * Safe here because argv-only spawning never puts a shell between us and
 * the process being ended.
 */
final class BoundedCommand
{
    /** Generous for metadata reads; fetch overrides with its own budget. */
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    /** Per-stream byte ceiling (S16, finding 030): no git child, however
     *  chatty, can grow a synchronous caller's memory without bound. */
    public const DEFAULT_MAX_OUTPUT_BYTES = 8 * 1024 * 1024;

    private const TICK_MICROSECONDS = 50_000;
    private const CHUNK_BYTES = 65536;
    private const TERM_GRACE_SECONDS = 1.0;
    private const KILL_WAIT_SECONDS = 2.0;

    /**
     * S15 (finding 024): children killed but not reaped within
     * KILL_WAIT_SECONDS (uninterruptible sleep, dying slowly). The old
     * shape fell through to proc_close() right here — which BLOCKS until
     * the child is gone — so the "hard deadline" runner could hold the
     * caller for the whole life of a stuck git. Parked handles go into
     * this static registry instead and are reaped with WNOHANG semantics
     * by the NEXT call (the pattern candy-top's AsyncCommand established:
     * no blocking wait in a bounded runner; a later tick collects).
     *
     * @var list<resource>
     */
    private static array $unreaped = [];

    /**
     * Collect any parked children that have exited since. Returns the
     * number reaped; public so a test (or a shutdown path) can force a
     * sweep without fabricating another spawn.
     */
    public static function reapPending(): int
    {
        $reaped = 0;
        foreach (self::$unreaped as $index => $proc) {
            $status = @\proc_get_status($proc);
            if ($status !== false && $status['running']) {
                continue; // still alive; poll again next call
            }
            @\proc_close($proc); // exited — collect the status, drop the handle
            unset(self::$unreaped[$index]);
            $reaped++;
        }
        self::$unreaped = \array_values(self::$unreaped);

        return $reaped;
    }

    /** Number of handles parked awaiting a non-blocking reap. */
    public static function pendingCount(): int
    {
        return \count(self::$unreaped);
    }

    /**
     * Line-oriented contract (the historical shape every metadata parser
     * here depends on): stdout split into lines, stderr merged in on
     * failure only.
     *
     * @param list<string> $argv program and its arguments (no shell)
     * @param array<string, string> $extraEnv merged over the inherited environment
     *
     * @return array{code: int, output: list<string>, truncated: bool}
     */
    public static function run(array $argv, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS, array $extraEnv = [], ?int $maxOutputBytes = null): array
    {
        $raw = self::spawn($argv, $timeoutSeconds, $extraEnv, $maxOutputBytes ?? self::DEFAULT_MAX_OUTPUT_BYTES);
        $stderr = $raw['stderr'];
        if ($raw['timedOut']) {
            $stderr .= ($stderr === '' ? '' : "\n")
                . 'candy-git-server: command timed out after ' . $timeoutSeconds . 's';
        }
        if ($raw['truncated']) {
            $stderr .= ($stderr === '' ? '' : "\n")
                . 'candy-git-server: stream output exceeded the byte cap; the remainder was discarded';
        }

        $merged = $raw['code'] === 0 || $stderr === '' ? $raw['stdout'] : $raw['stdout'] . ($raw['stdout'] === '' ? '' : "\n") . $stderr;

        return [
            'code' => $raw['code'],
            'truncated' => $raw['truncated'],
            // An empty stream is an empty line list — preg_split('') would
            // hand back [''] and every parser here filters by truthiness,
            // but callers counting tags/branches must see zero entries.
            'output' => $merged === '' ? [] : (\preg_split('/\r?\n/', \rtrim($merged, "\r\n")) ?: []),
        ];
    }

    /**
     * Byte-exact contract (S16, finding 031): stdout arrives as written,
     * no line-split and no implode — a CRLF file round-trips as CRLF.
     * Callers serving blob content use this so the bytes they hand out
     * are the repository's bytes.
     *
     * @param list<string> $argv program and its arguments (no shell)
     * @param array<string, string> $extraEnv merged over the inherited environment
     *
     * @return array{code: int, stdout: string, stderr: string, truncated: bool}
     */
    public static function runRaw(array $argv, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS, array $extraEnv = [], ?int $maxOutputBytes = null): array
    {
        $raw = self::spawn($argv, $timeoutSeconds, $extraEnv, $maxOutputBytes ?? self::DEFAULT_MAX_OUTPUT_BYTES);
        $timedOut = $raw['timedOut'];
        unset($raw['timedOut']);
        if ($timedOut) {
            $raw['stderr'] .= ($raw['stderr'] === '' ? '' : "\n")
                . 'candy-git-server: command timed out after ' . $timeoutSeconds . 's';
        }

        return $raw;
    }

    /**
     * Shared spawn/pump/reap core for both output contracts.
     *
     * @param list<string> $argv
     * @param array<string, string> $extraEnv
     *
     * @return array{code: int, stdout: string, stderr: string, truncated: bool, timedOut: bool}
     */
    private static function spawn(array $argv, float $timeoutSeconds, array $extraEnv, int $maxOutputBytes): array
    {
        if ($argv === []) {
            return [
                'code' => 127,
                'stdout' => '',
                'stderr' => 'candy-git-server: no command given',
                'truncated' => false,
                'timedOut' => false,
            ];
        }

        // S15: every call first sweeps parked handles from earlier
        // stuck children — the registry cannot grow past one entry per
        // genuinely-dead-stuck child, and a parked child that dies
        // between calls is collected here for free.
        self::reapPending();

        // Child-relative descriptors: index 0 ['pipe','r'] means the CHILD
        // reads stdin (the parent holds the write end); we never write a
        // prompt-answered stdin, so it is closed straight away.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $env = \getenv() !== false ? \getenv() : [];
        foreach ($extraEnv as $name => $value) {
            $env[$name] = $value;
        }

        $proc = @\proc_open($argv, $descriptors, $pipes, null, $env);
        if ($proc === false) {
            return [
                'code' => 127,
                'stdout' => '',
                'stderr' => 'candy-git-server: cannot launch ' . $argv[0],
                'truncated' => false,
                'timedOut' => false,
            ];
        }
        @\fclose($pipes[0]);
        \stream_set_blocking($pipes[1], false);
        \stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $truncated = false;
        $closeCode = -1;
        $stdoutOpen = true;
        $stderrOpen = true;
        $timedOut = false;
        $deadlineNs = \hrtime(true) + (int) \round($timeoutSeconds * 1_000_000_000);

        while ($stdoutOpen || $stderrOpen) {
            if (\hrtime(true) >= $deadlineNs) {
                $timedOut = true;

                break;
            }
            $read = [];
            if ($stdoutOpen) {
                $read[] = $pipes[1];
            }
            if ($stderrOpen) {
                $read[] = $pipes[2];
            }
            $write = null;
            $except = null;
            if (@\stream_select($read, $write, $except, 0, self::TICK_MICROSECONDS) === false) {
                continue; // signal-interrupted tick; the deadline still bites
            }
            foreach ($read as $stream) {
                $chunk = \fread($stream, self::CHUNK_BYTES);
                if ($chunk === false || ($chunk === '' && \feof($stream))) {
                    if ($stream === $pipes[1]) {
                        $stdoutOpen = false;
                    } else {
                        $stderrOpen = false;
                    }

                    continue;
                }
                if ($chunk === '') {
                    continue; // non-blocking not-ready read; try again next tick
                }
                if ($stream === $pipes[1]) {
                    // S16: stdout keeps its HEAD — a data read past the
                    // cap is flagged, and callers that care about
                    // integrity (Repo::readFile) refuse the partial bytes.
                    $room = $maxOutputBytes - \strlen($stdout);
                    if ($room <= 0) {
                        $truncated = true;

                        continue;
                    }
                    if (\strlen($chunk) > $room) {
                        $stdout .= \substr($chunk, 0, $room);
                        $truncated = true;
                    } else {
                        $stdout .= $chunk;
                    }
                } else {
                    // S16: stderr keeps its TAIL — a git fatal arrives
                    // last; a rolling window survives any flood before it.
                    $stderr .= $chunk;
                    if (\strlen($stderr) > $maxOutputBytes) {
                        $stderr = \substr($stderr, -$maxOutputBytes);
                        $truncated = true;
                    }
                }
            }
        }

        $code = null;
        try {
            $status = \proc_get_status($proc);
            // proc_get_status reports the exit code ONCE: the first read
            // after the child dies reaps it and later reads say -1. Latch
            // every observation of "not running" immediately.
            if (!$status['running']) {
                $code = $status['exitcode'];
            } else {
                @\proc_terminate($proc);
                $graceNs = \hrtime(true) + (int) \round(self::TERM_GRACE_SECONDS * 1_000_000_000);
                while (true) {
                    $status = \proc_get_status($proc);
                    if (!$status['running']) {
                        $code = $status['exitcode'];

                        break;
                    }
                    if (\hrtime(true) >= $graceNs) {
                        @\proc_terminate($proc, 9);
                        $killNs = \hrtime(true) + (int) \round(self::KILL_WAIT_SECONDS * 1_000_000_000);
                        while (true) {
                            $status = \proc_get_status($proc);
                            if (!$status['running']) {
                                $code = $status['exitcode'];

                                break 2;
                            }
                            if (\hrtime(true) >= $killNs) {
                                break 2; // unrecoverable zombie; code stays null
                            }
                            \usleep(10_000);
                        }

                        break;
                    }
                    \usleep(10_000);
                }
            }
        } finally {
            @\fclose($pipes[1]);
            @\fclose($pipes[2]);
            if ($code === null) {
                // KILL did not land inside KILL_WAIT. Closing the handle
                // here would block until the child exits anyway — park it
                // and let the next call's WNOHANG sweep collect. (Callers
                // see code -1: the truth is "ended late, status lost".)
                self::$unreaped[] = $proc;
            } else {
                $closeCode = \proc_close($proc);
            }
        }

        if ($code === null) {
            $code = $closeCode >= 0 ? $closeCode : -1;
        }

        return [
            'code' => $code,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'truncated' => $truncated,
            'timedOut' => $timedOut,
        ];
    }
}
