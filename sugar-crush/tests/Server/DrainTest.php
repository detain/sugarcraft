<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Tests\Server\Support\ProtocolFixture;

/**
 * A stopping server drains (Appendix O §4.6, `server.drainSeconds`): it
 * admits no new turn or session, tells every client how long it will wait,
 * and stops once the turns in flight have settled — or when the time is up,
 * whichever is first. Asking again cuts the wait short.
 */
final class DrainTest extends TestCase
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

    public function testADrainWaitsForTheRunningTurnThenStops(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'finish this']);
        $outcome = null;

        $this->fixture->dispatcher->drain(5.0, static function (bool $settled) use (&$outcome): void {
            $outcome = $settled;
        }, 'SIGTERM');

        self::assertNull($outcome, 'a turn is running, so the stop waits');
        self::assertTrue($this->fixture->dispatcher->isDraining());
        self::assertTrue($client->call('server.health')['draining']);
        self::assertSame('draining', $client->request('session.send', ['sessionId' => $sessionId, 'text' => 'one more'])['error']['data']['kind']);
        $shutdown = $client->events('server.shutdown');
        self::assertSame(['reason' => 'SIGTERM', 'graceSeconds' => 5], $shutdown[0]['data'] ?? null);

        $this->fixture->backend->settle(Message::assistant('done'));
        $this->fixture->run(0.3);

        self::assertTrue($outcome);
        self::assertFalse($this->fixture->dispatcher->isDraining());
    }

    public function testADrainThatRunsOutOfTimeStopsAnyway(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'never ends']);
        $outcome = null;

        $this->fixture->dispatcher->drain(0.15, static function (bool $settled) use (&$outcome): void {
            $outcome = $settled;
        });
        $this->fixture->run(0.4);

        self::assertFalse($outcome, 'told that a turn is still running');
    }

    public function testAskingAgainCutsTheWaitShort(): void
    {
        $client = $this->fixture->client();
        $sessionId = $this->fixture->session($client);
        $client->call('session.send', ['sessionId' => $sessionId, 'text' => 'long']);
        $calls = [];
        $then = static function (bool $settled) use (&$calls): void {
            $calls[] = $settled;
        };

        $this->fixture->dispatcher->drain(60.0, $then);
        $this->fixture->dispatcher->drain(60.0, $then);

        self::assertSame([false], $calls);
        self::assertFalse($this->fixture->dispatcher->isDraining());
    }

    public function testAnIdleServerStopsAtOnce(): void
    {
        $outcome = null;

        $this->fixture->dispatcher->drain(10.0, static function (bool $settled) use (&$outcome): void {
            $outcome = $settled;
        });

        self::assertTrue($outcome);
        self::assertTrue($this->fixture->context->isDraining(), 'and admits nothing new meanwhile');
    }
}
