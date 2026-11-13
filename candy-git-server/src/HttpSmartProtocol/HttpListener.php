<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\HttpSmartProtocol;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * The socket half of smart HTTP: accepts TCP connections, buffers complete
 * HTTP/1.1 requests (Content-Length or chunked bodies — git's own client
 * POSTs its negotiation chunked), hands them to the synchronous router
 * {@see Server::handleRequest()}, and writes the buffered response back in
 * full — a bounded drain that keeps feeding a non-blocking socket until the
 * kernel window empties repeatedly or the client stalls — before closing.
 * One request per connection: every response carries `Connection: close`,
 * matching the router's own header.
 *
 * Built on the react/event-loop stream watch the StatsServer already uses
 * (same accept/read/close discipline, same loopback-default floor) — the
 * lib has no react/http or react/socket dependency and this file adds
 * neither. The router never blocks on a child longer than the bounded read
 * ladder in Git\StatelessRpc allows, so an accept tick always terminates.
 *
 * Every seat is bounded three ways: a connection cap (the newcomer is
 * refused while the house is full, mirroring the daemon), an IDLE deadline
 * (a client that stops talking loses its seat without ever becoming
 * readable — the periodic sweep is what notices), and an ABSOLUTE deadline
 * per request (a slow-drip client that keeps talking still dies). Body
 * sizes are capped by ONE knob: the router's maxRequestBodyBytes() — the
 * socket and the pack paths can never disagree about what is acceptable.
 * The sweep enforces the request deadline between ticks; it cannot preempt
 * work already inside dispatch(), so the response write carries its OWN
 * two-part bound (zero-progress stall grace + absolute ceiling in
 * {@see self::writeAll()}) and the router's child pumps are bounded by
 * the ladder in Git\StatelessRpc — no phase of a served request is
 * unbounded.
 *
 * The envelope stated above is per seat, and the honest arithmetic of the
 * whole house follows from it: 64 seats × a 256 MiB default body cap is a
 * ~16 GiB worst-case aggregate of buffered request bodies, and this class
 * carries NO aggregate memory budget. A client that dribbles 1 MiB and a
 * house of clients that each post a full-size pack are indistinguishable
 * here. An in-flight-bytes admission budget was considered and declined:
 * it is a resource-allocation policy that changes what the endpoint
 * serves under load, and that is a ruling to make, not to sneak into a
 * hardening step. Until such a ruling lands, http.max_pack_bytes is the
 * tuning lever for the aggregate (lower the per-seat cap, lower the
 * worst-case house) together with MAX_CLIENTS for the seat count.
 */
final class HttpListener
{
    /** Bytes of request head (line + headers) tolerated before refusal. */
    private const MAX_HEADER_BYTES = 65536;

    /** Silence tolerated on a half-finished request before the seat dies. */
    private const IDLE_TIMEOUT_SECONDS = 30.0;

    /** Total time tolerated for one request regardless of dribble. */
    private const ABSOLUTE_DEADLINE_SECONDS = 180.0;

    /** Concurrent half-finished or in-flight requests accepted. */
    private const MAX_CLIENTS = 64;

    /** How often the deadline sweep runs. */
    private const SWEEP_PERIOD_SECONDS = 0.25;

    /**
     * Framing slack over the decoded cap for chunked bodies: legitimate
     * git clients send megabyte-sized chunks (negligible framing), so a
     * raw buffer this far past the cap is hostile many-tiny-chunk padding
     * and is refused without draining it toward the cap forever.
     */
    private const CHUNKED_RAW_SLACK_BYTES = 1048576;

    /**
     * @var array<int, array{stream: resource, buffer: string, connected_at: float, last_activity: float, scan: array{pos: int, mode: string, want: int, decoded: int, done: bool}}>
     */
    private array $clients = [];

    /** @var resource|null */
    private $serverStream = null;

    private ?LoopInterface $loop = null;

    private ?TimerInterface $sweepTimer = null;

    public function __construct(
        private readonly Server $router,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 0,
        ?LoopInterface $loop = null,
        private readonly float $idleTimeoutSeconds = self::IDLE_TIMEOUT_SECONDS,
        private readonly float $absoluteDeadlineSeconds = self::ABSOLUTE_DEADLINE_SECONDS,
        private readonly int $maxClients = self::MAX_CLIENTS,
    ) {
        $this->loop = $loop;
    }

    /**
     * Bind and start accepting. Port 0 asks the kernel for an ephemeral
     * one — read it back through listenAddress(). Throws on bind failure
     * (fail-fast; a half-listening server is the scaffold sin this listener
     * exists to keep out).
     */
    public function start(): void
    {
        if ($this->serverStream !== null) {
            throw new \RuntimeException('HttpListener is already running');
        }
        $this->loop ??= Loop::get();

        $context = \stream_context_create(['socket' => ['so_reuseaddr' => true]]);
        $stream = @\stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            $context
        );
        if ($stream === false) {
            throw new \RuntimeException("HttpListener failed to bind tcp://{$this->host}:{$this->port}: {$errstr} ({$errno})");
        }
        \stream_set_blocking($stream, false);

        $this->serverStream = $stream;
        $this->loop->addReadStream($stream, fn () => $this->acceptConnection());
        // Idle clients never become readable, so readiness callbacks
        // cannot police the deadlines themselves — a slow tick sweeps.
        $this->sweepTimer = $this->loop->addPeriodicTimer(
            self::SWEEP_PERIOD_SECONDS,
            function (): void {
                $this->sweepDeadlines();
            }
        );
    }

    /** Stop accepting, drop the watches, and drop every client; idempotent. */
    public function stop(): void
    {
        if ($this->serverStream === null) {
            return;
        }
        if ($this->sweepTimer !== null) {
            $this->loop?->cancelTimer($this->sweepTimer);
            $this->sweepTimer = null;
        }
        $this->loop?->removeReadStream($this->serverStream);
        @\fclose($this->serverStream);
        $this->serverStream = null;
        foreach (\array_keys($this->clients) as $id) {
            $client = $this->clients[$id]['stream'];
            $this->loop?->removeReadStream($client);
            @\fclose($client);
            unset($this->clients[$id]);
        }
    }

    /** Bound address as "host:port" (with the concrete ephemeral port), or null when stopped. */
    public function listenAddress(): ?string
    {
        if ($this->serverStream === null) {
            return null;
        }
        $name = @\stream_socket_get_name($this->serverStream, false);

        return $name === false ? null : $name;
    }

    /** Seats currently held (accepted, request unfinished). */
    public function activeConnectionCount(): int
    {
        return \count($this->clients);
    }

    // -------------------------------------------------------------------------
    // Connection handling
    // -------------------------------------------------------------------------

    private function acceptConnection(): void
    {
        if ($this->serverStream === null) {
            return;
        }
        $client = @\stream_socket_accept($this->serverStream, 0);
        if ($client === false) {
            return;
        }

        if (\count($this->clients) >= $this->maxClients) {
            // Refuse the NEWCOMER; established transfers are never cut to
            // make room (the daemon's capacity policy, same reasoning).
            @\fclose($client);

            return;
        }

        \stream_set_blocking($client, false);
        $now = \microtime(true);

        $this->clients[(int) $client] = [
            'stream'        => $client,
            'buffer'        => '',
            'connected_at'  => $now,
            'last_activity' => $now,
            'scan'          => ['pos' => 0, 'mode' => 'size', 'want' => 0, 'decoded' => 0, 'done' => false],
        ];
        $this->loop?->addReadStream($client, fn () => $this->onClientReadable($client));
    }

    /** Close every seat whose idle or absolute deadline has expired. */
    private function sweepDeadlines(): void
    {
        $now = \microtime(true);
        foreach ($this->clients as $client) {
            if ($now - $client['last_activity'] > $this->idleTimeoutSeconds
                || $now - $client['connected_at'] > $this->absoluteDeadlineSeconds) {
                // Silent drop: nothing answerable arrived (or nothing
                // answerable ever will); there is no request to attach a
                // status line to on the idle path.
                $this->closeClient($client['stream']);
            }
        }
    }

    /** @param resource $client */
    private function onClientReadable($client): void
    {
        $id = (int) $client;
        if (!isset($this->clients[$id])) {
            return;
        }
        $data = @\fread($client, 8192);
        if ($data === false || ($data === '' && \feof($client))) {
            $this->closeClient($client);

            return;
        }

        $this->clients[$id]['last_activity'] = \microtime(true);
        $buffer = &$this->clients[$id]['buffer'];
        $buffer .= $data;

        $headEnd = \strpos($buffer, "\r\n\r\n");
        if ($headEnd === false) {
            if (\strlen($buffer) > self::MAX_HEADER_BYTES) {
                unset($buffer);
                $this->writeStatus($client, 431, 'Request Header Fields Too Large');
                $this->closeClient($client);

                return;
            }
            return; // head incomplete — keep buffering
        }

        $head = \substr($buffer, 0, $headEnd);
        $bodySoFar = \substr($buffer, $headEnd + 4);
        $needs = $this->expectedBodyBytes($head, $bodySoFar, $this->clients[$id]['scan']);
        if ($needs === self::BODY_TOO_LARGE) {
            unset($buffer);
            $this->writeStatus($client, 413, 'Payload Too Large');
            $this->closeClient($client);

            return;
        }
        if ($needs === self::BODY_MALFORMED_FRAMING) {
            unset($buffer);
            $this->writeStatus($client, 400, 'Bad Request');
            $this->closeClient($client);

            return;
        }
        if ($needs > 0) {
            unset($buffer);
            return; // body incomplete — keep buffering
        }

        unset($buffer);
        $this->dispatch($client, $head, $this->decodeBody($head, $bodySoFar));
        $this->closeClient($client);
    }

    /** Sentinel returned by expectedBodyBytes() when the body overruns the cap. */
    private const BODY_TOO_LARGE = -1;

    /**
     * Sentinel returned by expectedBodyBytes() when duplicate
     * Content-Length headers DISAGREE — the request cannot be framed at
     * all, so no body-state answer applies.
     */
    private const BODY_MALFORMED_FRAMING = -2;

    /**
     * Remaining body bytes for the given head + body seen so far:
     * 0 = complete, >0 = still needed, BODY_TOO_LARGE = over the cap,
     * BODY_MALFORMED_FRAMING = conflicting Content-Length duplicates.
     *
     * The chunked path advances a PERSISTED scan cursor instead of
     * re-decoding the whole buffer per read — re-scanning turned a
     * cap-sized upload into quadratic work for the listener.
     *
     * @param array{pos: int, mode: string, want: int, decoded: int, done: bool} $scan
     */
    private function expectedBodyBytes(string $head, string $body, array &$scan): int
    {
        $cap = $this->router->maxRequestBodyBytes();
        if ($this->hasHeader($head, 'transfer-encoding', 'chunked')) {
            if (\strlen($body) > $cap + self::CHUNKED_RAW_SLACK_BYTES) {
                // Hostile framing padding (tiny chunks): the raw buffer is
                // past any legitimate overhead before the decoded cap can
                // ever be reached by honest chunks.
                return self::BODY_TOO_LARGE;
            }
            self::advanceChunkScan($body, $scan);
            if ($scan['decoded'] > $cap) {
                return self::BODY_TOO_LARGE;
            }

            return $scan['done'] ? 0 : 1;
        }

        $length = null;
        foreach (\explode("\r\n", $head) as $line) {
            if (\preg_match('/^content-length:\s*(\d+)$/i', $line, $m) !== 1) {
                continue;
            }
            $value = (int) $m[1];
            if ($length !== null && $length !== $value) {
                // Request-smuggling bait: two claims about where the body
                // ends. Last-wins would silently adopt whichever framing a
                // downstream proxy may not have seen; refuse the request.
                return self::BODY_MALFORMED_FRAMING;
            }
            $length = $value;
        }
        $length ??= 0;
        if ($length > $cap) {
            return self::BODY_TOO_LARGE;
        }
        $have = \strlen($body);

        return $have < $length ? $length - $have : 0;
    }

    /**
     * Advance the chunked framing scan from its persisted cursor, $O(new
     * bytes) per read. Parity with decodeChunked()'s tolerance: a garbage
     * size line decodes as 0 (terminator), and trailing CRLF after the
     * last chunk closes the body.
     *
     * @param array{pos: int, mode: string, want: int, decoded: int, done: bool} $scan
     */
    private static function advanceChunkScan(string $body, array &$scan): void
    {
        while (!$scan['done']) {
            $length = \strlen($body);
            if ($scan['mode'] === 'size') {
                $eol = \strpos($body, "\r\n", $scan['pos']);
                if ($eol === false) {
                    return; // size line not complete yet
                }
                $size = \hexdec((string) \preg_replace('/[^0-9a-fA-F].*$/', '', \trim(\substr($body, $scan['pos'], $eol - $scan['pos']))));
                $scan['pos'] = $eol + 2;
                if ($size === 0) {
                    $scan['mode'] = 'term';
                    continue;
                }
                $scan['decoded'] += $size;
                $scan['mode']    = 'data';
                $scan['want']    = $size;
            } elseif ($scan['mode'] === 'data') {
                if ($length < $scan['pos'] + $scan['want'] + 2) {
                    return; // chunk (or its CRLF) not fully here yet
                }
                $scan['pos'] += $scan['want'] + 2;
                $scan['mode'] = 'size';
            } else { // 'term': the CRLF that follows the zero-size line
                if ($length < $scan['pos'] + 2) {
                    return;
                }
                $scan['pos']  += 2;
                $scan['done']  = true;
            }
        }
    }

    private function decodeBody(string $head, string $body): string
    {
        if ($this->hasHeader($head, 'transfer-encoding', 'chunked')) {
            return self::decodeChunked($body) ?? '';
        }

        return $body;
    }

    /**
     * Decode a complete chunked body; null while the stream still needs
     * more bytes. Malformed chunk sizes decode as empty data — the router
     * answers protocol errors in-band, this layer only frames.
     */
    private static function decodeChunked(string $raw): ?string
    {
        $out = '';
        $offset = 0;
        while (true) {
            $eol = \strpos($raw, "\r\n", $offset);
            if ($eol === false) {
                return null; // size line not complete yet
            }
            $size = \hexdec((string) \preg_replace('/[^0-9a-fA-F].*$/', '', \trim(\substr($raw, $offset, $eol - $offset))));
            $offset = $eol + 2;
            if ($size === 0) {
                // terminator: trailing CRLF after the last-chunk size line
                return \strlen($raw) >= $offset + 2 ? $out : null;
            }
            if (\strlen($raw) < $offset + $size + 2) {
                return null; // chunk (or its CRLF) not fully here yet
            }
            $out .= \substr($raw, $offset, $size);
            $offset += $size + 2;
        }
    }

    /**
     * True when the named header carries $value as one of its comma-list
     * tokens. Substring matching would let 'Transfer-Encoding: xchunkedz'
     * (or any header merely CONTAINING the word) arm the chunked framing
     * path, so the comparison is per-token and exact.
     */
    private function hasHeader(string $head, string $lowerName, string $value): bool
    {
        foreach (\explode("\r\n", $head) as $line) {
            $colon = \strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            if (\strtolower(\trim(\substr($line, 0, $colon))) !== $lowerName) {
                continue;
            }
            foreach (\explode(',', \substr($line, $colon + 1)) as $token) {
                if (\strtolower(\trim($token)) === $value) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Hand the assembled request to the router and write its response. */
    /** @param resource $client */
    private function dispatch($client, string $head, string $body): void
    {
        $lines = \explode("\r\n", $head);
        $requestLine = \array_shift($lines) ?? '';
        $parts = \explode(' ', $requestLine);

        $method = $parts[0] ?? 'GET';
        $target = $parts[1] ?? '/';
        $segments = \explode('?', $target, 2);
        $path = $segments[0];
        $query = $segments[1] ?? '';

        /** @var array<string, string> $headers */
        $headers = [];
        foreach ($lines as $line) {
            $colon = \strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $headers[\trim(\substr($line, 0, $colon))] = \trim(\substr($line, $colon + 1));
        }

        $peer = self::peerAddress($client);

        // One line per request on the operator log (STDERR, next to
        // the pump's child-failure lines). Before git 2.51 retired the
        // daemon, operators could watch `git daemon` connections arrive;
        // the HTTP listener is the replacement front door, so it carries
        // the same minimum visibility: who asked, what for, what answer,
        // how much wire, how long it took.
        $startedNs = \hrtime(true);
        $result = $this->router->handleRequest($method, $path, $query, $headers, $body, $peer);
        $elapsedMs = (\hrtime(true) - $startedNs) / 1_000_000;
        \fwrite(
            \STDERR,
            self::accessLogLine($peer, $method, $path, (int) $result['status'], \strlen((string) $result['body']), $elapsedMs) . "\n"
        );

        $reason = self::REASON_PHRASES[$result['status']] ?? 'Unknown';
        $wire = "HTTP/1.1 {$result['status']} {$reason}\r\n";
        foreach ($result['headers'] as $name => $value) {
            $wire .= "{$name}: {$value}\r\n";
        }
        $wire .= "\r\n" . $result['body'];
        // The socket is non-blocking, so a
        // bare fwrite hands the kernel AT MOST one send-buffer of bytes and
        // the rest of the response would be silently lost on close — real
        // `git clone` of any repo whose pack exceeds the kernel buffers died
        // with curl 18 "transfer closed with N bytes remaining". writeAll()
        // keeps feeding the socket until it is drained or the client stalls.
        self::writeAll($client, $wire);
    }

    /**
     * Push $bytes through a non-blocking stream, retrying every partial
     * accept until the whole payload is handed to the kernel — or until the
     * client makes zero progress for $stallSeconds, or the write outlives
     * the absolute ceiling, or the socket errors. Returns the byte count
     * actually written (== strlen($bytes) on success); the caller's close
     * path is identical either way because a half-delivered response is
     * already protocol-dead to the client.
     *
     * The progress re-arm bounds only the ZERO-progress grace: a client
     * that accepts one byte every few seconds used to hold the seat — and,
     * in the serial-loop build, every other client with it — for as long as
     * it liked. The absolute ceiling (start + $absoluteSeconds, monotonic)
     * is the same shape GitDaemon::drainWrite and StdioPipe's hard bound
     * already enforce, so no response write is unbounded anywhere in the
     * lib. Sweeping deadlines cannot preempt a write already in flight
     * (single-threaded dispatch), which is exactly why the bound lives here.
     *
     * @param resource $stream
     */
    private static function writeAll($stream, string $bytes, ?float $stallSeconds = null, ?float $absoluteSeconds = null): int
    {
        $stall    = $stallSeconds ?? self::WRITE_STALL_SECONDS;
        $absolute = $absoluteSeconds ?? self::WRITE_ABSOLUTE_SECONDS;
        $total    = \strlen($bytes);
        $offset   = 0;
        $absoluteNs = \hrtime(true) + (int) ($absolute * 1e9);
        $giveUp   = \microtime(true) + $stall;

        while ($offset < $total) {
            if (\microtime(true) >= $giveUp || \hrtime(true) >= $absoluteNs) {
                return $offset; // wedged (zero progress past the grace) or dribbling (past the ceiling)
            }
            // Hand the kernel ONE chunk per feed: substr($bytes, $offset)
            // re-sliced the whole remainder on every partial accept, so a
            // max-size response on a slow peer did O(n²/chunk) byte copying
            // inside the write loop (the same quadratic-writer family as
            // the fleet's streaming holds). The slice is also what keeps a
            // huge $bytes from being copied twice per attempt.
            $written = @\fwrite($stream, \substr($bytes, $offset, self::WRITE_CHUNK_BYTES));
            if ($written === false) {
                return $offset; // socket error / reset by peer
            }
            if ($written > 0) {
                $offset += $written;
                $giveUp = \microtime(true) + $stall; // progress re-arms the window, never past the ceiling
                continue;
            }
            // 0-byte accept: kernel buffer full (or EINTR-ish moment). Wait
            // for writability in short ticks so the stall deadline stays
            // authoritative even if the socket never turns writable.
            $read = $except = null;
            $write = [$stream];
            @\stream_select($read, $write, $except, 0, 100_000); // 100 ms tick
        }

        return $offset;
    }

    /** How long a response write may make ZERO progress on a client socket. */
    private const WRITE_STALL_SECONDS = 10.0;

    /** Bytes handed to one fwrite feed by writeAll() (the lib's write quantum). */
    private const WRITE_CHUNK_BYTES = 65536;

    /**
     * Absolute ceiling for one response write regardless of trickle
     * progress — mirrors StdioPipe::HARD_SECONDS, the per-request bound
     * the daemon pump already carries. A seat that keeps the house's
     * kernel buffers fed forever is still only a seat for this long.
     */
    private const WRITE_ABSOLUTE_SECONDS = 300.0;

    /** @param resource $client */
    private static function peerAddress($client): ?string
    {
        $name = @\stream_socket_get_name($client, true);
        if ($name === false || $name === '') {
            return null;
        }
        if ($name[0] === '[') {
            // IPv6: stream_socket_get_name() formats peers as '[addr]:port'.
            // Hand out the BARE address — a bracketed literal never matches
            // inet_pton()-derived CIDR bounds downstream, and every other
            // consumer (access log, allowlist prose) wants the plain form.
            $close = \strpos($name, ']');
            if ($close !== false) {
                return \substr($name, 1, $close - 1);
            }
        }
        $colon = \strrpos($name, ':');

        return $colon === false ? $name : \substr($name, 0, $colon);
    }

    /**
     * One line per served request, shaped after git daemon's log style:
     * `access <peer> <METHOD> <path> <status> <bytes> <elapsed>`.
     */
    private static function accessLogLine(?string $peer, string $method, string $path, int $status, int $bytes, float $elapsedMs): string
    {
        return \sprintf(
            'access %s %s %s %d %d %.1fms',
            $peer ?? '-',
            $method,
            $path,
            $status,
            $bytes,
            $elapsedMs
        );
    }

    /** @param resource $client */
    private function writeStatus($client, int $status, string $reason): void
    {
        $body = "{$status} {$reason}\n";
        // Same full-buffer drain as dispatch(): small bodies almost always
        // fit one kernel window, but "almost" is what the byte-fidelity
        // review proved unprovable from the inside.
        self::writeAll($client, "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: text/plain\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "Server: CandyGitServer/1.0\r\n\r\n" . $body);
    }

    /** @param resource $client */
    private function closeClient($client): void
    {
        $id = (int) $client;
        if (isset($this->clients[$id])) {
            $this->loop?->removeReadStream($this->clients[$id]['stream']);
            @\fclose($this->clients[$id]['stream']);
            unset($this->clients[$id]);
        }
    }

    private const REASON_PHRASES = [
        200 => 'OK',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        408 => 'Request Timeout',
        411 => 'Length Required',
        413 => 'Payload Too Large',
        422 => 'Unprocessable Entity',
        504 => 'Gateway Timeout',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
    ];
}
