<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Fixture;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

/**
 * A loopback stand-in for the Discord REST API: it logs every call
 * {method, path, body} and answers with canned JSON, so the outbound
 * payload tests (message shape, approval components, thread probe and
 * archive) pin exactly what the bundle PUT/POSTs/PATCHes/DELETEs — never
 * the real service, never real tokens. The failNextWith knob answers the
 * next call with a real status line, because the status arms in Rest must
 * be driven by LIVE statuses, not by transport errors.
 */
final class FakeDiscordApi
{
    /** @var list<array{method:string,path:string,body:array<string,mixed>}> */
    public array $calls = [];

    /**
     * One-shot failure knob: when set, the NEXT request is answered with a
     * real status line for that code and the knob resets. The status arms in
     * Rest must be driven by LIVE statuses, not by transport errors, or a
     * green test proves nothing about the react/http rejection default.
     */
    public ?int $failNextWith = null;

    /**
     * Persistent sibling of the one-shot knob: while set, EVERY request is
     * answered with that live status. A single test can therefore drive the
     * undeploy walk over two failing legs — which is what the loud-once
     * notice in DiscordAccount needs to be observable against.
     */
    public ?int $failAllWith = null;

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

    /** The most recent logged call whose path ends with $suffix. */
    public function lastCallFor(string $suffix): ?array
    {
        foreach (\array_reverse($this->calls) as $call) {
            if (\str_ends_with($call['path'], $suffix)) {
                return $call;
            }
        }

        return null;
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
            $head = \substr($buffer, 0, $end);
            $lines = \explode("\r\n", $head);
            [$method, $path] = \array_pad(\explode(' ', $lines[0] ?? ''), 2, '');
            $length = 0;
            foreach ($lines as $line) {
                if (\preg_match('/^content-length:\s*(\d+)$/i', $line, $m) === 1) {
                    $length = (int) $m[1];
                }
            }
            if (\strlen($buffer) < $end + 4 + $length) {
                return;
            }
            $body = \substr($buffer, $end + 4, $length);
            $decoded = \json_decode($body, true);
            $this->calls[] = [
                'method' => $method,
                'path' => $path,
                'body' => \is_array($decoded) ? $decoded : [],
            ];
            $payload = $this->respond($method, $path);
            if ($this->failNextWith !== null) {
                $status = $this->failNextWith;
                $this->failNextWith = null;
                $connection->end($this->frame($status, '{}'));

                return;
            }
            if ($this->failAllWith !== null) {
                $connection->end($this->frame($this->failAllWith, '{}'));

                return;
            }
            $connection->end($this->frame(200, $payload));
        });
    }

    private function frame(int $status, string $body): string
    {
        $reason = match ($status) {
            201 => 'Created',
            404 => 'Not Found',
            default => 'OK',
        };

        return "HTTP/1.1 {$status} {$reason}\r\nContent-Type: application/json\r\nContent-Length: "
            . \strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    }

    private function respond(string $method, string $path): string
    {
        if ($method === 'POST' && \str_ends_with($path, '/typing')) {
            return '{}';
        }
        if ($method === 'POST' && \str_ends_with($path, '/messages')) {
            return '{"id":"msg_out"}';
        }
        if ($method === 'PATCH' && \str_contains($path, '/messages/')) {
            return '{"id":"m1"}';
        }
        if ($method === 'GET' && $path === '/applications/@me') {
            return '{"id":"app-9"}';
        }
        if ($method === 'GET' && $path === '/channels/th1') {
            return '{"id":"th1","type":11}';
        }
        if ($method === 'GET' && \str_starts_with($path, '/channels/')) {
            return '{"id":"c9","type":0}';
        }
        if ($method === 'PUT') {
            return '[]'; // Bulk overwrite (deploy) — accepted roster is pinned by the calls array.
        }
        if ($method === 'GET' && \str_ends_with($path, '/commands')) {
            // N5 undeploy listing: two deployed commands for the face to retract.
            return '[{"id":"cmd1","name":"new"},{"id":"cmd2","name":"stop"}]';
        }

        return '{}';
    }
}
