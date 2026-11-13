<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Code\Tests\Server\Support\WireClient;

/**
 * S-O19: a forwarded call whose host never answers is settled by a deadline —
 * the client gets a retryable `busy`, and the root stops counting the call in
 * flight so idle recycling can proceed.
 */
final class GatewayForwardDeadlineTest extends TestCase
{
    private ProtocolFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ProtocolFixture::new();
    }

    protected function tearDown(): void
    {
        $this->fixture->tearDown();
    }

    public function testAWedgedHostSettlesTheClientWithinTheBudgetAndFreesTheRoot(): void
    {
        $gateway = Gateway::new(
            $this->fixture->dispatcher,
            ServerConfig::new($this->fixture->dir)->withRoot($this->fixture->root),
            'test',
            Loop::get(),
            $this->fixture->dir . '/run',
        );
        $client = WireClient::open($gateway);
        $client->hello();

        $root = '/wedged/root';
        // A host start that never completes.
        (new \ReflectionProperty(Gateway::class, 'starting'))->setValue($gateway, [$root => (new Deferred())->promise()]);
        (new \ReflectionProperty(Gateway::class, 'forwardDeadline'))->setValue($gateway, 0.05);

        $client->clear();
        (new \ReflectionMethod(Gateway::class, 'forward'))
            ->invoke($gateway, $client->connection, 7, $root, 'session.list', new \stdClass());

        $inFlight = new \ReflectionProperty(Gateway::class, 'inFlight');
        self::assertSame(1, $inFlight->getValue($gateway)[$root]);

        $loop = Loop::get();
        $guard = $loop->addTimer(5.0, static fn () => $loop->stop());
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($client, $loop): void {
            if ($client->response(7) !== null) {
                $loop->stop();
            }
        });
        $loop->run();
        $loop->cancelTimer($guard);
        $loop->cancelTimer($poll);

        $reply = $client->response(7);
        self::assertNotNull($reply, 'the client is answered, not parked');
        self::assertSame('host_timeout', $reply['error']['data']['kind'] ?? null);
        self::assertTrue($reply['error']['data']['retryable'] ?? false, 'the client may simply retry');
        self::assertSame(0, $inFlight->getValue($gateway)[$root], 'the root is reapable again');
    }
}
