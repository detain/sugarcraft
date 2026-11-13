<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\HttpSmartProtocol;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\GitServer\Support\HttpWire;
use SugarCraft\GitServer\Support\WorkerFork;

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
 * neither.
 *
 * Concurrency is constructor-chosen (8b redesign): WITHOUT a
 * {@see WorkerFork} the listener runs every seat on the caller's event
 * loop, one request at a time — a bounded but strictly SERIAL house: a
 * served request cannot be preempted mid-dispatch, so a long push delays
 * every other client behind it (finding 006's disclosure). WITH a pool,
 * each accepted connection is handed to a forked worker that owns it to
 * completion and exits, so one wedged request stalls nobody; the parent
 * keeps only accept, capacity, and reap duties. Windows (no fork) and
 * non-pool constructions stay on the serial loop — the degraded mode the
 * bin names out loud at startup, never a silent surprise.
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
 * {@see HttpWire::writeAll()}) and the router's child pumps are bounded by
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
    /** Header cap + write quantum now live in one place — see Support/HttpWire. */
    private const MAX_HEADER_BYTES = HttpWire::MAX_HEADER_BYTES;

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

    /**
     * Worker-mode state (8b redesign): the pool the listener hands every
     * accepted connection to, the child-side flag that detaches teardown
     * from the (never-running-in-child) event loop, and this process's own
     * connection start stamp as seen by a worker.
     */
    private ?WorkerFork $workers = null;

    private bool $inWorker = false;

    public function __construct(
        private readonly Server $router,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 0,
        ?LoopInterface $loop = null,
        private readonly float $idleTimeoutSeconds = self::IDLE_TIMEOUT_SECONDS,
        private readonly float $absoluteDeadlineSeconds = self::ABSOLUTE_DEADLINE_SECONDS,
        private readonly int $maxClients = self::MAX_CLIENTS,
        ?WorkerFork $workers = null,
    ) {
        $this->loop = $loop;
        $this->workers = $workers;
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

    /** Stop accepting, drop the watches, end every worker, and drop every client; idempotent. */
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
        // 8b: the pool's workers own their connections; terminating them
        // (TERM, bounded poll, KILL) is what releases the seats. Each
        // worker also carries a parent-death pipe, so even a stop() that
        // never runs (SIGKILL to the parent) ends them — see WorkerFork.
        $this->workers?->terminateAll();
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

    /** Seats currently held (accepted, request unfinished), worker-owned included. */
    public function activeConnectionCount(): int
    {
        return \count($this->clients) + ($this->workers?->live() ?? 0);
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
            // 047: the framing decision parses once (head is complete
            // exactly once) and rides the seat as data, the same
            // cursor-on-the-row shape as the chunk scan above.
            'framing'       => null,
        ];

        if ($this->workers !== null) {
            // 8b worker-per-request: hand the freshly registered seat to a
            // forked owner. The bookkeeping entry above is COPIED into the
            // child by the fork, which is why registration precedes it.
            // After a successful fork the parent closes its own client
            // copy — keeping it would hold the connection's EOF hostage
            // to the parent's lifetime and the port past a stop(). The
            // listen socket and every OTHER seat travel in $foreign so the
            // child closes them immediately: a worker must never keep the
            // accept queue or a neighbour's stream alive.
            $foreign = [];
            if ($this->serverStream !== null) {
                $foreign[] = $this->serverStream;
            }
            foreach ($this->clients as $id => $seat) {
                if ($id !== (int) $client) {
                    $foreign[] = $seat['stream'];
                }
            }
            $spawned = $this->workers->fork(
                function ($client): bool {
                    return $this->workerTick($client);
                },
                $client,
                $foreign
            );
            $entry = $this->clients[(int) $client] ?? null;
            unset($this->clients[(int) $client]);
            if (!$spawned) {
                // Pool full / fork failed: refuse the newcomer exactly like
                // the capacity arm above — established seats are never cut.
                if ($entry !== null) {
                    @\fclose($entry['stream']);
                }

                return;
            }
            @\fclose($client);

            return;
        }

        $this->loop?->addReadStream($client, fn () => $this->onClientReadable($client));
    }

    /** Close every seat whose idle or absolute deadline has expired. */
    private function sweepDeadlines(): void
    {
        // Worker seats sweep themselves (the pool alarm is the outer
        // bound); the parent's sweep keeps their bookkeeping current and
        // frees their seats the moment they exit.
        $this->workers?->reap();
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
        $this->pumpClient($client);
    }

    /**
     * The loop-free seat pump: one readable event buffers, frames, and —
     * when the request completes — dispatches and closes. Returns whether
     * the seat is STILL connected (true = keep waiting for more bytes).
     *
     * @param resource $client
     */
    private function pumpClient($client): bool
    {
        $id = (int) $client;
        // 112: a matching seat NUMBER is not a matching CONNECTION — PHP
        // recycles resource ids, and a stale readable callback whose old
        // stream got closed under it would otherwise fread a dead handle,
        // then closeClient() the NEWER socket sharing the number (the
        // innocent seat's stream IS what closeClient acts through). The
        // identity check makes stale callbacks inert.
        if (($this->clients[$id]['stream'] ?? null) !== $client) {
            return false;
        }
        // The lib's 64 KiB read quantum, shared with the write helper
        // through HttpWire::CHUNK_BYTES (047): 8 KiB slices on a
        // cap-sized push bought the pump ~32x more ticks for zero
        // smaller worst-case buffers.
        $data = @\fread($client, HttpWire::CHUNK_BYTES);
        if ($data === false || ($data === '' && \feof($client))) {
            $this->closeClient($client);

            return false;
        }
        if ($data !== '') {
            // Activity means BYTES. The worker's timeout ticks pump this
            // same method on empty select-expiries; refreshing last_activity
            // there would resurrect a silent seat forever and defeat the
            // idle deadline — which is exactly what a worker enforces for
            // itself, the parent's sweep not running in its process.
            $this->clients[$id]['last_activity'] = \microtime(true);
        }
        $buffer = &$this->clients[$id]['buffer'];
        $buffer .= $data;

        $headEnd = \strpos($buffer, "\r\n\r\n");
        if ($headEnd === false) {
            if (\strlen($buffer) > self::MAX_HEADER_BYTES) {
                unset($buffer);
                $this->writeStatus($client, 431, 'Request Header Fields Too Large');
                $this->closeClient($client);

                return false;
            }
            return true; // head incomplete — keep buffering
        }

        $head = \substr($buffer, 0, $headEnd);
        $bodySoFar = \substr($buffer, $headEnd + 4);
        $framing = $this->clients[$id]['framing'] ?? null;
        if ($framing === null) {
            // 047: header parsing lands exactly once per seat. The old
            // shape re-split and re-regexed the whole head on every read
            // tick, riding ~2 GiB of redundant analysis on a cap-sized
            // push; the decision is now persisted on the client row in
            // the same cursor-on-the-seat shape the chunk scan already
            // used (and forks with it).
            $framing = $this->parseBodyFraming($head);
            $this->clients[$id]['framing'] = $framing;
        }
        $needs = $this->expectedBodyBytes($framing, $bodySoFar, $this->clients[$id]['scan']);
        if ($needs === self::BODY_TOO_LARGE) {
            unset($buffer);
            $this->writeStatus($client, 413, 'Payload Too Large');
            $this->closeClient($client);

            return false;
        }
        if ($needs === self::BODY_MALFORMED_FRAMING) {
            unset($buffer);
            $this->writeStatus($client, 400, 'Bad Request');
            $this->closeClient($client);

            return false;
        }
        if ($needs > 0) {
            unset($buffer);
            return true; // body incomplete — keep buffering
        }

        unset($buffer);
        $this->dispatch($client, $head, $this->decodeBody($framing, $bodySoFar));
        $this->closeClient($client);

        return false;
    }

    /**
     * The worker child's view of the pump (8b): identical framing through
     * {@see pumpClient()}, plus the seat's own deadline enforcement — the
     * parent's sweep timer does not run inside a worker (there is no
     * event loop to run it), and WorkerFork's alarm ceiling is only the
     * OUTER bound on one request. Returns false (connection finished) the
     * way the pool expects.
     *
     * @param resource $client
     */
    private function workerTick($client): bool
    {
        // The inherited loop is the PARENT's: never add, remove, or run
        // anything on it from here — closeClient consults this flag.
        $this->inWorker = true;

        $id = (int) $client;
        if (($this->clients[$id]['stream'] ?? null) !== $client) {
            // 112: same number is not the same connection (pumpClient's
            // reasoning); a worker never services a recycled-id seat.
            return false;
        }
        $now = \microtime(true);
        if ($now - $this->clients[$id]['last_activity'] > $this->idleTimeoutSeconds
            || $now - $this->clients[$id]['connected_at'] > $this->absoluteDeadlineSeconds
        ) {
            // Silent drop, the sweep's semantics carried into the seat:
            // nothing answerable arrived (or never will).
            $this->closeClient($client);

            return false;
        }

        return $this->pumpClient($client);
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
     * Parse the body-framing decision from a complete header block —
     * ONCE per seat (047). Precedence and semantics are unchanged from
     * the old per-tick shape: an explicit chunked TE wins, otherwise
     * the single Content-Length claim, otherwise disagreement between
     * duplicates is malformed (refused), otherwise zero-length.
     *
     * @return array{mode: 'chunked'|'length'|'malformed', length?: int}
     */
    private function parseBodyFraming(string $head): array
    {
        if ($this->hasHeader($head, 'transfer-encoding', 'chunked')) {
            return ['mode' => 'chunked'];
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
                return ['mode' => 'malformed'];
            }
            $length = $value;
        }

        return ['mode' => 'length', 'length' => $length ?? 0];
    }

    /**
     * Remaining body bytes for a persisted framing decision plus the
     * body seen so far: 0 = complete, >0 = still needed,
     * BODY_TOO_LARGE = over the cap, BODY_MALFORMED_FRAMING =
     * conflicting Content-Length duplicates.
     *
     * Consumes the {@see parseBodyFraming()} verdict instead of the raw
     * head: the header must not be re-split and re-regexed on every
     * read tick (047). The chunked path additionally advances a
     * PERSISTED scan cursor instead of re-decoding the whole buffer per
     * read — re-scanning turned a cap-sized upload into quadratic work
     * for the listener.
     *
     * @param array{mode: string, length?: int} $framing
     * @param array{pos: int, mode: string, want: int, decoded: int, done: bool} $scan
     */
    private function expectedBodyBytes(array $framing, string $body, array &$scan): int
    {
        $cap = $this->router->maxRequestBodyBytes();
        if ($framing['mode'] === 'chunked') {
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

        if ($framing['mode'] === 'malformed') {
            return self::BODY_MALFORMED_FRAMING;
        }

        $length = $framing['length'];
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

    /**
     * Decode the completed body through the seat's persisted framing
     * decision (047): chunked streams run the full decoder, every other
     * mode carries its bytes verbatim. Sentinels never reach this point;
     * the pump refuses those requests before dispatch.
     *
     * @param array{mode: string, length?: int} $framing
     */
    private function decodeBody(array $framing, string $body): string
    {
        if ($framing['mode'] === 'chunked') {
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
        // with curl 18 "transfer closed with N bytes remaining".
        // HttpWire::writeAll() keeps feeding the socket until it is drained
        // or the client stalls (shared with the stats transport, S13).
        HttpWire::writeAll($client, $wire);
    }

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
        HttpWire::writeAll($client, "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: text/plain\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "Server: CandyGitServer/1.0\r\n\r\n" . $body);
    }

    /** @param resource $client */
    private function closeClient($client): void
    {
        $id = (int) $client;
        // Only the seat that OWNS this exact stream may be closed through
        // it (112): closeClient acts on the seat's stream, so a recycled-id
        // guest closing the door would tear down an innocent connection.
        if (isset($this->clients[$id]) && $this->clients[$id]['stream'] === $client) {
            if (!$this->inWorker) {
                // The loop belongs to the parent process; a worker child
                // must never touch the inherited watch structures (the uv /
                // select internals were not forked with them).
                $this->loop?->removeReadStream($this->clients[$id]['stream']);
            }
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
