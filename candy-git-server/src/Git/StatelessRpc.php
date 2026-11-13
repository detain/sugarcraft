<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Git;

/**
 * The single stateless-rpc delegate: this package never re-implements git's
 * protocol brain, it pumps bytes to and from the real binary.
 *
 * The smart-HTTP read path advertises and
 * negotiates by spawning `git upload-pack --stateless-rpc` (and, for the
 * advertisement half of receive-pack, `git receive-pack --stateless-rpc`)
 * per request, exactly the way git's own http-backend does. Spawns go
 * through proc_open ARGV ARRAYS — never a shell string — per house law; the
 * repo path is trusted server config (docs/TRUST-BOUNDARY.md doctrine for
 * this package), never client input.
 *
 * Child-lifetime discipline: every spawn
 * function carries its own request-scoped reap — a bounded stdin write and
 * a bounded drain sharing ONE wall-clock deadline, stderr collected into a
 * bounded STDERR_TAIL_BYTES tail (reported on failure) so the child can
 * never block on a full error pipe, and on any undrained
 * outcome a TERM that escalates to KILL after a short grace, then
 * proc_close. No git child outlives the call that spawned it.
 *
 * Ground truth (PktLineTest + SmartHttp*Test, git 2.43):
 *  - `--stateless-rpc --advertise-refs` writes the bare advertisement
 *    (pkt-framed, no service header); http transports prepend the
 *    "# service=<name>\n" pkt + flush themselves — advertise() returns the
 *    git bytes and the caller frames the header.
 *  - a stateless-rpc CHILD reads the raw request body but rejects the
 *    service-header packet (fatal "expected to get object ID"), so
 *    negotiate() strips the leading "# service=…" pkt and its flush before
 *    feeding stdin — the http-backend's documented duty.
 */
final class StatelessRpc
{
    /** Wall-clock bound for an advertisement child (git answers instantly). */
    public const ADVERTISE_DEADLINE_SECONDS = 10.0;

    /** Wall-clock bound for a negotiation child: covers a full pack build. */
    public const NEGOTIATE_DEADLINE_SECONDS = 120.0;

    /** How much of the child's stderr is kept for failure reporting. */
    public const STDERR_TAIL_BYTES = 8192;

    /** Bytes handed to one fwrite feed by writeBounded() (the lib's write quantum). */
    private const WRITE_CHUNK_BYTES = 65536;

    /** Grace between SIGTERM and SIGKILL for an undrained child. */
    private const TERM_GRACE_SECONDS = 1.0;

    /** Ceiling for a SIGKILLed child to actually disappear. */
    private const KILL_WAIT_SECONDS = 2.0;

    /** Poll interval while waiting for a terminated child to die. */
    private const REAP_POLL_SECONDS = 0.01;

    /**
     * Negotiation outcome reasons. Timeout and overflow were both signalled
     * as null before, which made the caller answer every wedged child with
     * 413 "too large" — a misleading wire verdict for a slow child.
     */
    public const REASON_OK = 'ok';
    public const REASON_OVERFLOW = 'overflow';
    public const REASON_TIMEOUT = 'timeout';
    public const REASON_EXITED = 'exited';

    /**
     * E721 request-scoped reap, one ladder for every exit path — including
     * the SUCCESS path: a child that has closed its output pipes is polled
     * until it reports exited, and only then does proc_close() collect the
     * code. The old shape skipped straight to proc_close() on success, so a
     * git child that drained stdout but hung around (hook, stuck object
     * access) froze the whole single-loop server indefinitely.
     *
     * @return array{exitCode: int, signal: int}
     */
    private static function reapBounded($proc, array $pipes): array
    {
        // PHP reports a dead child's exit code exactly once — the call
        // that first observes running=false reaps it and every later call
        // reads -1. Latch the code at that first observation so the
        // failure log names the real status.
        $observedCode = null;
        $status = \proc_get_status($proc);
        if (!$status['running']) {
            $observedCode = $status['exitcode'];
        } else {
            @\proc_terminate($proc);
            if (!self::waitForExit($proc, self::TERM_GRACE_SECONDS, $observedCode)) {
                // 9 = SIGKILL: a child that ignored TERM gets the hard end.
                @\proc_terminate($proc, 9);
                self::waitForExit($proc, self::KILL_WAIT_SECONDS, $observedCode);
            }
            $status = \proc_get_status($proc);
        }

        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @\fclose($pipe);
            }
        }

        $closeRc = \proc_close($proc);
        $exitCode = $observedCode ?? ($closeRc >= 0 ? $closeRc : $status['exitcode']);

        return ['exitCode' => (int) $exitCode, 'signal' => (int) $status['termsig']];
    }

    private static function waitForExit($proc, float $seconds, ?int &$observedCode = null): bool
    {
        $until = \hrtime(true) + (int) ($seconds * 1e9);
        while (\hrtime(true) < $until) {
            $status = \proc_get_status($proc);
            if (!$status['running']) {
                $observedCode ??= $status['exitcode'];

                return true;
            }
            \usleep((int) (self::REAP_POLL_SECONDS * 1e6));
        }

        $status = \proc_get_status($proc);
        if (!$status['running']) {
            $observedCode ??= $status['exitcode'];

            return true;
        }

        return false;
    }

    private function __construct()
    {
    }

    /**
     * The git sub-command implementing a smart-HTTP service, or null when
     * the service name is not a git transport service at all.
     */
    public static function binaryFor(string $service): ?string
    {
        return match ($service) {
            'git-upload-pack'  => 'upload-pack',
            'git-receive-pack' => 'receive-pack',
            default            => null,
        };
    }

    /**
     * Run `git <binary> --stateless-rpc --advertise-refs` over $repoPath and
     * return its raw pkt-framed advertisement bytes.
     *
     * @param float|null $deadlineSeconds override for tests/ops; the
     *                                    production default stays
     *                                    {@see ADVERTISE_DEADLINE_SECONDS}
     *
     * @throws \RuntimeException when the child cannot start or fails
     */
    public static function advertise(string $service, string $repoPath, ?float $deadlineSeconds = null): string
    {
        $binary = self::binaryFor($service);
        if ($binary === null) {
            throw new \RuntimeException('unknown smart-HTTP service: ' . $service);
        }

        $proc = \proc_open(
            ['git', '-C', $repoPath, $binary, '--stateless-rpc', '--advertise-refs', '.'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!\is_resource($proc)) {
            throw new \RuntimeException('failed to start git ' . $binary . ' --advertise-refs');
        }

        // E721 request-scoped reap: stdout is the answer, stderr keeps a
        // bounded tail (it is drained either way, so the child can never
        // block on a full error pipe). reapBounded() ends the child on
        // EVERY path — success included — never an unbounded proc_close.
        $stderrTail = '';
        $timedOut   = false;
        try {
            \fclose($pipes[0]);
            $drained = self::drainBounded(
                $pipes[1],
                $pipes[2],
                \hrtime(true) + (int) (($deadlineSeconds ?? self::ADVERTISE_DEADLINE_SECONDS) * 1e9),
                \PHP_INT_MAX,
                $stderrTail
            );
            $bytes    = $drained['bytes'];
            $timedOut = $drained['reason'] === self::REASON_TIMEOUT;
        } finally {
            $reap = self::reapBounded($proc, $pipes);
        }

        // A timeout truncates the advertisement mid-frame; handing the
        // partial bytes to the client would masquerade as a valid answer.
        if ($timedOut) {
            self::reportFailure($binary, '--advertise-refs', 'timeout', $reap, $stderrTail);
            throw new \RuntimeException('git ' . $binary . ' --advertise-refs timed out');
        }
        if ($bytes === '' && $reap['exitCode'] !== 0) {
            self::reportFailure($binary, '--advertise-refs', 'exit', $reap, $stderrTail);
            throw new \RuntimeException('git ' . $binary . ' --advertise-refs exited ' . $reap['exitCode']);
        }

        return $bytes;
    }

    /**
     * A failed child used to be silent — stderr drained to null and
     * the reason (timeout vs exit code) was lost. The bounded tail now goes
     * to the server's STDERR so a 500 on the wire has a matching log line.
     */
    private static function reportFailure(string $binary, string $mode, string $reason, array $reap, string $stderrTail): void
    {
        $line = "candy-git-server: git {$binary} {$mode} failed ({$reason}, exit code {$reap['exitCode']}, signal {$reap['signal']})";
        if ($stderrTail !== '') {
            $line .= "\n" . $stderrTail;
        }
        \fwrite(\STDERR, $line . "\n");
    }

    /**
     * Run one stateless-rpc negotiation: feed $body (the smart-HTTP request
     * body, service header included — clients send it) to
     * `git <binary> --stateless-rpc` and return the response.
     *
     * The child carries the protocol: wants, haves, NAK/ACK, side-band and
     * the packfile all come from the real binary, byte-exact.
     *
     * The outcome is TYPED: the old ?string return conflated a
     * wedged child (timeout) with a response over the cap (overflow) —
     * both null, so the caller answered every slow child with a wrong
     * "413 too large". Callers now branch on REASON_*.
     *
     * @param int        $maxBytes        refuse responses above this many bytes
     * @param float|null $deadlineSeconds production default stays
     *                                    {@see NEGOTIATE_DEADLINE_SECONDS}
     *
     * @return array{bytes: string, reason: string, stderrTail: string, exitCode: int}
     *         bytes is whatever was collected before the verdict (full
     *         response only on REASON_OK); stderrTail is the bounded tail
     *         the child wrote to its error pipe.
     * @throws \RuntimeException when the child cannot start
     */
    public static function negotiate(
        string $service,
        string $repoPath,
        string $body,
        int $maxBytes,
        ?float $deadlineSeconds = null
    ): array {
        $binary = self::binaryFor($service);
        if ($binary === null) {
            throw new \RuntimeException('unknown smart-HTTP service: ' . $service);
        }

        // git's http-backend strips the service header (plus its flush)
        // before handing the body to a stateless-rpc child; measured on this
        // host: leaving it in makes upload-pack die with
        // "expected to get object ID". Unparseable bodies pass through
        // verbatim — the child's in-band protocol error IS the answer.
        $stdin = self::stripServiceHeader($service, $body);

        $proc = \proc_open(
            ['git', '-C', $repoPath, $binary, '--stateless-rpc', '.'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!\is_resource($proc)) {
            throw new \RuntimeException('failed to start git ' . $binary . ' --stateless-rpc');
        }

        // E721 request-scoped reap — same ladder as advertise(): ONE
        // deadline budget covers write + drain together; writeBounded()
        // feeds stdin only as fast as a consuming child takes it and stops
        // at the deadline; a child that never reads is refused and ended
        // by reapBounded()'s TERM→KILL run, never frozen mid-fwrite.
        $deadline = \hrtime(true) + (int) (($deadlineSeconds ?? self::NEGOTIATE_DEADLINE_SECONDS) * 1e9);
        $stderrTail = '';
        $bytes = '';
        $reason = self::REASON_OK;
        try {
            self::writeBounded($pipes[0], $stdin, $deadline);
            \fclose($pipes[0]);
            $drained = self::drainBounded($pipes[1], $pipes[2], $deadline, $maxBytes, $stderrTail);
            $bytes   = $drained['bytes'];
            $reason  = $drained['reason'];
        } finally {
            $reap = self::reapBounded($proc, $pipes);
        }

        // Reported once the child is actually dead, so the line carries
        // the true exit status rather than a placeholder.
        if ($reason !== self::REASON_OK) {
            self::reportFailure($binary, '--stateless-rpc', $reason, $reap, $stderrTail);
        } elseif ($reap['exitCode'] !== 0 && $bytes === '') {
            $reason = self::REASON_EXITED;
            self::reportFailure($binary, '--stateless-rpc', 'exit', $reap, $stderrTail);
        }

        return [
            'bytes' => $bytes,
            'reason' => $reason,
            'stderrTail' => $stderrTail,
            'exitCode' => $reap['exitCode'],
        ];
    }

    /**
     * Remove a leading "# service=<name>" packet (and its flush) from a
     * smart-HTTP request body, exactly once; everything else rides through
     * untouched (including bodies that are not pkt-framed at all).
     *
     * Byte-slice, not decode-reshape: the old implementation ran
     * the WHOLE body through PktLine::decode() and re-encoded every
     * packet — which (a) throws on any legitimate payload above the
     * per-packet cap (real push command packets with long refs/caps, and
     * packfile bytes are NOT packets at all), and the catch then fed the
     * child the UNSTRIPPED body: guaranteed "expected to get object ID"
     * death on exactly the requests that matter; (b) churned the body 3x
     * through memory. Only the first two packets are parsed; the rest of
     * the wire is relocated by offset, raw.
     */
    private static function stripServiceHeader(string $service, string $body): string
    {
        if (\strlen($body) < PktLine::HEADER_BYTES || !\ctype_xdigit(\substr($body, 0, PktLine::HEADER_BYTES))) {
            return $body;
        }
        $length = \hexdec(\substr($body, 0, PktLine::HEADER_BYTES));
        if ($length < PktLine::HEADER_BYTES || $length > PktLine::MAX_PACKET_BYTES || \strlen($body) < $length) {
            return $body;
        }

        $first = \substr($body, PktLine::HEADER_BYTES, $length - PktLine::HEADER_BYTES);
        if (!PktLine::isServiceHeader($first, $service)) {
            return $body;
        }
        $offset = $length;

        // Skip the header's flush packet ("0000") when present.
        if (\strlen($body) >= $offset + PktLine::HEADER_BYTES
            && \substr($body, $offset, PktLine::HEADER_BYTES) === PktLine::FLUSH
        ) {
            $offset += PktLine::HEADER_BYTES;
        }

        return \substr($body, $offset);
    }

    /**
     * Feed $bytes to a (non-blocking) child stdin pipe until the whole body
     * is accepted or the absolute wall-clock $deadlineMono passes — never a
     * bare fwrite that could freeze the single-loop server on a child that
     * stops consuming.
     *
     * @param resource $stream
     * @throws \RuntimeException when the child did not take the whole body in time
     */
    private static function writeBounded($stream, string $bytes, int $deadlineNs): void
    {
        \stream_set_blocking($stream, false);

        $offset = 0;
        $total  = \strlen($bytes);
        while ($offset < $total) {
            // One chunk per feed: re-slicing the whole remainder on every
            // partial accept made a large push body quadratic work for the
            // server (the fleet's quadratic-writer family; S02-lineage).
            $written = @\fwrite($stream, \substr($bytes, $offset, self::WRITE_CHUNK_BYTES));
            if ($written === false) {
                throw new \RuntimeException('stateless-rpc stdin closed by the child');
            }
            if ($written > 0) {
                $offset += $written;
                continue;
            }
            if (\hrtime(true) >= $deadlineNs) {
                throw new \RuntimeException('stateless-rpc stdin write stalled past the deadline');
            }
            $read = $except = null;
            $write = [$stream];
            @\stream_select($read, $write, $except, 0, 100_000); // 100 ms tick
        }
    }

    /**
     * Read stdout (capped) while stderr is collected (bounded tail), until
     * both close or the absolute hrtime deadline passes. A wedged child
     * ends the call at the deadline with REASON_TIMEOUT and whatever was
     * already collected — the caller's reapBounded() then ends the child,
     * so this function itself cannot hang.
     *
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     * @param int $deadlineNs absolute hrtime deadline in nanoseconds
     * @param int $maxBytes stdout collection cap
     * @param string $stderrTail bounded stderr tail, collected by reference
     * @return array{bytes: string, reason: string}
     */
    private static function drainBounded($stdoutPipe, $stderrPipe, int $deadlineNs, int $maxBytes, string &$stderrTail): array
    {
        $data   = '';
        $outEof = false;
        $errEof = false;

        \stream_set_blocking($stdoutPipe, false);
        \stream_set_blocking($stderrPipe, false);

        while (!($outEof && $errEof)) {
            $remainingNs = $deadlineNs - \hrtime(true);
            if ($remainingNs <= 0) {
                return ['bytes' => $data, 'reason' => self::REASON_TIMEOUT]; // wedged child — caller reaps
            }

            $read = [];
            if (!$outEof) {
                $read[] = $stdoutPipe;
            }
            if (!$errEof) {
                $read[] = $stderrPipe;
            }

            $null  = null;
            $ready = @\stream_select($read, $null, $null, 0, (int) \min(1_000_000, $remainingNs));
            if ($ready === false) {
                \usleep(1000); // interrupted (signal): re-check the deadline next turn
                continue;
            }

            foreach ($read as $stream) {
                $chunk = \fread($stream, 65536);
                $closed = $chunk === false || ($chunk === '' && \feof($stream));

                if ($stream === $stderrPipe) {
                    // Collected for the failure line, tail-bounded
                    // so a chatty child cannot balloon server memory; the
                    // pipe is drained either way, so the child never
                    // blocks on it.
                    if ($chunk !== false && $chunk !== '') {
                        $stderrTail .= $chunk;
                        if (\strlen($stderrTail) > self::STDERR_TAIL_BYTES) {
                            $stderrTail = \substr($stderrTail, -self::STDERR_TAIL_BYTES);
                        }
                    }
                    if ($closed || \feof($stderrPipe)) {
                        $errEof = true;
                    }

                    continue;
                }

                if ($closed || \feof($stdoutPipe)) {
                    $outEof = true;
                }

                if ($chunk === '' || $chunk === false) {
                    continue;
                }

                $data .= $chunk;
                if (\strlen($data) > $maxBytes) {
                    return ['bytes' => $data, 'reason' => self::REASON_OVERFLOW]; // caller answers 413
                }
            }
        }

        return ['bytes' => $data, 'reason' => self::REASON_OK];
    }
}
