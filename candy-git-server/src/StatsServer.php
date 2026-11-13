<?php

declare(strict_types=1);

namespace SugarCraft\GitServer;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\GitServer\Support\HttpWire;

/**
 * Minimal HTTP stats endpoint on `stats.listen_addr`.
 *
 * Serves the {@see Stats} counters as JSON: `GET /`, `GET /stats` and
 * `GET /stats.json` return
 * `{"uptime_seconds": …, "connections": …, …}`. Runs on a
 * ReactPHP event loop only (same transport pattern as
 * `GitDaemon::serveAsync()`); there is deliberately no blocking mode —
 * a stats endpoint that monopolises the process would starve the very
 * servers it reports on, so hosts running only the blocking
 * `GitDaemon::serve()` loop have no stats server (documented in the
 * README).
 *
 * Mirrors charmbracelet/soft-serve's stats server on
 * `stats.listen_addr` (upstream serves Prometheus metrics; candy-git-server
 * serves a minimal JSON counter set).
 *
 * Every seat is bounded three ways, ported from the {@see HttpListener}
 * hardening (same slowloris class): a connection cap (the newcomer is
 * refused while the house is full), an IDLE deadline (a client that stops
 * talking loses its seat without ever becoming readable — the periodic
 * sweep notices), and an ABSOLUTE deadline per request (a slow-drip client
 * that keeps talking still dies). A request head longer than the header cap
 * is refused with 431 before it can grow the buffer further. The
 * loopback-default bind is a mitigation, not one of these bounds.
 */
final class StatsServer
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

    private Config $config;
    private ?Stats $stats;

    /** @var resource|null */
    private $serverStream = null;

    private ?LoopInterface $loop = null;

    private ?TimerInterface $sweepTimer = null;

    /** @var array<int, array{stream: resource, buffer: string, connected_at: float, last_activity: float}> keyed by (int) $stream */
    private array $clients = [];

    public function __construct(
        Config $config,
        ?Stats $stats = null,
        private readonly float $idleTimeoutSeconds = self::IDLE_TIMEOUT_SECONDS,
        private readonly float $absoluteDeadlineSeconds = self::ABSOLUTE_DEADLINE_SECONDS,
        private readonly int $maxClients = self::MAX_CLIENTS,
    ) {
        $this->config = $config;
        $this->stats = $stats;
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Bind `stats.listen_addr` and start answering on the event loop.
     *
     * @throws \RuntimeException if already running or the socket cannot be bound
     */
    public function start(?LoopInterface $loop = null): void
    {
        if ($this->serverStream !== null) {
            throw new \RuntimeException('StatsServer is already running; call stop() first.');
        }

        $this->loop = $loop ?? Loop::get();

        [$host, $port] = $this->listenHostPort();
        $errno = 0;
        $errstr = '';
        $stream = @\stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if ($stream === false) {
            $this->loop = null;
            throw new \RuntimeException(
                "Failed to create stats server socket on {$this->config->statsListenAddr}: {$errstr}"
            );
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

    /** Tear down every loop registration and close all sockets. Idempotent. */
    public function stop(): void
    {
        if ($this->serverStream === null) {
            return;
        }

        foreach ($this->clients as $client) {
            $this->closeClient($client['stream']);
        }
        $this->clients = [];

        if ($this->sweepTimer !== null) {
            $this->loop?->cancelTimer($this->sweepTimer);
            $this->sweepTimer = null;
        }
        $this->loop?->removeReadStream($this->serverStream);
        @\fclose($this->serverStream);
        $this->serverStream = null;
        $this->loop = null;
    }

    public function isRunning(): bool
    {
        return $this->serverStream !== null;
    }

    /** Seats currently held (accepted, request unfinished). */
    public function activeConnectionCount(): int
    {
        return \count($this->clients);
    }

    /** Actual bound address (useful when the configured port is 0). */
    public function listenAddress(): ?string
    {
        if ($this->serverStream === null) {
            return null;
        }
        $name = @\stream_socket_get_name($this->serverStream, false);

        return $name === false ? null : $name;
    }

    // -------------------------------------------------------------------------
    // Request handling
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
            // make room (the listener's capacity policy, same reasoning).
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
        ];
        $this->loop->addReadStream($client, fn () => $this->onClientReadable($client));
    }

    /** Close every seat whose idle or absolute deadline has expired. */
    private function sweepDeadlines(): void
    {
        $now = \microtime(true);
        foreach ($this->clients as $id => $client) {
            if ($now - $client['last_activity'] > $this->idleTimeoutSeconds
                || $now - $client['connected_at'] > $this->absoluteDeadlineSeconds) {
                // Silent drop: nothing answerable arrived (or nothing
                // answerable ever will); there is no request to attach a
                // status line to on the idle path.
                $this->closeClient($client['stream']);
                unset($this->clients[$id]);
            }
        }
    }

    /** @param resource $client */
    private function onClientReadable($client): void
    {
        $id = (int) $client;
        // 112: the number alone is not the connection — a callback for a
        // closed-and-recycled stream must not fread a dead handle and
        // then unset the innocent seat that inherited its id.
        if (($this->clients[$id]['stream'] ?? null) !== $client) {
            return;
        }
        $data = @\fread($client, 8192);
        if ($data === false || ($data === '' && \feof($client))) {
            $this->closeClient($client);
            unset($this->clients[$id]);

            return;
        }

        $this->clients[$id]['last_activity'] = \microtime(true);
        $buffer = &$this->clients[$id]['buffer'];
        $buffer .= $data;

        // Stats requests are header-only GETs — respond as soon as the
        // request head is complete.
        if (!\str_contains($buffer, "\r\n\r\n") && !\str_contains($buffer, "\n\n")) {
            if (\strlen($buffer) > self::MAX_HEADER_BYTES) {
                unset($buffer);
                $this->writeResponse($client, 431, 'Request Header Fields Too Large', '{"error":"request header fields too large"}');
                $this->closeClient($client);
                unset($this->clients[$id]);

                return;
            }

            return;
        }

        $this->respond($client, $buffer);
        $this->closeClient($client);
        unset($this->clients[$id]);
    }

    /** @param resource $client */
    private function respond($client, string $request): void
    {
        $requestLine = \strtok($request, "\r\n") ?: '';
        $parts = \explode(' ', $requestLine);
        $method = $parts[0] ?? '';
        $path = \explode('?', $parts[1] ?? '/', 2)[0];

        $stats = $this->stats ?? Stats::instance();

        if ($method !== 'GET') {
            $this->writeResponse($client, 405, 'Method Not Allowed', '{"error":"method not allowed"}');

            return;
        }

        if ($path !== '/' && $path !== '/stats' && $path !== '/stats.json') {
            $this->writeResponse($client, 404, 'Not Found', '{"error":"not found"}');

            return;
        }

        $this->writeResponse($client, 200, 'OK', $stats->toJson());
    }

    /** @param resource $client */
    private function writeResponse($client, int $status, string $reason, string $body): void
    {
        $headers = "HTTP/1.1 {$status} {$reason}\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "Server: CandyGitServer/1.0\r\n\r\n";
        // 033: this used to be ONE @fwrite — on a non-blocking socket that
        // hands off at most one send window, so a stats body past the
        // kernel buffer (an operator with hundreds of repos packed the
        // JSON) arrived truncated behind a matching Content-Length. Same
        // defect shape the listener was cured of; same drain, one source
        // (HttpWire, S13).
        HttpWire::writeAll($client, $headers . $body);
    }

    /** @param resource $client */
    private function closeClient($client): void
    {
        $this->loop?->removeReadStream($client);
        @\fclose($client);
    }

    /**
     * Parse `stats.listen_addr` (":23233" / "127.0.0.1:23233") into host + port.
     *
     * Security floor: an omitted host (e.g. the default ":23233") binds to
     * loopback (127.0.0.1), NOT the wildcard 0.0.0.0. The stats endpoint is
     * unauthenticated, so it must not be network-exposed by default — exposing
     * it more widely has to be a deliberate choice (an explicit host such as
     * "0.0.0.0:23233" in the config).
     *
     * @return array{string, int}
     */
    private function listenHostPort(): array
    {
        $parts = \explode(':', $this->config->statsListenAddr);
        $host = $parts[0] !== '' ? $parts[0] : '127.0.0.1';
        $port = isset($parts[1]) ? (int) $parts[1] : 23233;

        return [$host, $port];
    }
}
