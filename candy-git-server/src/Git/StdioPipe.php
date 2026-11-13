<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Git;

/**
 * Bounded bidirectional stdio pump between a client connection and a
 * short-lived git child process.
 *
 * The daemon (and, from the SSH transport, the git services) delegate
 * protocol work to the REAL `git upload-pack` / `git receive-pack`
 * binaries rather than emulating the negotiation: framing, capability
 * grammar, packfile construction and ref updates then cannot drift out
 * of spec, because this process never parses them. The pump is the
 * transport glue around that child: bytes from the client go to the
 * child's stdin, the child's stdout streams straight back to the
 * client, and the child's stderr is collected (tail only) for honest
 * failure reporting.
 *
 * CHILD-PROCESS LIFETIME: the child is REQUEST-scoped. Every
 * exit path — normal drain, idle give-up, hard deadline, or an
 * unwinding exception — funnels through one `finally` that terminates
 * (SIGTERM, then SIGKILL after a grace poll) and closes the handle, so
 * no git child can outlive run(). The pump itself is SYNCHRONOUS with
 * respect to its caller — it drives one blocking select loop from start
 * to finish. WHO the caller is decides whether that delays anyone else:
 * under the HTTP smart-protocol front door (owner ruling 8b) each
 * connection runs in its own {@see \SugarCraft\GitServer\Support\WorkerFork}
 * worker, so a long transfer stalls no sibling; on the git:// daemon and
 * the SSH transport, where the pump runs inside a shared event-loop
 * readiness callback, it still holds that loop for its duration — the
 * per-transport limitation S39 keeps on the record.
 */
final class StdioPipe
{
    /** Bytes requested per pump tick on each readable handle. */
    public const CHUNK_BYTES = 65536;

    /** Give up when neither direction has moved a single byte this long. */
    public const IDLE_SECONDS = 10.0;

    /** Absolute ceiling for one pumped request. */
    public const HARD_SECONDS = 300.0;

    /** How much of the child's stderr is kept for the failure line. */
    public const STDERR_TAIL_BYTES = 8192;

    /**
     * Ceiling on the client→child stdin backlog held in memory while the
     * child's pipe refuses more bytes. A healthy child drains its stdin
     * continuously, so the pile never grows past roughly one pipe buffer;
     * a sustained multi-megabyte backlog means the child stopped consuming
     * (wedged or hostile). Left uncapped, the pile grows until the hard
     * wall-clock stop — a memory-exhaustion vector on the authenticated
     * push path, where a client can stream gigabytes regardless of what
     * the repository ever was. This is a MEMORY bound, not a timeout.
     */
    public const PENDING_TO_CHILD_MAX_BYTES = 16 * 1024 * 1024;

    private const TICK_MICROSECONDS = 100_000;

    private const REAP_POLL_MICROSECONDS = 10_000;

    private const TERM_GRACE_SECONDS = 1.0;

    private const KILL_WAIT_SECONDS = 2.0;

    /**
     * Pump one request between the client pair and the spawned child.
     *
     * The client handles are NEVER closed here — the caller owns them
     * (the daemon's closeClient does). Passing the same handle for both
     * directions (a bidirectional socket) is the normal case.
     *
     * @param resource|\Socket $in   client read side
     * @param resource|\Socket $out  client write side (same socket for a
     *                               bidirectional connection is fine)
     * @param list<string>     $argv child command as an ARGV ARRAY — no
     *                               shell is ever involved
     * @param string           $cwd  working directory for the child
     * @param string           $seed request bytes already read from the
     *                               client (the bytes following the
     *                               request line), fed before any reads
     * @param float|null       $idleSeconds idle ceiling override (tests)
     * @param float|null       $hardSeconds absolute ceiling override (tests)
     *
     * @return int the child's exit code, or a synthetic failure code
     *             (1 = client/stream unusable — including a stdin backlog past
     *             {@see self::PENDING_TO_CHILD_MAX_BYTES} — , 127 = spawn failed)
     */
    public static function run(
        $in,
        $out,
        array $argv,
        string $cwd,
        string $seed = '',
        ?float $idleSeconds = null,
        ?float $hardSeconds = null
    ): int {
        $idleNs = (int) (($idleSeconds ?? self::IDLE_SECONDS) * 1e9);
        $hardNs = (int) (($hardSeconds ?? self::HARD_SECONDS) * 1e9);

        $inStream = self::resolve($in);
        $outStream = $in === $out ? $inStream : self::resolve($out);
        if ($inStream === null || $outStream === null) {
            return 1;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        /** @var array<int, resource|null> $pipes */
        $pipes = [];
        /** @var resource|\Process|false $proc */
        $proc = @\proc_open($argv, $descriptors, $pipes, $cwd);
        if ($proc === false) {
            $label = self::label($argv);
            \fwrite(\STDERR, "candy-git-server: could not start the {$label} child\n");

            return 127;
        }

        $errTail = '';
        $exitCode = -1;
        // PHP's proc_get_status() reports the exit code exactly once: the
        // call that observes the child as no-longer-running reaps it and
        // every later call reads -1. Latch the code on that first
        // observation so the final report tells the truth.
        $observedCode = null;
        // Declared before the try so the finally block is meaningful even
        // when the pump body throws on its first statements.
        $childErrDone = false;
        // Set when the client→child backlog crosses PENDING_TO_CHILD_MAX_BYTES.
        // Kept separate from $exitCode because the finally block unconditionally
        // recomputes $exitCode from the reaped child — a synthetic breach code
        // written inside the loop would be clobbered there.
        $backlogBreached = false;
        try {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\stream_set_blocking($pipe, false);
                }
            }

            $toChild = $seed;
            if (\strlen($toChild) > self::PENDING_TO_CHILD_MAX_BYTES) {
                // The seed itself is already past the cap: refuse before a
                // byte is pushed at the child.
                $backlogBreached = true;
            }
            $pendingOut = '';
            $writtenOut = 0;
            $childInOpen = true;
            $clientInDone = false;
            $childOutDone = false;
            $clientGone = false;

            $startNs = \hrtime(true);
            $lastActivityNs = $startNs;
            $wedgedNs = null;

            while (!$backlogBreached) {
                $read = [];
                if (!$clientInDone) {
                    $read[] = $inStream;
                }
                if (!$childOutDone) {
                    $read[] = $pipes[1];
                }
                if (!$childErrDone) {
                    $read[] = $pipes[2];
                }
                $write = [];
                if ($childInOpen && $toChild !== '') {
                    $write[] = $pipes[0];
                }
                if (!$clientGone && $pendingOut !== '' && $writtenOut < \strlen($pendingOut)) {
                    $write[] = $outStream;
                }

                $running = self::childRunning($proc, $observedCode);
                $nowNs = \hrtime(true);
                if ($nowNs - $startNs >= $hardNs || $nowNs - $lastActivityNs >= $idleNs) {
                    break;
                }
                if ($read === [] && $write === []) {
                    // Nothing left to talk to. If the child is still
                    // running it is wedged; the reap ladder ends it.
                    if (!$running) {
                        break;
                    }
                    $wedgedNs ??= $nowNs;
                    if ($nowNs - $wedgedNs >= $idleNs) {
                        break;
                    }
                    \usleep(self::TICK_MICROSECONDS);
                    continue;
                }

                $except = null;
                $readCopy = $read;
                $writeCopy = $write;
                $ready = @\stream_select($readCopy, $writeCopy, $except, 0, self::TICK_MICROSECONDS);
                $nowNs = \hrtime(true);

                if ($ready === false) {
                    // Signal-interrupted or errored select: treat as an
                    // empty tick; the deadlines above still bound the
                    // loop. (pcntl handlers land here by design.)
                    continue;
                }

                // Client -> child
                if (!$clientInDone && \in_array($inStream, $readCopy, true)) {
                    $chunk = @\fread($inStream, self::CHUNK_BYTES);
                    if ($chunk === false || ($chunk === '' && @\feof($inStream))) {
                        $clientInDone = true;
                    } elseif ($chunk !== '') {
                        $toChild .= $chunk;

                        if (\strlen($toChild) > self::PENDING_TO_CHILD_MAX_BYTES) {
                            // n11: the pile is past the cap — the child stopped
                            // consuming. Fail the stream instead of growing
                            // without bound until the wall-clock stop.
                            $backlogBreached = true;

                            break;
                        }

                        $lastActivityNs = $nowNs;
                    }
                }

                // Child stdout -> client (buffer only the unflushed tail)
                if (!$childOutDone && \in_array($pipes[1], $readCopy, true)) {
                    $chunk = @\fread($pipes[1], self::CHUNK_BYTES);
                    if ($chunk === false || $chunk === '') {
                        if (@\feof($pipes[1])) {
                            $childOutDone = true;
                        }
                    } else {
                        if ($writtenOut > 0) {
                            $pendingOut = \substr($pendingOut, $writtenOut);
                            $writtenOut = 0;
                        }
                        $pendingOut .= $chunk;
                        $lastActivityNs = $nowNs;
                    }
                }

                // Child stderr -> bounded tail buffer
                if (!$childErrDone && \in_array($pipes[2], $readCopy, true)) {
                    $chunk = @\fread($pipes[2], self::CHUNK_BYTES);
                    if ($chunk === false || $chunk === '') {
                        if (@\feof($pipes[2])) {
                            $childErrDone = true;
                        }
                    } else {
                        $errTail .= $chunk;
                        if (\strlen($errTail) > self::STDERR_TAIL_BYTES) {
                            $errTail = \substr($errTail, -self::STDERR_TAIL_BYTES);
                        }
                        $lastActivityNs = $nowNs;
                    }
                }

                // Pending client -> child bytes (one CHUNK_BYTES feed per
                // tick: the full-buffer fwrite re-copied the whole pile on
                // every partial accept — quadratic against a stalled child).
                if ($childInOpen && $toChild !== '') {
                    $n = @\fwrite($pipes[0], \substr($toChild, 0, self::CHUNK_BYTES));
                    if ($n === false) {
                        $childInOpen = false; // child closed its stdin
                    } elseif ($n > 0) {
                        $toChild = \substr($toChild, $n);
                        $lastActivityNs = $nowNs;
                    }
                }
                if ($childInOpen && $clientInDone && $toChild === '') {
                    $childInOpen = false;
                    @\fclose($pipes[0]);
                    $pipes[0] = null;
                }

                // Pending child -> client bytes
                if (!$clientGone && $pendingOut !== '' && $writtenOut < \strlen($pendingOut)) {
                    $n = @\fwrite($outStream, \substr($pendingOut, $writtenOut, self::CHUNK_BYTES));
                    if ($n === false) {
                        $clientGone = true;
                    } elseif ($n > 0) {
                        $writtenOut += $n;
                        $lastActivityNs = $nowNs;
                    }
                }
                if ($writtenOut >= \strlen($pendingOut)) {
                    $pendingOut = '';
                    $writtenOut = 0;
                }

                // Done when the child has exited and both output pipes
                // are drained (EOF observed) and nothing is pending for
                // either direction. The client side deliberately does
                // NOT gate the exit: one-shot delegations (the daemon's
                // `--advertise-refs` child) finish long before the
                // client half-closes, and making the client wait out
                // the idle deadline would fake a hung server. Whatever
                // is still buffered from the client is discarded by the
                // caller closing the connection.
                $toChildEmpty = $toChild === '' || !$childInOpen;
                if (!self::childRunning($proc, $observedCode)
                    && $childOutDone
                    && $toChildEmpty
                    && ($pendingOut === '' || $clientGone)
                ) {
                    break;
                }
            }
        } finally {
            // E721 reap ladder: TERM, bounded poll, KILL, bounded poll,
            // then close the handle. proc_close never waits on a child
            // we did not already bound.
            //
            // S15 (finding 023): the ladder runs FIRST, the stderr drain
            // SECOND. The old order drained up to 64 guarded ticks BEFORE
            // the TERM that could actually end a wedged child — every
            // teardown against a live child burned a full drain window
            // first, and a child blocked on a full stderr pipe (the very
            // case that wedge produces) got no relief from a parent that
            // is only nominally draining it while the child never
            // schedules. After the ladder the child is gone, its write
            // ends are closed by the OS, buffered bytes remain readable
            // until our fclose — so the drain below cannot stall.
            //
            // Kill-tree boundary: the escalation reaches THIS direct child
            // only. A git process that spawned its own helpers is not
            // signalled transitively; the contract that makes this safe is
            // that these services exec git binaries without a shell and
            // without hook-side daemons inheriting the pipes.
            if (self::childRunning($proc, $observedCode)) {
                @\proc_terminate($proc);
                if (!self::waitForExit($proc, self::TERM_GRACE_SECONDS, $observedCode)) {
                    @\proc_terminate($proc, 9);
                    self::waitForExit($proc, self::KILL_WAIT_SECONDS, $observedCode);
                }
            }
            // Honest failure reporting: a fast-dying child can still have
            // stderr bytes in the pipe when the loop breaks (the exit
            // condition above does not wait for STDERR EOF).
            if (isset($pipes[2]) && \is_resource($pipes[2])) {
                // Read until the pipe reports EOF: a drained-but-open
                // non-blocking stream returns '' repeatedly, so the loop
                // must terminate on feof() (or on a read error).
                $guard = 0;
                while ($guard++ < 64) {
                    $chunk = @\fread($pipes[2], self::CHUNK_BYTES);
                    if ($chunk === false) {
                        break;
                    }
                    if ($chunk !== '') {
                        $errTail .= $chunk;
                        continue;
                    }
                    if (@\feof($pipes[2])) {
                        $childErrDone = true;
                        break;
                    }
                    \usleep(self::TICK_MICROSECONDS);
                }
                if (\strlen($errTail) > self::STDERR_TAIL_BYTES) {
                    $errTail = \substr($errTail, -self::STDERR_TAIL_BYTES);
                }
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            $reapedCode = $observedCode ?? \proc_get_status($proc)['exitcode'];
            $closeRc = \proc_close($proc);
            // Capture the code BEFORE proc_close: after it, the status is
            // gone. Precedence note: bare `$closeRc >= 0 ? $closeRc : x
            // ?? y` mis-groups in PHP 8 — parenthesise explicitly.
            $exitCode = $closeRc >= 0 ? $closeRc : ($reapedCode >= 0 ? $reapedCode : $closeRc);
        }

        if ($backlogBreached) {
            // n11: the backlog cap fired; the finally ladder already reaped
            // the child. Contract code 1 = client/stream unusable.
            \fwrite(\STDERR, 'candy-git-server: ' . self::label($argv) . ' stdin backlog exceeded ' . self::PENDING_TO_CHILD_MAX_BYTES . " bytes; stream failed\n");

            return 1;
        }

        if ($exitCode !== 0) {
            $label = self::label($argv);
            $line = "candy-git-server: {$label} exited with code {$exitCode}";
            if ($errTail !== '') {
                $line .= "\n" . $errTail;
            }
            \fwrite(\STDERR, $line . "\n");
        }

        return $exitCode;
    }

    /**
     * Normalize a client handle to a non-blocking stream, or null when
     * it is not usable (dead socket, closed resource).
     *
     * @param resource|\Socket $handle
     * @return resource|null
     */
    private static function resolve($handle)
    {
        if ($handle instanceof \Socket) {
            $stream = @\socket_export_stream($handle);
            if ($stream === false) {
                return null;
            }
            @\stream_set_blocking($stream, false);

            return $stream;
        }

        if (\is_resource($handle)) {
            @\stream_set_blocking($handle, false);

            return $handle;
        }

        return null;
    }

    private static function waitForExit($proc, float $seconds, ?int &$observedCode = null): bool
    {
        $untilNs = \hrtime(true) + (int) ($seconds * 1e9);
        while (\hrtime(true) < $untilNs) {
            if (!self::childRunning($proc, $observedCode)) {
                return true;
            }
            \usleep(self::REAP_POLL_MICROSECONDS);
        }

        return !self::childRunning($proc, $observedCode);
    }

    /**
     * Is the child still running? The first observation that it is NOT
     * reaps it and latches its exit code through $observedExit (later
     * proc_get_status calls would only read -1).
     *
     * @param resource|\Process $proc
     */
    private static function childRunning($proc, ?int &$observedExit): bool
    {
        $status = \proc_get_status($proc);
        if (!$status['running'] && $observedExit === null) {
            $observedExit = $status['exitcode'];
        }

        return (bool) $status['running'];
    }

    /**
     * Human name for a git child from its argv: the subcommand token
     * (upload-pack, receive-pack, ...), skipping flags like -C and path
     * arguments that are not a bare lowercase word.
     *
     * @param array<int, string> $argv
     */
    private static function label(array $argv): string
    {
        foreach ($argv as $token) {
            if (\preg_match('/^[a-z][a-z0-9-]*$/', $token) && $token !== 'git') {
                return $token;
            }
        }

        return $argv[1] ?? 'git';
    }
}
