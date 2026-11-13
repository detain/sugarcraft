<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Fixture;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use React\Socket\SocketServer;

/**
 * A loopback stand-in for the Discord media CDN (§7.8 fetchBinary caps).
 * It speaks real HTTP/1.1 over a real socket — the react/http parsers in
 * Rest stay genuinely exercised — while the knobs decide how honest the
 * wire is: `declaredLength` does or does not announce a Content-Length,
 * and the body dribbles from a timer so a client that aborts at its cap
 * can be MEASURED (`bodyBytesSent` counts bytes the server actually
 * managed to write before the connection died). A lying or absent
 * header plus an endless body is exactly the hostile-CDN shape the
 * streaming cap must survive, and no real network is ever touched:
 * `connector()` re-routes any dial (the https + media-host gate in
 * fetchBinary stays live and unmodified in front of it) here.
 */
final class FakeMediaCdn
{
    /** Content-Length header value; null omits the header entirely. */
    public ?int $declaredLength = null;

    /** Total body bytes this CDN would stream if nobody stops it. */
    public int $streamBytes = 0;

    /** Body bytes pushed per timer tick (one 'data' event each). */
    public int $streamChunkBytes = 1024;

    /** Delay before the FIRST body tick — lets a header-time abort see zero writes. */
    public int $streamDelayMs = 0;

    /** Body bytes actually written by the server (abort-bound proof). */
    public int $bodyBytesSent = 0;

    /** Requests received (request line fully terminated). */
    public int $requests = 0;

    /** Header-complete responses written. */
    public int $headersSent = 0;

    private ?SocketServer $server = null;

    public function __construct(private readonly LoopInterface $loop) {}

    public function listen(): int
    {
        $server = new SocketServer('127.0.0.1:0', [], $this->loop);
        $this->server = $server;
        $parts = \parse_url((string) $server->getAddress());
        $server->on('connection', function (ConnectionInterface $connection): void {
            $this->onConnection($connection);
        });

        return (int) ($parts['port'] ?? 0);
    }

    /**
     * Re-routes DISCORD CDN dials to this fixture; everything else (the
     * loopback API base) passes through to the real connector — a
     * blanket redirect would hijack the REST calls riding the same
     * Browser.
     */
    public function connector(): ConnectorInterface
    {
        $cdnTarget = '127.0.0.1:' . $this->listen();
        $inner = new Connector([], $this->loop);

        return new class ($cdnTarget, $inner) implements ConnectorInterface {
            public function __construct(private readonly string $cdnTarget, private readonly Connector $inner) {}

            // Untyped to match this react/socket's legacy interface signature.
            #[\ReturnTypeWillChange]
            public function connect($uri)
            {
                $host = \strtolower((string) (\parse_url((string) $uri, \PHP_URL_HOST) ?: ''));
                foreach (['discord.com', 'discord.net', 'discordapp.com', 'discordapp.net'] as $domain) {
                    if ($host === $domain || \str_ends_with($host, '.' . $domain)) {
                        return $this->inner->connect($this->cdnTarget);
                    }
                }

                return $this->inner->connect((string) $uri);
            }
        };
    }

    public function stop(): void
    {
        $this->server?->close();
        $this->server = null;
    }

    private function onConnection(ConnectionInterface $connection): void
    {
        $buffer = '';
        $connection->on('data', function (string $chunk) use ($connection, &$buffer): void {
            $buffer .= $chunk;
            $end = \strpos($buffer, "\r\n\r\n");
            if ($end === false) {
                return;
            }
            ++$this->requests;
            $head = \substr($buffer, 0, $end);
            $line = \explode("\r\n", $head)[0] ?? '';
            [$method, $path] = \array_pad(\explode(' ', $line), 2, '');
            if ($method !== 'GET' || $path === '') {
                $connection->end("HTTP/1.1 405 Method Not Allowed\r\nContent-Length: 0\r\n\r\n");

                return;
            }
            $this->streamBody($connection);
        });
    }

    private function streamBody(ConnectionInterface $connection): void
    {
        $headers = "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nConnection: close\r\n";
        if ($this->declaredLength !== null) {
            $headers .= 'Content-Length: ' . $this->declaredLength . "\r\n";
        }
        $connection->write($headers . "\r\n");
        ++$this->headersSent;
        if ($this->streamBytes <= 0) {
            $connection->end();

            return;
        }
        $remaining = $this->streamBytes;
        $this->loop->addTimer($this->streamDelayMs / 1000.0, function () use ($connection, &$remaining): void {
            if (!$connection->isWritable()) {
                return;
            }
            $connection->write(\str_repeat('x', \min($remaining, $this->streamChunkBytes)));
            $this->bodyBytesSent += \min($remaining, $this->streamChunkBytes);
            $remaining -= $this->streamChunkBytes;
            if ($remaining <= 0) {
                $connection->end();

                return;
            }
            $this->loop->addPeriodicTimer(0.001, function (\React\EventLoop\TimerInterface $timer) use ($connection, &$remaining): void {
                if (!$connection->isWritable()) {
                    $this->loop->cancelTimer($timer);

                    return;
                }
                $slice = \min($remaining, $this->streamChunkBytes);
                $connection->write(\str_repeat('x', $slice));
                $this->bodyBytesSent += $slice;
                $remaining -= $slice;
                if ($remaining <= 0) {
                    $this->loop->cancelTimer($timer);
                    $connection->end();
                }
            });
        });
    }
}
