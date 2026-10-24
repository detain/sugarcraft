<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\HttpSmartProtocol;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * The socket half of smart HTTP: accepts TCP connections, buffers complete
 * HTTP/1.1 requests (Content-Length or chunked bodies — git's own client
 * POSTs its negotiation chunked), hands them to the synchronous router
 * {@see Server::handleRequest()}, and writes the buffered response back
 * before closing. One request per connection: every response carries
 * `Connection: close`, matching the router's own header.
 *
 * Built on the react/event-loop stream watch the StatsServer already uses
 * (same accept/read/close discipline, same loopback-default floor) — the
 * lib has no react/http or react/socket dependency and this file adds
 * neither. The router never blocks on a child longer than the bounded read
 * ladder in Git\StatelessRpc allows, so an accept tick always terminates.
 *
 * Added by r1024/githttp (owner ruling 1b): smart-HTTP READ path only.
 */
final class HttpListener
{
    /** Bytes of request head (line + headers) tolerated before refusal. */
    private const MAX_HEADER_BYTES = 65536;

    /** Total request body cap — the router caps packs tighter; this is the socket-level guard. */
    private const MAX_BODY_BYTES = 33554432; // 32 MiB

    /** @var array<int, array{stream: resource, buffer: string}> */
    private array $clients = [];

    /** @var resource|null */
    private $serverStream = null;

    private ?LoopInterface $loop = null;

    public function __construct(
        private readonly Server $router,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 0,
        ?LoopInterface $loop = null,
    ) {
        $this->loop = $loop;
    }

    /**
     * Bind and start accepting. Port 0 asks the kernel for an ephemeral
     * one — read it back through listenAddress(). Throws on bind failure
     * (fail-fast; a half-listening server is the scaffold sin this whole
     * lane exists to keep out).
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
    }

    /** Stop accepting and drop the (single) listen watch; idempotent. */
    public function stop(): void
    {
        if ($this->serverStream === null) {
            return;
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
        \stream_set_blocking($client, false);

        $this->clients[(int) $client] = ['stream' => $client, 'buffer' => ''];
        $this->loop?->addReadStream($client, fn () => $this->onClientReadable($client));
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
        $needs = $this->expectedBodyBytes($head, $bodySoFar);
        if ($needs === self::BODY_TOO_LARGE) {
            unset($buffer);
            $this->writeStatus($client, 413, 'Payload Too Large');
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
     * Remaining body bytes for the given head + body seen so far:
     * 0 = complete, >0 = still needed, BODY_TOO_LARGE = over the cap.
     */
    private function expectedBodyBytes(string $head, string $body): int
    {
        if ($this->hasHeader($head, 'transfer-encoding', 'chunked')) {
            $decoded = self::decodeChunked($body);
            if ($decoded === null) {
                return \strlen($body) > self::MAX_BODY_BYTES ? self::BODY_TOO_LARGE : 1;
            }
            return 0;
        }

        $length = 0;
        foreach (\explode("\r\n", $head) as $line) {
            if (\preg_match('/^content-length:\s*(\d+)$/i', $line, $m) === 1) {
                $length = (int) $m[1];
            }
        }
        if ($length > self::MAX_BODY_BYTES) {
            return self::BODY_TOO_LARGE;
        }
        $have = \strlen($body);

        return $have < $length ? $length - $have : 0;
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
            $size = \hexdec(\trim(\substr($raw, $offset, $eol - $offset)));
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

    private function hasHeader(string $head, string $lowerName, string $value): bool
    {
        foreach (\explode("\r\n", $head) as $line) {
            $colon = \strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            if (\strtolower(\trim(\substr($line, 0, $colon))) === $lowerName
                && \str_contains(\strtolower(\substr($line, $colon + 1)), $value)) {
                return true;
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

        $result = $this->router->handleRequest($method, $path, $query, $headers, $body, $peer);

        $reason = self::REASON_PHRASES[$result['status']] ?? 'Unknown';
        $wire = "HTTP/1.1 {$result['status']} {$reason}\r\n";
        foreach ($result['headers'] as $name => $value) {
            $wire .= "{$name}: {$value}\r\n";
        }
        $wire .= "\r\n" . $result['body'];
        @\fwrite($client, $wire);
    }

    /** @param resource $client */
    private static function peerAddress($client): ?string
    {
        $name = @\stream_socket_get_name($client, true);
        if ($name === false || $name === '') {
            return null;
        }
        $colon = \strrpos($name, ':');

        return $colon === false ? $name : \substr($name, 0, $colon);
    }

    /** @param resource $client */
    private function writeStatus($client, int $status, string $reason): void
    {
        $body = "{$status} {$reason}\n";
        @\fwrite($client, "HTTP/1.1 {$status} {$reason}\r\n"
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
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        411 => 'Length Required',
        413 => 'Payload Too Large',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
    ];
}
