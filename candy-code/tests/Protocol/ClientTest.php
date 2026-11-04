<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use React\Stream\ThroughStream;
use SugarCraft\Code\Protocol\Client;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Ws\Connection;
use SugarCraft\Code\Server\Ws\MessageHandler;
use SugarCraft\Code\Server\Ws\Outbox;

/**
 * The two budgets a live client carries (CWPD-1): the request bucket that
 * counts roundtrips, and the byte meter that counts wire — checked against
 * the transport's own frame ceiling, the refill rate, and a clock that never
 * runs backwards from the socket's point of view.
 */
final class ClientTest extends TestCase
{
    public function testTheByteMeterCeilingIsTheTransportFrameCeiling(): void
    {
        // The meter must fit ONE legal maximum frame from idle, or a
        // legitimate session.import would starve (the whole point of the
        // burst being the ceiling, not a smaller round number).
        self::assertSame((float) ServerConfig::MAX_CLIENT_FRAME_BYTES, Client::BYTE_BURST);
        self::assertSame(8_388_608.0, Client::BYTE_RATE_PER_SECOND);
    }

    public function testOneMaxFrameFitsFromIdleAndTheByteAfterTheCeilingIsRefused(): void
    {
        $now = 0.0;
        $client = $this->clientOnScriptedClock($now);
        self::assertTrue($client->admitBytes((int) Client::BYTE_BURST), 'a frame exactly at the ceiling is admitted from an idle meter');
        self::assertFalse($client->admitBytes(1), 'the meter is empty');

        $fresh = $this->clientOnScriptedClock($now);
        self::assertFalse($fresh->admitBytes((int) Client::BYTE_BURST + 1), 'one byte past the ceiling is refused even from a full meter');
    }

    public function testTheByteMeterRefillsAtTheStatedRate(): void
    {
        $now = 0.0;
        $client = $this->clientOnScriptedClock($now);
        self::assertTrue($client->admitBytes((int) Client::BYTE_BURST));
        $now = 1.0;
        self::assertTrue($client->admitBytes((int) Client::BYTE_RATE_PER_SECOND), 'one second of refill buys one second of rate');
        self::assertFalse($client->admitBytes(1), 'and nothing beyond it until more time passes');
    }

    public function testABackwardClockCannotDrainTheByteMeter(): void
    {
        $now = 1000.0;
        $client = $this->clientOnScriptedClock($now);
        $now = 990.0;
        self::assertTrue($client->admitBytes((int) Client::BYTE_BURST), 'a clock jump backwards refills nothing and spends nothing');
    }

    public function testABackwardClockCannotDrainTheRequestBucket(): void
    {
        // CWPD-11: the same law for the request bucket. A clock that steps
        // backwards (NTP, a scripted harness, a container rebasing its
        // monotonic origin) must not be credited as negative elapsed time —
        // at base it drove the budget to 200 - 10*50 = -300 and every request
        // after the jump was refused until wall time caught up.
        $now = 1000.0;
        $client = $this->clientOnScriptedClock($now);
        $now = 990.0;
        self::assertTrue($client->admit(), 'a clock jump backwards neither refills nor drains the request bucket');
        self::assertTrue($client->admit(), 'and the burst is still there to spend');
    }

    /** A client whose two budgets move only when the test moves $now. */
    private function clientOnScriptedClock(float &$now): Client
    {
        $clock = static function () use (&$now): float {
            return $now;
        };
        $handler = new class () implements MessageHandler {
            public function onOpen(Connection $connection): void
            {
            }

            public function onMessage(Connection $connection, string $payload): void
            {
            }

            public function onClose(Connection $connection, int $code): void
            {
            }
        };
        $connection = new Connection('t1', 'bearer', new ThroughStream(), new ThroughStream(), $handler, ServerConfig::MAX_CLIENT_FRAME_BYTES);

        return new Client($connection, Outbox::for($connection), Scope::owner(), $clock);
    }
}
