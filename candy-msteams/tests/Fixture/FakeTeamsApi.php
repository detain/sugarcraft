<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Fixture;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

/**
 * Loopback HTTP fake for the Bot Framework conversation side and the AAD
 * token endpoint: canned JSON answers, every call captured. The bundle's
 * Rest/TokenBroker point at it through the endpoint-override settings with
 * the loopback-proxy opt-in — never the real service, never real tokens.
 */
final class FakeTeamsApi
{
    public int $port = 0;

    /** @var list<array{method: string, path: string, body: string, auth: string}> */
    public array $calls = [];

    public int $tokensMinted = 0;

    public int $outIds = 0;

    public int $typingSent = 0;

    /** One-shot failure injection (status code the next matching call gets). */
    public ?int $failNextWith = null;

    /** Budgeted failures for activity posts: the next N POSTs get this status (and Retry-After). */
    private int $failBudget = 0;

    private int $failStatus = 0;

    private ?string $failRetryAfter = null;

    private string $failBody = '{"error":"throttled"}';

    /** @var array<string,mixed>|null JWKS body served at /keys */
    public ?array $keys = null;

    public string $mediaBytes = 'FAKEPNGBYTES';

    private ?SocketServer $server = null;

    public function __construct(
        private readonly LoopInterface $loop,
    ) {
    }

    public function listen(): int
    {
        $server = new SocketServer('127.0.0.1:0', [], $this->loop);
        $this->port = (int) \parse_url((string) $server->getAddress(), \PHP_URL_PORT);
        $server->on('connection', function (ConnectionInterface $conn): void {
            $buffer = '';
            $conn->on('data', function (string $chunk) use ($conn, &$buffer): void {
                $buffer .= $chunk;
                $end = \strpos($buffer, "\r\n\r\n");
                if ($end === false) {
                    return;
                }
                $head = \substr($buffer, 0, $end);
                $body = \substr($buffer, $end + 4);
                $lines = \explode("\r\n", $head);
                if (!isset($lines[0])) {
                    return;
                }
                if (\preg_match('/^Content-Length:\s*(\d+)/mi', $head, $m) === 1 && strlen($body) < (int) $m[1]) {
                    return; // keep buffering
                }
                [$method, $path] = \array_pad(\explode(' ', $lines[0]), 2, '');
                $auth = '';
                if (\preg_match('/^Authorization:\s*(.+)$/mi', $head, $m) === 1) {
                    $auth = \trim($m[1]);
                }
                $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'auth' => $auth];

                $this->answer($conn, $method, (string) $path, $body);
            });
        });
        $this->server = $server;

        return $this->port;
    }

    /** The next $times activity POSTs answer $status (with an optional Retry-After). */
    public function failActivityPosts(int $status, int $times, ?string $retryAfter = null, string $body = '{"error":"throttled"}'): void
    {
        $this->failStatus = $status;
        $this->failBudget = $times;
        $this->failRetryAfter = $retryAfter;
        $this->failBody = $body;
    }

    private function answer(ConnectionInterface $conn, string $method, string $path, string $body): void
    {
        if ($this->failBudget > 0 && $method === 'POST' && \str_contains($path, '/activities')) {
            $this->failBudget--;
            $conn->end($this->frame($this->failStatus, $this->failBody, 'application/json', $this->failRetryAfter));

            return;
        }
        if ($this->failNextWith !== null) {
            $status = $this->failNextWith;
            $this->failNextWith = null;
            $conn->end($this->frame($status, '{}'));

            return;
        }
        if ($method === 'POST' && \str_ends_with($path, '/oauth2/v2.0/token')) {
            $this->tokensMinted++;
            $conn->end($this->frame(200, (string) \json_encode([
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'access_token' => 'bot-tok-' . $this->tokensMinted,
            ])));

            return;
        }
        if ($method === 'GET' && $path === '/keys') {
            $conn->end($this->frame(200, (string) \json_encode($this->keys ?? ['keys' => []])));

            return;
        }
        if ($method === 'GET' && \str_starts_with($path, '/media/') && \str_contains($path, 'missing')) {
            $conn->end($this->frame(404, '{"error":"no such media"}'));

            return;
        }
        if ($method === 'GET' && \str_starts_with($path, '/media/')) {
            $conn->end($this->frame(200, $this->mediaBytes, 'application/octet-stream'));

            return;
        }
        if ($method === 'POST' && \str_contains($path, '/v3/conversations/')) {
            if (\str_contains($body, '"type":"typing"') || \str_contains($body, '"type": "typing"')) {
                $this->typingSent++;
                $conn->end($this->frame(200, '{}'));

                return;
            }
            $this->outIds++;
            $conn->end($this->frame(201, (string) \json_encode([
                'id' => 'act_out_' . $this->outIds,
                'serviceUrl' => 'http://127.0.0.1:' . $this->port,
            ])));

            return;
        }
        if ($method === 'PUT' && \str_contains($path, '/v3/conversations/')) {
            $id = \substr($path, (int) \strrpos($path, '/') + 1);
            $conn->end($this->frame(200, (string) \json_encode(['id' => $id])));

            return;
        }

        $conn->end($this->frame(404, '{"error":"unexpected ' . $method . ' ' . $path . '"}'));
    }

    /** @return array{method: string, path: string, body: string, auth: string}|null */
    public function lastCallFor(string $needle, string $method = ''): ?array
    {
        foreach (\array_reverse($this->calls) as $call) {
            if (\str_contains($call['path'], $needle) && ($method === '' || $call['method'] === $method)) {
                return $call;
            }
        }

        return null;
    }

    public function stop(): void
    {
        $this->server?->close();
    }

    private function frame(int $status, string $body, string $type = 'application/json', ?string $retryAfter = null): string
    {
        $reason = match ($status) {
            201 => 'Created',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            default => 'OK',
        };
        $retry = $retryAfter === null ? '' : "Retry-After: {$retryAfter}\r\n";

        return "HTTP/1.1 {$status} {$reason}\r\nContent-Type: {$type}\r\n{$retry}Content-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    }
}
