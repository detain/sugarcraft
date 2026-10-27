<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\HookWebhooks;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;

/**
 * C-AGT-1: a real 70 KiB webhook push, driven through the REAL middleware
 * stack (StreamingRequestMiddleware → guards → RequestBodyBufferMiddleware →
 * router → handler). The buffer middleware discards an over-cap body and hands
 * the handler an empty one; only the handler's declared-body guard turns that
 * into a loud 413. Without it the delivery 200s and bills a turn for nothing.
 *
 * The exact cap is the other half of the pin: a body of precisely
 * MAX_API_BODY_BYTES must still be admitted — the middleware discards only
 * PAST the cap, and the guard mirrors that strict greater-than.
 */
final class HookWebhooksBodyCapStackTest extends TestCase
{
    private const SECRET = 'body-cap-stack-secret-longer-than-thirty-two';

    private Server $server;

    private int $port;

    /** @var list<array{name:string,hook:array{secret:string,prompt:string,permissionMode:\SugarCraft\Code\Permissions\PermissionMode|null,rate:int},prompt:string}> */
    private array $deliveries = [];

    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            @fclose($socket);
        }
        $this->sockets = [];
        if (isset($this->server)) {
            $this->server->stop();
            $timer = Loop::addTimer(0.05, static fn () => Loop::stop());
            Loop::run();
            Loop::cancelTimer($timer);
            unset($this->server);
        }
    }

    public function testSeventyKibThroughTheRealStackIsRefusedFortyThirteen(): void
    {
        $this->serveStack();

        $answer = $this->postBytes('/api/hooks/deploy', self::SECRET, \str_repeat('p', 70 * 1024));

        self::assertStringStartsWith('HTTP/1.1 413', $answer);
        self::assertStringContainsString('"kind":"payload_too_large"', $answer);
        self::assertSame([], $this->deliveries, 'the over-cap push never reaches the admission door');
    }

    public function testABodyOfExactlyTheCapIsStillAdmitted(): void
    {
        $this->serveStack();

        $answer = $this->postBytes('/api/hooks/deploy', self::SECRET, \str_repeat('k', ServerConfig::MAX_API_BODY_BYTES));

        self::assertStringStartsWith('HTTP/1.1 200', $answer, 'the guard is strictly past the cap, matching the middleware');
        self::assertCount(1, $this->deliveries);
        self::assertSame(\str_repeat('k', ServerConfig::MAX_API_BODY_BYTES), $this->deliveries[0]['prompt']);
    }

    public function testAnOrdinarySmallBodyRidesTheStackUnchanged(): void
    {
        $this->serveStack();

        $answer = $this->postBytes('/api/hooks/deploy', self::SECRET, 'deploy finished ok');

        self::assertStringStartsWith('HTTP/1.1 200', $answer);
        self::assertCount(1, $this->deliveries);
        self::assertSame('deploy finished ok', $this->deliveries[0]['prompt']);
    }

    private function serveStack(): void
    {
        $run = function (string $name, array $hook, string $prompt): array {
            $this->deliveries[] = ['name' => $name, 'hook' => $hook, 'prompt' => $prompt];

            return ['bgId' => 'bg-' . $name, 'status' => 'running'];
        };
        $this->server = Server::new(
            ServerConfig::new('/nonexistent')->withPort(0),
            AuthContext::new(TokenStore::new('/nonexistent')->withOverride(\str_repeat('7', 64))),
            null,
            null,
            Loop::get(),
            null,
            null,
            null,
            null,
            HookWebhooks::new(
                ['deploy' => ['secret' => self::SECRET, 'prompt' => '{{payload}}', 'permissionMode' => null, 'rate' => 5]],
                $run,
                static fn (): float => \microtime(true),
            ),
        );
        $this->server->start();
        $this->port = (int) $this->server->port();
    }

    /**
     * Feed a body that dwarfs the socket buffers: the client side goes
     * non-blocking and pumps the SAME loop the server runs on whenever a
     * write stalls, so the test cannot deadlock against the kernel.
     */
    private function postBytes(string $path, string $secret, string $body): string
    {
        $socket = @stream_socket_client('127.0.0.1:' . $this->port, $errno, $errstr, 5.0);
        self::assertNotFalse($socket, 'connect: ' . $errstr);
        $this->sockets[] = $socket;
        \stream_set_blocking($socket, false);

        $bytes = \sprintf("POST %s HTTP/1.1\r\nHost: 127.0.0.1:%d\r\nContent-Length: %d\r\n", $path, $this->port, \strlen($body))
            . 'x-candy-code-hook: ' . $secret . "\r\n\r\n" . $body;

        $written = 0;
        for ($spins = 0; $written < \strlen($bytes); ++$spins) {
            self::assertLessThan(20_000, $spins, 'the socket stalled feeding the server');
            $n = @fwrite($socket, \substr($bytes, $written));
            self::assertNotFalse($n, 'socket write failed');
            $written += $n;
            $this->pumpLoop(0.002);
        }

        $this->pumpLoop(0.3);
        $answer = '';
        while (false !== ($chunk = fread($socket, 65536))) {
            $answer .= $chunk;
            if ('' === $chunk) {
                break;
            }
        }
        self::assertStringContainsString("\r\n\r\n", $answer, 'the server answered');

        return $answer;
    }

    private function pumpLoop(float $seconds): void
    {
        $timer = Loop::addTimer($seconds, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);
    }
}
