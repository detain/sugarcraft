<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;

/**
 * ISS-055: the front door answers even while clients are dribbling bytes.
 *
 * Before this, a slow request body was read — and its slot held — by stages
 * that ran BEFORE `--allowed-ips`, the Host check, authentication: a handful
 * of sockets sending one byte a minute could park the whole listener, and a
 * `GET /api/health` from a legitimate client queued behind them forever. Now
 * the guards and the address cap run on headers alone, every request carries
 * a read deadline, and a connection that sends nothing at all is dropped.
 *
 * These tests speak RAW TCP — the point is behaviour react/http's buffered
 * client can no longer express: half-sent bodies, silent connections, and
 * what the port answers while they are stuck. The deadline is injected at a
 * short value ({@see Server::new()}'s `$requestDeadlineSeconds`); the shipped
 * default stays {@see Server::REQUEST_READ_DEADLINE_SECONDS}.
 */
final class RequestIngressTest extends TestCase
{
    private const DEADLINE = 0.3;

    private Server $server;

    private int $port;

    /** @var list<string> */
    private array $lines = [];

    /** @var list<resource> */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->server = Server::new(
            ServerConfig::new('/nonexistent')->withPort(0),
            AuthContext::new(TokenStore::new('/nonexistent')->withOverride(str_repeat('7', 64))),
            null,
            null,
            Loop::get(),
            function (string $line): void {
                $this->lines[] = $line;
            },
            null,
            self::DEADLINE,
        );
        $this->server->start();
        $this->port = (int) $this->server->port();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            @fclose($socket);
        }
        $this->sockets = [];
        $this->server->stop();
        $timer = Loop::addTimer(0.05, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }

    public function testAStalledBodyIsAnswered408AndTheSocketTornDown(): void
    {
        $socket = $this->connect();
        $this->write($socket, "POST /api/login HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\nContent-Length: 100\r\n\r\nx");
        $this->spin(self::DEADLINE + 0.8);

        $answer = $this->readAll($socket);
        self::assertStringStartsWith('HTTP/1.1 408', $answer, 'the deadline answers, it does not sulk silently');
        self::assertStringContainsString('request_timeout', $answer);
        self::assertStringContainsString('Connection: close', $answer, 'react/http tears the socket down after writing');
        self::assertTrue(feof($socket), 'and the socket is gone');
        self::assertContains('request deadline: POST /api/login did not finish reading within 0.3 s', $this->lines);
    }

    public function testAConnectionThatSendsNoRequestIsDropped(): void
    {
        $socket = $this->connect();
        $this->spin(self::DEADLINE + 0.8);

        $this->readAll($socket);
        self::assertTrue(feof($socket), 'the first-byte deadline closed it');
        self::assertContains('request deadline: a client connected and sent no request', $this->lines);
    }

    public function testOneAddressCannotParkMoreThanTheCapLeavesTheListenerAnswering(): void
    {
        $stalled = [];
        for ($i = 0; $i < Server::MAX_CONCURRENT_PER_ADDRESS; ++$i) {
            $socket = $this->connect();
            $this->write($socket, "POST /api/login HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\nContent-Length: 100\r\n\r\nx");
            $stalled[] = $socket;
        }
        $this->spin(0.08);

        // The cap is 16 deep and the deadline 0.3 s — inside that window the
        // address is at its cap, and a 17th request is refused without ever
        // queuing behind the dribble.
        $seventeenth = $this->connect();
        $this->write($seventeenth, "GET /api/health HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\n\r\n");
        $this->spin(0.08);
        self::assertStringStartsWith('HTTP/1.1 429', $this->readAll($seventeenth), 'per-address cap refuses, with a Retry-After');

        // Once the stalled bodies hit their deadline the address is free again.
        $this->spin(self::DEADLINE + 0.6);
        foreach ($stalled as $socket) {
            self::assertStringStartsWith('HTTP/1.1 408', $this->readAll($socket));
        }
        $healthy = $this->connect();
        $this->write($healthy, "GET /api/health HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\n\r\n");
        $this->spin(0.3);
        self::assertStringStartsWith('HTTP/1.1 200', $this->readAll($healthy), 'and health answered all along — the guards run ahead of the stall');
    }

    public function testACompleteBodyNeverCarriesTheDeadline(): void
    {
        $socket = $this->connect();
        $body = '{"code":"nope"}';
        $this->write($socket, "POST /api/login HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\nContent-Length: " . \strlen($body) . "\r\nOrigin: http://127.0.0.1:{$this->port}\r\n\r\n" . $body);
        $this->spin(0.2);

        self::assertStringStartsWith('HTTP/1.1 401', $this->readAll($socket), 'a bad code is answered promptly, not timed out');
        foreach ($this->lines as $line) {
            self::assertStringNotContainsString('request deadline', $line);
        }
    }

    /** @return resource */
    private function connect()
    {
        $socket = @stream_socket_client('127.0.0.1:' . $this->port, $errno, $errstr, 5.0);
        self::assertNotFalse($socket, 'connect: ' . $errstr);
        $this->sockets[] = $socket;

        return $socket;
    }

    /** @param resource $socket */
    private function write($socket, string $bytes): void
    {
        self::assertSame(\strlen($bytes), @fwrite($socket, $bytes));
    }

    /** Run the shared loop for $seconds, then hand control back. */
    private function spin(float $seconds): void
    {
        $timer = Loop::addTimer($seconds, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }

    /** @param resource $socket */
    private function readAll($socket): string
    {
        stream_set_blocking($socket, false);
        $bytes = '';
        while (false !== ($chunk = fread($socket, 65536))) {
            $bytes .= $chunk;
            if ('' === $chunk) {
                break;
            }
        }

        return $bytes;
    }
}
