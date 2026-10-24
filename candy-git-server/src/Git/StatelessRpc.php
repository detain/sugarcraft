<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Git;

/**
 * The single stateless-rpc delegate: this package never re-implements git's
 * protocol brain, it pumps bytes to and from the real binary.
 *
 * Owner ruling 1b (r1024/githttp): the smart-HTTP read path advertises and
 * negotiates by spawning `git upload-pack --stateless-rpc` (and, for the
 * advertisement half of receive-pack, `git receive-pack --stateless-rpc`)
 * per request, exactly the way git's own http-backend does. Spawns go
 * through proc_open ARGV ARRAYS — never a shell string — per house law; the
 * repo path is trusted server config (docs/TRUST-BOUNDARY.md doctrine for
 * this package), never client input.
 *
 * Child-lifetime discipline (E721 / ChafaRenderer pattern): every spawn
 * function carries its own request-scoped reap — a bounded drain with a
 * wall-clock deadline, stderr drained to null so the child can never block
 * on a full error pipe, and on any undrained outcome a TERM that escalates
 * to KILL after a short grace, then proc_close. No git child outlives the
 * call that spawned it.
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

    /** Grace between SIGTERM and SIGKILL for an undrained child. */
    private const TERM_GRACE_SECONDS = 1.0;

    /** Poll interval while waiting for a terminated child to die. */
    private const REAP_POLL_SECONDS = 0.01;

    /**
     * SIGKILL a child that ignored TERM within the grace window. The number
     * 9 is the Unix SIGKILL constant — no ext-pcntl dependency.
     */
    private static function escalateToKill($proc): void
    {
        $grace = \microtime(true) + self::TERM_GRACE_SECONDS;
        while (\microtime(true) < $grace && \proc_get_status($proc)['running']) {
            \usleep((int) (self::REAP_POLL_SECONDS * 1e6));
        }

        if (\proc_get_status($proc)['running']) {
            @\proc_terminate($proc, 9);
        }
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
     * @throws \RuntimeException when the child cannot start or fails
     */
    public static function advertise(string $service, string $repoPath): string
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

        // E721 request-scoped reap: stdout is the answer, stderr is drained
        // to null (it must never block the child); anything undrained at the
        // end of the bounded read is terminated-then-killed before close.
        $bytes     = null;
        $exitCode  = -1;
        try {
            \fclose($pipes[0]);
            $bytes = self::drainBounded($proc, $pipes[1], $pipes[2], self::ADVERTISE_DEADLINE_SECONDS, \PHP_INT_MAX);
        } finally {
            if ($bytes === null) {
                @\proc_terminate($proc);
                self::escalateToKill($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            $exitCode = \proc_close($proc);
        }

        if ($bytes === null || $bytes === '' && $exitCode !== 0) {
            throw new \RuntimeException('git ' . $binary . ' --advertise-refs exited ' . $exitCode);
        }

        return $bytes;
    }

    /**
     * Run one stateless-rpc negotiation: feed $body (the smart-HTTP request
     * body, service header included — clients send it) to
     * `git <binary> --stateless-rpc` and return the raw response bytes.
     *
     * The child carries the protocol: wants, haves, NAK/ACK, side-band and
     * the packfile all come from the real binary, byte-exact.
     *
     * @param int $maxBytes refuse responses above this many bytes
     * @return string|null null when the response overflowed $maxBytes (the
     *                     caller answers 413 and the child is reaped anyway)
     * @throws \RuntimeException when the child cannot start
     */
    public static function negotiate(string $service, string $repoPath, string $body, int $maxBytes): ?string
    {
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

        // E721 request-scoped reap — same ladder as advertise(): the write
        // may itself block on a wedged child, so the deadline covers write +
        // drain together and every undrained outcome escalates TERM→KILL.
        $bytes = null;
        try {
            \fwrite($pipes[0], $stdin);
            \fclose($pipes[0]);
            $bytes = self::drainBounded($proc, $pipes[1], $pipes[2], self::NEGOTIATE_DEADLINE_SECONDS, $maxBytes);
        } finally {
            if ($bytes === null) {
                @\proc_terminate($proc);
                self::escalateToKill($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @\fclose($pipe);
                }
            }
            \proc_close($proc);
        }

        return $bytes;
    }

    /**
     * Remove a leading "# service=<name>" packet (and its flush) from a
     * smart-HTTP request body, exactly once; everything else rides through
     * untouched (including bodies that are not pkt-framed at all).
     */
    private static function stripServiceHeader(string $service, string $body): string
    {
        if (\strlen($body) < PktLine::HEADER_BYTES || \ctype_xdigit(\substr($body, 0, PktLine::HEADER_BYTES)) !== true) {
            return $body;
        }

        try {
            $packets = PktLine::decode($body);
        } catch (\RuntimeException) {
            return $body;
        }

        $first = $packets[0] ?? null;
        if (!\is_string($first) || !PktLine::isServiceHeader($first, $service)) {
            return $body;
        }

        \array_shift($packets);
        if (($packets[0] ?? null) === null) {
            \array_shift($packets); // the header's flush-pkt
        }

        return \implode('', \array_map(static fn (?string $p): string => PktLine::encode((string) $p), $packets));
    }

    /**
     * Read stdout (capped) while stderr drains to null, until both close or
     * the wall-clock deadline passes. A wedged child that never closes its
     * pipes ends the call at the deadline with null — the caller's reap
     * escalates TERM→KILL, so this function itself cannot hang.
     *
     * @param resource $proc
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     * @return string|null null on deadline or size-cap overflow
     */
    private static function drainBounded($proc, $stdoutPipe, $stderrPipe, float $deadlineSeconds, int $maxBytes): ?string
    {
        $deadline = \microtime(true) + $deadlineSeconds;
        $data     = '';
        $outEof   = false;
        $errEof   = false;

        \stream_set_blocking($stdoutPipe, false);
        \stream_set_blocking($stderrPipe, false);

        while (!($outEof && $errEof)) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0.0) {
                return null; // wedged child — the caller's reap escalates
            }

            $read = [];
            if (!$outEof) {
                $read[] = $stdoutPipe;
            }
            if (!$errEof) {
                $read[] = $stderrPipe;
            }

            $null  = null;
            $ready = @\stream_select($read, $null, $null, (int) $remaining, (int) (($remaining - (int) $remaining) * 1e6));
            if ($ready === false) {
                \usleep(1000); // interrupted (signal): re-check the deadline next turn
                continue;
            }

            foreach ($read as $stream) {
                $chunk = \fread($stream, 65536);
                $closed = $chunk === false || ($chunk === '' && \feof($stream));

                if ($stream === $stderrPipe) {
                    // Drain to null: the child must never block on a full
                    // error pipe; we only watch it for its close.
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
                    return null; // response overflow — reap and answer 413
                }
            }
        }

        return $data;
    }

}
