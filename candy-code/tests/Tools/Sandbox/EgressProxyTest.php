<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Sandbox;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use SugarCraft\Code\Tools\Sandbox\EgressPolicy;
use SugarCraft\Code\Tools\Sandbox\EgressProxy;

/**
 * C-TOOL-4.f: the egress proxy serves CONNECT and absolute-form HTTP on a
 * 0600 unix socket, refuses off-list hosts with a 403 that names the host
 * AND the setting, rewrites absolute-form request lines to origin-form, and
 * gives the tunnel only an IDLE reap — never a total bound (E646: a long
 * upload or download must not be killed by wall-clock).
 *
 * Every scenario injects a {@see EgressPolicy} whose resolver answers with
 * 127.0.0.1, so "dialling the vetted address" lands on fake upstreams bound
 * in-test. The loop is a private StreamSelectLoop per scenario and the
 * driver stops it well inside the 1 s liveness sweep, so the child-exit
 * legs never race the test.
 */
final class EgressProxyTest extends TestCase
{
    private const LOCAL = '127.0.0.1';

    public function testConnectToAGrantedHostTunnelsBytesBothWays(): void
    {
        $loop = new StreamSelectLoop();
        $directory = $this->privateDirectory();
        $socket = $directory . '/s';
        $upstream = $this->echoUpstream($loop, 'pong-4f');
        $policy = $this->proxyPolicy(['tunnel.internal:' . $upstream['port']]);

        $received = '';
        $this->pumpClient(
            $loop,
            $socket,
            'CONNECT tunnel.internal:' . $upstream['port'] . " HTTP/1.1\r\n\r\n",
            'HTTP/1.1 200 Connection Established',
            'ping-4f',
            $received,
        );
        EgressProxy::servePolicy($socket, $policy, $loop);

        self::assertStringContainsString('HTTP/1.1 200 Connection Established', $received);
        self::assertStringContainsString('GOT:ping-4f', $received, 'tunnel bytes must flow client→proxy→upstream');
        self::assertStringContainsString('pong-4f', $received, 'tunnel bytes must flow upstream→proxy→client');
        $this->releaseEgress($directory, $socket, $upstream['server']);
    }

    public function testDeniedConnectAnswersFourOhThreeNamingHostAndSetting(): void
    {
        $loop = new StreamSelectLoop();
        $directory = $this->privateDirectory();
        $socket = $directory . '/s';
        $policy = $this->proxyPolicy(['github.com']);

        $received = '';
        $this->pumpClient(
            $loop,
            $socket,
            "CONNECT other.internal:443 HTTP/1.1\r\n\r\n",
            'sandboxAllowedDomains',
            null,
            $received,
        );
        EgressProxy::servePolicy($socket, $policy, $loop);

        self::assertStringStartsWith('HTTP/1.1 403 Forbidden', $received);
        self::assertStringContainsString('other.internal', $received);
        self::assertStringContainsString('sandboxAllowedDomains', $received, 'the refusal must say which setting to change');
        $this->releaseEgress($directory, $socket);
    }

    public function testAbsoluteFormRequestIsRewrittenToOriginForm(): void
    {
        $loop = new StreamSelectLoop();
        $directory = $this->privateDirectory();
        $socket = $directory . '/s';
        $seen = null;
        $upstream = $this->replyUpstream($loop, static function (string $head) use (&$seen): string {
            $lines = explode("\r\n", $head);
            $seen = $lines[0] ?? null;
            return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nhi";
        });
        $policy = $this->proxyPolicy(['abs.internal:' . $upstream['port']]);

        $received = '';
        $this->pumpClient(
            $loop,
            $socket,
            'GET http://abs.internal:' . $upstream['port'] . "/x?y=1 HTTP/1.1\r\nHost: abs.internal\r\n\r\n",
            'Content-Length: 2',
            null,
            $received,
        );
        EgressProxy::servePolicy($socket, $policy, $loop);

        self::assertStringContainsString('HTTP/1.1 200 OK', $received);
        self::assertStringEndsWith('hi', $received);
        self::assertSame('GET /x?y=1 HTTP/1.1', $seen, 'the upstream must see the request an origin client would have sent');
        $this->releaseEgress($directory, $socket, $upstream['server']);
    }

    public function testGarbageRequestLineIsRejectedWithoutFourOhFourIngTheProxy(): void
    {
        $loop = new StreamSelectLoop();
        $directory = $this->privateDirectory();
        $socket = $directory . '/s';
        $policy = $this->proxyPolicy(['github.com']);

        $received = '';
        $this->pumpClient($loop, $socket, "HELLO world\r\n\r\n", 'HTTP/1.1 400', null, $received);
        EgressProxy::servePolicy($socket, $policy, $loop);

        self::assertStringStartsWith('HTTP/1.1 400 Bad Request', $received);
        self::assertStringContainsString('CONNECT', $received, 'the refusal explains the accepted shapes');
        $this->releaseEgress($directory, $socket);
    }

    public function testOversizedHeadIsRefusedAtTheCap(): void
    {
        $loop = new StreamSelectLoop();
        $directory = $this->privateDirectory();
        $socket = $directory . '/s';
        $policy = $this->proxyPolicy(['github.com']);

        $request = "CONNECT padded.internal:443 HTTP/1.1\r\nX-Pad: "
            . str_repeat('A', EgressProxy::HEAD_CAP_BYTES) . "\r\n\r\n";
        $received = '';
        $this->pumpClient($loop, $socket, $request, 'HTTP/1.1 400', null, $received);
        EgressProxy::servePolicy($socket, $policy, $loop);

        self::assertStringStartsWith('HTTP/1.1 400 Bad Request', $received);
        self::assertStringContainsString((string) EgressProxy::HEAD_CAP_BYTES, $received);
        $this->releaseEgress($directory, $socket);
    }

    public function testDeadUpstreamAnswersFiveOhTwo(): void
    {
        $loop = new StreamSelectLoop();
        $directory = $this->privateDirectory();
        $socket = $directory . '/s';
        // Port 1 on the loopback is bound by nobody: the vetted-address dial
        // fails fast and the client is told the proxy could not reach upstream.
        $policy = $this->proxyPolicy(['gone.internal:1']);

        $received = '';
        $this->pumpClient($loop, $socket, "CONNECT gone.internal:1 HTTP/1.1\r\n\r\n", 'HTTP/1.1 502', null, $received);
        EgressProxy::servePolicy($socket, $policy, $loop);

        self::assertStringStartsWith('HTTP/1.1 502 Bad Gateway', $received);
        self::assertStringContainsString('Upstream dial failed', $received);
        $this->releaseEgress($directory, $socket);
    }

    public function testSocketFileIsOwnerOnlyAndThePathIsShort(): void
    {
        $loop = new StreamSelectLoop();
        $directory = $this->privateDirectory();
        $socket = $directory . '/s';
        // Unix socket paths cap around 104-108 bytes; the scaffold keeps the
        // full path shallow under the system temp dir, never a deep HOME tree.
        self::assertLessThan(100, strlen($socket));
        $policy = $this->proxyPolicy(['github.com']);

        $mode = -1;
        $loop->addTimer(0.001, static function () use ($loop, $socket, &$mode): void {
            $mode = @fileperms($socket) & 0777;
            $loop->stop();
        });
        EgressProxy::servePolicy($socket, $policy, $loop);

        self::assertSame(0600, $mode, 'the jail-visible socket must not be world-connectable');
        $this->releaseEgress($directory, $socket);
    }

    public function testTunnelsCarryAnIdleReapButNoTotalBound(): void
    {
        // E646 structural pin over the bridge implementation itself: the
        // only timer a tunnel gets is the PERIODIC idle sweep; a one-shot
        // total-request deadline anywhere in the relay would reintroduce the
        // banned blanket timeout.
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Tools/Sandbox/EgressProxy.php');
        $start = strpos($source, 'private static function bridge');
        self::assertIsInt($start);
        $body = substr((string) $source, (int) $start);

        self::assertGreaterThan(0, substr_count($body, 'addPeriodicTimer'));
        self::assertSame(0, substr_count($body, '->addTimer('), 'a one-shot bound on a tunnel is the E646 blanket timeout');
        self::assertStringContainsString('IDLE_TIMEOUT_SECONDS', $body);
        self::assertGreaterThan(0, substr_count($source, "'timeout' => self::CONNECT_TIMEOUT_SECONDS"));
    }

    /**
     * @param list<string> $entries
     */
    private function proxyPolicy(array $entries): EgressPolicy
    {
        return new EgressPolicy(
            $entries,
            static fn (string $host): array => [self::LOCAL],
            static fn (string $address): bool => false,
        );
    }

    /**
     * @return array{server: SocketServer, port: int}
     */
    private function echoUpstream(LoopInterface $loop, string $greeting): array
    {
        $server = new SocketServer('127.0.0.1:0', [], $loop);
        $server->on('connection', static function (ConnectionInterface $connection) use ($greeting): void {
            $connection->write($greeting);
            $connection->on('data', static function (string $chunk) use ($connection): void {
                $connection->write('GOT:' . $chunk);
            });
        });
        return ['server' => $server, 'port' => $this->portOf($server)];
    }

    /**
     * @param callable(string): string $responder
     *
     * @return array{server: SocketServer, port: int}
     */
    private function replyUpstream(LoopInterface $loop, callable $responder): array
    {
        $server = new SocketServer('127.0.0.1:0', [], $loop);
        $server->on('connection', static function (ConnectionInterface $connection) use ($responder): void {
            $head = '';
            $connection->on('data', static function (string $chunk) use ($connection, $responder, &$head): void {
                $head .= $chunk;
                if (strpos($head, "\r\n\r\n") === false) {
                    return;
                }
                $connection->end($responder($head));
            });
        });
        return ['server' => $server, 'port' => $this->portOf($server)];
    }

    private function portOf(SocketServer $server): int
    {
        $address = (string) $server->getAddress();
        $colon = strrpos($address, ':');
        self::assertIsInt($colon);
        return (int) substr($address, $colon + 1);
    }

    /**
     * Drives one client request through the test loop the proxy will run on.
     *
     * The client is a plain non-blocking stream on the unix socket: bytes
     * collected into &$received until $needle appears, then an optional
     * second (post-tunnel) write, then collection again until EOF or the
     * 0.85 s hard stop — comfortably inside the 1 s liveness sweep so the
     * servePolicy self-exit legs never fire mid-scenario.
     */
    private function pumpClient(
        LoopInterface $loop,
        string $socket,
        string $request,
        string $needle,
        ?string $followUp,
        string &$received,
    ): void {
        $received = '';
        $loop->addTimer(0.001, static function () use ($loop, $socket, $request, $needle, $followUp, &$received): void {
            $client = @stream_socket_client('unix://' . $socket, $errno, $errstr, 1.0);
            if (!is_resource($client)) {
                $received = 'CLIENT-CONNECT-FAILED: ' . $errstr;
                $loop->stop();
                return;
            }
            stream_set_blocking($client, false);
            fwrite($client, $request);
            $state = ['followed' => false];
            $loop->addReadStream($client, static function ($stream) use ($loop, $needle, $followUp, &$received, &$state): void {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    if ($chunk === false || feof($stream)) {
                        $loop->removeReadStream($stream);
                        $loop->stop();
                    }
                    return;
                }
                $received .= $chunk;
                if (!$state['followed'] && $followUp !== null && str_contains($received, $needle)) {
                    $state['followed'] = true;
                    fwrite($stream, $followUp);
                }
            });
        });
        $loop->addTimer(0.85, static function () use ($loop): void {
            $loop->stop();
        });
    }

    private function privateDirectory(): string
    {
        $base = rtrim(sys_get_temp_dir(), '/');
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $base . '/ccpt' . substr(bin2hex(random_bytes(4)), 0, 6);
            if (@mkdir($candidate, 0700) && is_dir($candidate)) {
                return $candidate;
            }
        }
        self::fail('could not create a private temp directory');
    }

    private function releaseEgress(string $directory, string $socket, ?SocketServer $server = null): void
    {
        if ($server !== null) {
            $server->close();
        }
        @unlink($socket);
        @rmdir($directory);
    }
}
