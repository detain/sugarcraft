<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Host\InProcessHostServices;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;
use SugarCraft\Plugins\Host\RefusedCall;

/**
 * PL-5c-A: the in-process door against a real workspace host of another root, through the
 * gateway's child socket (no loopback dial, no frame of the door's own). Needs pcntl and posix,
 * as WorkspaceHostTest does.
 */
final class InProcessRoutedTest extends TestCase
{
    private ProtocolFixture $fixture;

    private Gateway $gateway;

    private InProcessHostServices $door;

    private string $other;

    /** @var list<array<string, mixed>> the envelopes the door's listener heard */
    private array $heard = [];

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('workspace hosts run forked turns: needs ext-pcntl and ext-posix');
        }
        $this->fixture = ProtocolFixture::new();
        $this->other = $this->fixture->dir . '/other';
        $home = $this->fixture->dir . '/home';
        \mkdir($this->other, 0o700, true);
        \mkdir($home, 0o700, true);
        $this->other = (string) \realpath($this->other);

        $this->gateway = Gateway::new(
            $this->fixture->dispatcher,
            ServerConfig::new($this->fixture->dir)->withRoot($this->fixture->root),
            'test',
            Loop::get(),
            $this->fixture->dir . '/run',
            ['HOME' => $home, 'CANDY_CODE_PROVIDER' => 'echo'],
        );
        $this->gateway->start();
        $this->door = InProcessHostServices::new($this->fixture->dispatcher, Loop::get(), $this->gateway);
        $this->call($this->door->connect());
        $this->call($this->door->subscribeEvents(function (array $envelope): void {
            $this->heard[] = $envelope;
        }));
    }

    protected function tearDown(): void
    {
        $this->call($this->door->disconnect());
        $this->gateway->stop('test over');
        $this->fixture->tearDown();
    }

    public function testCreateSessionInBindsTheIdSoLaterCallsNeedNoRoot(): void
    {
        $created = $this->call($this->door->createSessionIn($this->other, null, null));

        self::assertIsArray($created, 'a routed answer is an array, as a primary one is');
        self::assertSame($this->other, $created['root']);
        $sessionId = (string) $created['id'];
        self::assertSame($this->other, $this->gateway->rootOf($sessionId));

        $snapshot = $this->call($this->door->sessionSnapshot($sessionId, 10));
        self::assertIsArray($snapshot);
        self::assertSame($this->other, $snapshot['root']);
    }

    public function testARoutedTurnStreamsBackThroughTheSinkWithItsRoot(): void
    {
        $sessionId = (string) $this->call($this->door->createSessionIn($this->other, null, null))['id'];
        $followed = $this->call($this->door->subscribeSession($sessionId, null, null));
        self::assertIsArray($followed);
        self::assertSame($this->other, $followed['root']);
        self::assertContains($sessionId, $this->call($this->door->followedSessions()));

        $sent = $this->call($this->door->sendTurn($sessionId, 'hello from the door', 'queue', null, [], []));
        self::assertSame('started', $sent['admitted']);

        self::assertTrue($this->runUntil(fn (): bool => $this->eventsOf('turn.completed') !== [], 30.0), 'the routed turn never completed');
        $completed = $this->eventsOf('turn.completed')[0];
        self::assertSame($sessionId, $completed['sessionId']);
        self::assertSame($this->other, $completed['root'], 'a routed event names its root');
        self::assertSame([], $this->eventsOf('server.overflow'));

        $this->call($this->door->unsubscribeSession($sessionId));
        self::assertFalse($this->gateway->hasSubscribers($sessionId));
    }

    public function testPendingPermissionsInAnswersFromTheRootWithTheTopLevelRoot(): void
    {
        $pending = $this->call($this->door->pendingPermissionsIn($this->other));

        self::assertIsArray($pending);
        self::assertSame($this->other, $pending['root']);
    }

    public function testARootRefusalRejectsTheCallAndNeverThrowsAtTheCaller(): void
    {
        foreach ([
            fn (): PromiseInterface => $this->door->createSessionIn($this->fixture->dir . '/no/such/dir', null, null),
            fn (): PromiseInterface => $this->door->pendingPermissionsIn($this->fixture->dir . '/no/such/dir'),
        ] as $call) {
            $refusal = null;
            try {
                $value = $this->fixture->await($call());
            } catch (\Throwable $thrown) {
                self::fail('a routing refusal must reject the promise, not throw: ' . $thrown->getMessage());
            }
            self::assertInstanceOf(RefusedCall::class, $value);
            $refusal = $value;
            self::assertSame('root_not_found', $refusal->refusalKind());
        }
    }

    public function testDisconnectLeavesNoRoutedSubscriptionAndNoFurtherEvent(): void
    {
        $sessionId = (string) $this->call($this->door->createSessionIn($this->other, null, null))['id'];
        $this->call($this->door->subscribeSession($sessionId, null, null));
        self::assertContains($sessionId, $this->call($this->door->followedSessions()));

        $this->call($this->door->disconnect());
        self::assertFalse($this->gateway->hasSubscribers($sessionId), 'the sink leaves the gateway with the client');
        self::assertSame([], $this->call($this->door->followedSessions()));
        self::assertFalse($this->call($this->door->isConnected()));
    }

    public function testAnIdNoDispatcherHoldsIsStillSessionNotFound(): void
    {
        $refused = $this->fixture->await($this->door->sendTurn('no-such-session', 'hi', 'queue', null, [], []));

        self::assertInstanceOf(RefusedCall::class, $refused);
        self::assertSame('session_not_found', $refused->refusalKind());
    }

    public function testAKilledHostIsAnnouncedToTheSinkAndTheNextCallStartsAFreshHost(): void
    {
        $sessionId = (string) $this->call($this->door->createSessionIn($this->other, null, null))['id'];
        $this->call($this->door->subscribeSession($sessionId, null, null));
        $pid = $this->gateway->host($this->other)?->pid();
        self::assertIsInt($pid);

        \posix_kill($pid, 9);
        self::assertTrue($this->runUntil(fn (): bool => $this->gateway->host($this->other) === null, 10.0), 'the gateway never noticed the host died');
        $overflow = $this->eventsOf('server.overflow');
        self::assertCount(1, $overflow, 'the sink hears the host loss as a resubscribe notice');
        self::assertSame(['sessionId' => $sessionId, 'dropped' => 0, 'action' => 'resubscribe'], $overflow[0]['data']);
        self::assertSame($this->other, $overflow[0]['root']);

        $again = $this->call($this->door->subscribeSession($sessionId, null, null));
        self::assertIsArray($again, 'the next call starts a fresh host for the root');
        self::assertNotSame($pid, $this->gateway->host($this->other)?->pid());
    }

    /** @return mixed the value, or the throwable the call rejected with */
    private function call(PromiseInterface $promise): mixed
    {
        $value = $this->fixture->await($promise);
        if ($value instanceof \Throwable) {
            throw $value;
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    private function eventsOf(string $type): array
    {
        return \array_values(\array_filter($this->heard, static fn (array $e): bool => ($e['type'] ?? null) === $type));
    }

    private function runUntil(\Closure $condition, float $timeout = 10.0): bool
    {
        $deadline = \microtime(true) + $timeout;
        while (!$condition()) {
            if (\microtime(true) >= $deadline) {
                return false;
            }
            $this->fixture->run(0.02);
        }

        return true;
    }
}
