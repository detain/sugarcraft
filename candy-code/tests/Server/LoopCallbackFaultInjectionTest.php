<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Message;
use SugarCraft\Code\Backend\PendingAsk;
use SugarCraft\Code\Events\PermissionAsked;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Ws\Connection;
use SugarCraft\Code\Server\Ws\MessageHandler;
use SugarCraft\Code\Server\Ws\Upgrade;
use SugarCraft\Code\Tests\Server\Support\FaultableLoop;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * S-O04: a Loop-registered callback that throws must not unwind `Loop::run()`
 * (that would end the daemon for every client). The cause is logged by name
 * and the timer stays armed.
 */
final class LoopCallbackFaultInjectionTest extends TestCase
{
    public function testAThrowingPumpTickIsLoggedAndTheLoopKeepsServing(): void
    {
        $lines = [];
        $throw = false;
        $fixture = ProtocolFixture::new(
            log: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            clock: static function () use (&$throw): float {
                if ($throw) {
                    throw new \RuntimeException('clock exploded');
                }

                return \microtime(true);
            },
        );

        try {
            $fixture->dispatcher->start();
            $throw = true;
            $fixture->run(0.2);

            $pumpLines = \array_values(\array_filter($lines, static fn (string $l): bool => \str_starts_with($l, 'pump tick failed: RuntimeException: clock exploded')));
            self::assertGreaterThanOrEqual(2, \count($pumpLines), 'the timer survived the first throw and fired again');

            $throw = false;
            $before = \count($lines);
            $fixture->run(0.12);
            self::assertSame($before, \count($lines), 'once the fault clears the pump runs quietly');
        } finally {
            $fixture->tearDown();
        }
    }

    public function testAThrowingServerTickIsLoggedAndTheTimerStaysArmed(): void
    {
        $lines = [];
        $throw = false;
        $loop = new FaultableLoop();
        $loop->recordPeriodic = true;
        $fixture = ProtocolFixture::new(
            log: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            clock: static function () use (&$throw): float {
                if ($throw) {
                    throw new \RuntimeException('tick clock exploded');
                }

                return \microtime(true);
            },
            loop: $loop,
        );

        try {
            $fixture->dispatcher->start();
            $tick = $loop->periodicCallback(Dispatcher::TICK_INTERVAL_SECONDS);

            $throw = true;
            $tick();
            $tick();
            $failed = \array_values(\array_filter($lines, static fn (string $l): bool => \str_starts_with($l, 'server tick failed: RuntimeException: tick clock exploded')));
            self::assertCount(2, $failed, 'each throw was logged by name and the callback returned both times');

            $throw = false;
            $before = \count($lines);
            $tick();
            self::assertSame($before, \count($lines), 'the next tick runs quietly once the fault clears');
        } finally {
            $fixture->tearDown();
        }
    }

    public function testAThrowingDrainPollIsLoggedAndTheDrainStillCompletes(): void
    {
        $lines = [];
        $throw = false;
        $fixture = ProtocolFixture::new(
            log: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            clock: static function () use (&$throw): float {
                if ($throw) {
                    throw new \RuntimeException('drain clock exploded');
                }

                return \microtime(true);
            },
        );

        try {
            $client = $fixture->client();
            $sessionId = $fixture->session($client);
            $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'finish this']);
            $outcome = null;
            $fixture->dispatcher->drain(30.0, static function (bool $settled) use (&$outcome): void {
                $outcome = $settled;
            });

            $throw = true;
            $fixture->run(0.35);
            $failed = \array_values(\array_filter($lines, static fn (string $l): bool => \str_starts_with($l, 'drain poll failed: RuntimeException: drain clock exploded')));
            self::assertGreaterThanOrEqual(2, \count($failed), 'the periodic poll survived its first throw and fired again');
            self::assertNull($outcome, 'a faulted poll is not a verdict: the drain is still waiting');

            $throw = false;
            $fixture->backend->settle(Message::assistant('done'));
            $fixture->run(0.3);
            self::assertTrue($outcome, 'once the fault clears the same timer ends the drain');
        } finally {
            $fixture->tearDown();
        }
    }

    public function testAThrowingAskTimeoutIsLoggedNotFatal(): void
    {
        $lines = [];
        $fixture = ProtocolFixture::new(
            ServerConfig::new('/nonexistent')->withAskTimeoutSeconds(0.05),
            log: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        try {
            $client = $fixture->client();
            $sessionId = $fixture->session($client);
            $client->call('session.subscribe', ['sessionId' => $sessionId]);
            $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'ask me']);
            $fixture->run();

            // An ask whose settle callback throws: the timeout's host->answer()
            // is the call that faults, inside a Loop timer.
            $ask = PendingAsk::fromFrame([
                'askId' => PendingAsk::askId('c1', 'Bash', ['command' => 'ls']),
                'toolCallId' => 'c1',
                'tool' => 'Bash',
                'arguments' => ['command' => 'ls'],
                'reason' => 'needs approval',
                'source' => 'gate',
                'mode' => 'default',
                'suggestions' => ['once', 'reject'],
                'alwaysScope' => [],
                'askedAt' => \time(),
            ], static function (): void {
                throw new \RuntimeException('settle exploded');
            });
            \assert($ask instanceof PendingAsk);
            $fixture->backend->emit(new PermissionAsked($ask));

            $fixture->run(0.25);

            $failed = \array_values(\array_filter($lines, static fn (string $l): bool => \str_contains($l, ' ask timeout ') && \str_contains($l, 'RuntimeException: settle exploded')));
            self::assertCount(1, $failed, 'the timer fired once, the throw was logged with the session and ask named');
            self::assertStringContainsString($sessionId, $failed[0]);
            self::assertSame('ok', $client->call('server.health')['status'] ?? 'ok', 'the loop still serves calls after the fault');
        } finally {
            $fixture->tearDown();
        }
    }

    public function testAThrowingOnOpenHandlerIsLoggedAndOnlyThatSocketIsDropped(): void
    {
        $lines = [];
        $opened = [];
        $handler = new class () implements MessageHandler {
            public function onOpen(Connection $connection): void
            {
                throw new \RuntimeException('onOpen exploded');
            }

            public function onMessage(Connection $connection, string $payload): void
            {
            }

            public function onClose(Connection $connection, int $code): void
            {
            }
        };
        $upgrade = new Upgrade(
            $handler,
            static function (Connection $connection) use (&$opened): void {
                $opened[] = $connection;
            },
            static function (Connection $connection): void {
            },
            ServerConfig::MAX_CLIENT_FRAME_BYTES,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $request = (new ServerRequest('GET', 'http://127.0.0.1/ws'))
            ->withHeader('Upgrade', 'websocket')
            ->withHeader('Connection', 'Upgrade')
            ->withHeader('Sec-WebSocket-Key', \base64_encode(\random_bytes(16)))
            ->withHeader('Sec-WebSocket-Version', '13')
            ->withHeader('Sec-WebSocket-Protocol', ServerConfig::SUBPROTOCOL);

        $response = $upgrade($request);
        self::assertSame(101, $response->getStatusCode());
        self::assertCount(1, $opened);
        self::assertTrue($opened[0]->isOpen(), 'the socket is open until the deferred onOpen runs');

        $timer = Loop::addTimer(0.05, static fn () => Loop::stop());
        Loop::run();
        Loop::cancelTimer($timer);

        self::assertCount(1, $lines);
        self::assertStringStartsWith('socket ' . $opened[0]->id() . ' onOpen: RuntimeException: onOpen exploded', $lines[0]);
        self::assertFalse($opened[0]->isOpen(), 'the faulting socket is closed, not left half-open');
    }
}
