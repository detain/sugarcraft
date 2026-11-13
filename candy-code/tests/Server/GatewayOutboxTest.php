<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Protocol\JsonRpc;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * S-O03: a frame the gateway emits (relay, error reply) rides the connection's
 * Outbox, so a congested socket holds it in order and bounded rather than
 * taking an unbounded write straight onto the socket.
 */
final class GatewayOutboxTest extends TestCase
{
    private ProtocolFixture $fixture;

    private Gateway $gateway;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
        $this->gateway = Gateway::new(
            $this->fixture->dispatcher,
            ServerConfig::new($this->fixture->dir)->withRoot($this->fixture->root),
            'test',
            Loop::get(),
            $this->fixture->dir . '/run',
        );
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testRelayedFramesToACongestedClientQueueInOrderInTheOutbox(): void
    {
        $client = WireClient::open($this->gateway);
        $client->hello();
        $outbox = $this->fixture->dispatcher->outboxFor($client->connection);
        self::assertNotNull($outbox);

        $root = $this->fixture->root;
        $rootClients = new \ReflectionProperty(Gateway::class, 'rootClients');
        $rootClients->setValue($this->gateway, [$root => [$client->connection->id() => $client->connection]]);
        $relay = new \ReflectionMethod(Gateway::class, 'relay');

        $client->clear();
        $client->toClient->pause();
        for ($n = 1; $n <= 5; $n++) {
            $payload = (string) \json_encode(['jsonrpc' => '2.0', 'method' => 'event', 'params' => ['type' => 'x', 'data' => ['n' => $n]]]);
            $relay->invoke($this->gateway, $root, ['type' => 'x', 'durable' => false], $payload);
        }

        self::assertGreaterThan(0, $outbox->count(), 'a congested socket holds the rest in the outbox');
        self::assertGreaterThan(0, $outbox->bufferedBytes());
        $client->toClient->resume();

        self::assertSame(0, $outbox->count());
        self::assertSame([1, 2, 3, 4, 5], \array_column(\array_column($client->events(), 'data'), 'n'), 'in order, nothing lost');
        self::assertSame($root, $client->events()[0]['root'] ?? null);
    }

    public function testAGatewayErrorReplyIsQueuedBehindAWedgedSocket(): void
    {
        $client = WireClient::open($this->gateway);
        $outbox = $this->fixture->dispatcher->outboxFor($client->connection);
        self::assertNotNull($outbox);

        $client->toClient->pause();
        $client->connection->send('prime');
        $client->raw((string) \json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'workspace.list', 'params' => new \stdClass()]));

        self::assertSame(1, $outbox->count(), 'the not-initialized error waited in the outbox');
        $client->toClient->resume();
        self::assertSame(0, $outbox->count());
    }
}
