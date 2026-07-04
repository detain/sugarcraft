<?php

declare(strict_types=1);

namespace SugarCraft\Serve;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * Minimal HTTP stats endpoint on `stats.listen_addr` (plan item 7.6).
 *
 * Serves the {@see Stats} counters as JSON: `GET /` or `GET /stats`
 * returns `{"uptime_seconds": …, "connections": …, …}`. Runs on a
 * ReactPHP event loop only (same transport pattern as
 * `GitDaemon::serveAsync()`); there is deliberately no blocking mode —
 * a stats endpoint that monopolises the process would starve the very
 * servers it reports on, so hosts running only the blocking
 * `GitDaemon::serve()` loop have no stats server (documented in the
 * README).
 *
 * Mirrors charmbracelet/soft-serve's stats server on
 * `stats.listen_addr` (upstream serves Prometheus metrics; candy-serve
 * serves a minimal JSON counter set).
 */
final class StatsServer
{
    private Config $config;
    private ?Stats $stats;

    /** @var resource|null */
    private $serverStream = null;

    private ?LoopInterface $loop = null;

    /** @var array<int, array{stream: resource, buffer: string}> keyed by (int) $stream */
    private array $clients = [];

    public function __construct(Config $config, ?Stats $stats = null)
    {
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

        $this->loop?->removeReadStream($this->serverStream);
        @\fclose($this->serverStream);
        $this->serverStream = null;
        $this->loop = null;
    }

    public function isRunning(): bool
    {
        return $this->serverStream !== null;
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
        $client = @\stream_socket_accept($this->serverStream, 0);
        if ($client === false) {
            return;
        }
        \stream_set_blocking($client, false);

        $this->clients[(int) $client] = ['stream' => $client, 'buffer' => ''];
        $this->loop->addReadStream($client, fn () => $this->onClientReadable($client));
    }

    /** @param resource $client */
    private function onClientReadable($client): void
    {
        $data = @\fread($client, 8192);
        if ($data === false || ($data === '' && \feof($client))) {
            $this->closeClient($client);
            unset($this->clients[(int) $client]);

            return;
        }

        $buffer = &$this->clients[(int) $client]['buffer'];
        $buffer .= $data;

        // Stats requests are header-only GETs — respond as soon as the
        // request head is complete.
        if (!\str_contains($buffer, "\r\n\r\n") && !\str_contains($buffer, "\n\n")) {
            return;
        }

        $this->respond($client, $buffer);
        $this->closeClient($client);
        unset($this->clients[(int) $client]);
    }

    /** @param resource $client */
    private function respond($client, string $request): void
    {
        $requestLine = \strtok($request, "\r\n") ?: '';
        $parts = \explode(' ', $requestLine);
        $method = $parts[0] ?? '';
        $path = \explode('?', $parts[1] ?? '/', 2)[0];

        $stats = $this->stats ?? Stats::getInstance();

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
            . "Server: CandyServe/1.0\r\n\r\n";
        @\fwrite($client, $headers . $body);
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
     * @return array{string, int}
     */
    private function listenHostPort(): array
    {
        $parts = \explode(':', $this->config->statsListenAddr);
        $host = $parts[0] ?: '0.0.0.0';
        $port = isset($parts[1]) ? (int) $parts[1] : 23233;

        return [$host, $port];
    }
}
