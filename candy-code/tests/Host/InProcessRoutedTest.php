<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Host\InProcessHostServices;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Tests\Host\Support\CallDeadlineLoop;
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

    /** The loop the timed door arms its call deadlines on; the tests that need a timeout fire them. */
    private CallDeadlineLoop $deadlines;

    /** A second door whose call deadlines are recorded, not armed (made on first use by {@see timedDoor()}). */
    private ?InProcessHostServices $timed = null;

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
        $this->deadlines = new CallDeadlineLoop();
    }

    protected function tearDown(): void
    {
        if ($this->timed !== null) {
            $this->call($this->timed->disconnect());
        }
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
        $sinkId = $this->sinkOf($this->door)?->id();
        self::assertContains($sinkId, $this->rootClientIds($this->other), 'an answered subscribe registers the sink for its root');

        $this->call($this->door->disconnect());
        self::assertFalse($this->gateway->hasSubscribers($sessionId), 'the sink leaves the gateway with the client');
        self::assertNotContains($sinkId, $this->rootClientIds($this->other), 'and leaves the root clients with it');
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

    public function testARoutedRefusalIsTheChildHostsOwnRefusal(): void
    {
        $sessionId = $this->createOther();

        $refused = $this->fixture->await($this->door->respondPermission($sessionId, 'no-such-ask', 'once', null));

        self::assertInstanceOf(RefusedCall::class, $refused);
        self::assertSame('ask_not_found', $refused->refusalKind(), 'the child host answers the refusal, as the wire does');
    }

    public function testAHostKilledWhileATurnIsInFlightIsWorkspaceFailedAndTheDoorDoesNotReplayIt(): void
    {
        $sessionId = $this->createOther();
        $pid = $this->gateway->host($this->other)?->pid();
        self::assertIsInt($pid);

        // A stopped host cannot read the turn, so no answer can land before the kill.
        \posix_kill($pid, \SIGSTOP);
        $refusals = $this->refusalsOf($this->door->sendTurn($sessionId, 'in flight', 'queue', null, [], []));
        \posix_kill($pid, 9);

        self::assertTrue($this->runUntil(fn (): bool => \count($refusals) > 0, 10.0), 'the in-flight turn was never refused');
        self::assertCount(1, $refusals);
        self::assertSame('workspace_failed', $refusals[0]->refusalKind(), 'a host lost mid-call is workspace_failed, not dropped');
        self::assertSame(ErrorCode::Internal, $refusals[0]->errorCode);

        // The refusal is final: the door starts no host and sends no second session.send.
        $this->fixture->run(0.3);
        self::assertCount(1, $refusals, 'the refused turn is not replayed');
        self::assertNull($this->gateway->host($this->other), 'no retry started a fresh host for the turn');
        self::assertSame([], $this->startingOf(), 'no retry is starting a host either');
    }

    public function testARoutedCallAfterTheGatewayStopsIsRefusedBusyDraining(): void
    {
        $this->gateway->stop('test: draining');

        $refused = $this->fixture->await($this->door->createSessionIn($this->other, null, null));

        self::assertInstanceOf(RpcError::class, $refused, 'the gateway refusal passes through the door as the wire\'s RpcError');
        self::assertSame('draining', $refused->refusalKind());
        self::assertSame(ErrorCode::Busy, $refused->errorCode, 'busy is what the bundles\' retry rules key on');
    }

    public function testANinthRootIsRefusedBusyTooManyWorkspaces(): void
    {
        // Eight hosts still starting fill the gateway's host limit without starting eight real processes.
        $starting = new \ReflectionProperty(Gateway::class, 'starting');
        $filled = [];
        for ($i = 0; $i < Gateway::MAX_HOSTS; $i++) {
            $filled['/fill/' . $i] = (new Deferred())->promise();
        }
        $starting->setValue($this->gateway, $filled);

        $refused = $this->fixture->await($this->door->createSessionIn($this->other, null, null));
        $starting->setValue($this->gateway, []);

        self::assertInstanceOf(RpcError::class, $refused);
        self::assertSame('too_many_workspaces', $refused->refusalKind());
        self::assertSame(ErrorCode::Busy, $refused->errorCode);
    }

    public function testARoutedCreateRegistersTheSinkWithItsRootBeforeAnySubscribe(): void
    {
        $this->call($this->door->createSessionIn($this->other, null, null));

        $sink = $this->sinkOf($this->door);
        self::assertNotNull($sink, 'a routed call makes the client\'s sink');
        self::assertContains($sink->id(), $this->rootClientIds($this->other), 'the sink hears the root\'s server-scope events, as forward() does for a connection');
    }

    public function testAPrimarySessionIsNeverBoundToARoot(): void
    {
        $primary = (string) $this->call($this->door->createSession(null, null))['id'];

        self::assertNull($this->gateway->rootOf($primary), 'the primary dispatcher\'s ids stay out of sessionRoots (design Q7)');
        self::assertNull($this->gateway->rootFor((object) ['sessionId' => $primary]));
    }

    public function testAPrimaryCallSettlesBeforeItReturnsAndARoutedCallOnALaterTick(): void
    {
        $primary = (string) $this->call($this->door->createSession(null, null))['id'];
        $sessionId = $this->createOther();

        $primarySettled = false;
        $this->door->sessionSnapshot($primary, 10)->then(function () use (&$primarySettled): void {
            $primarySettled = true;
        });
        self::assertTrue($primarySettled, 'a primary call settles before its method returns');

        $routedSettled = false;
        $answer = null;
        $rejection = null;
        $this->door->sessionSnapshot($sessionId, 10)->then(function (mixed $value) use (&$routedSettled, &$answer): void {
            $routedSettled = true;
            $answer = $value;
        }, function (\Throwable $e) use (&$routedSettled, &$rejection): void {
            $routedSettled = true;
            $rejection = $e;
        });
        self::assertFalse($routedSettled, 'a routed call never settles synchronously');

        // An arrow function would copy $routedSettled at creation, so the condition reads it by reference.
        $ran = $this->runUntil(static function () use (&$routedSettled): bool {
            return $routedSettled;
        }, 10.0);
        self::assertTrue($ran, 'the routed call settles on a later tick: ' . ($rejection instanceof \Throwable ? \get_class($rejection) . ' ' . $rejection->getMessage() : 'no answer'));
        self::assertIsArray($answer);
        self::assertSame($this->other, $answer['root']);
        self::assertSame(0, $this->inFlightOf($this->other), 'the answer returns the root\'s in-flight count to zero');
    }

    public function testSessionDeleteTellsTheSinkTheSessionIsNoLongerFollowed(): void
    {
        $sessionId = $this->createOther();
        $this->call($this->door->subscribeSession($sessionId, null, null));
        self::assertContains($sessionId, $this->call($this->door->followedSessions()));

        $this->call($this->gateway->callRoot($this->other, 'session.delete', (object) ['sessionId' => $sessionId]));

        self::assertFalse($this->gateway->hasSubscribers($sessionId), 'the gateway drops the deleted session\'s subscribers');
        self::assertNotContains($sessionId, $this->call($this->door->followedSessions()), 'the sink no longer lists the deleted session');
    }

    public function testAnUnansweredRoutedCallIsRefusedOnceAndItsLateAnswerIsDropped(): void
    {
        $sessionId = $this->createOther();
        $timed = $this->timedDoor();
        $refusals = $this->refusalsOf($timed->sessionSnapshot($sessionId, 10));

        self::assertSame(1, $this->deadlines->fireDeadlines(), 'the routed call armed one 20 s deadline');
        self::assertCount(1, $refusals, 'the caller is refused when the deadline passes');
        self::assertSame('timeout', $refusals[0]->refusalKind());
        self::assertSame(ErrorCode::Busy, $refusals[0]->errorCode);

        self::assertTrue($this->runUntil(fn (): bool => $this->inFlightOf($this->other) === 0), 'the late answer lands and the gateway settles its call');
        self::assertCount(1, $refusals, 'the late answer does not settle the caller a second time');
    }

    public function testALateSubscribeIsCompensatedOnTheHostAndRecordsNoSink(): void
    {
        $sessionId = $this->createOther();
        $timed = $this->timedDoor();
        $refusals = $this->refusalsOf($timed->subscribeSession($sessionId, null, null));

        $this->deadlines->fireDeadlines();
        self::assertCount(1, $refusals);
        self::assertSame('timeout', $refusals[0]->refusalKind());
        self::assertTrue($this->runUntil(fn (): bool => $this->inFlightOf($this->other) === 0), 'the late subscribe answer lands');

        self::assertFalse($this->gateway->hasSubscribers($sessionId), 'the late answer records no subscriber');
        self::assertSame([], $this->call($timed->followedSessions()));
        // The main door's sink is a client of the root once its own create ran; only the refused subscribe's sink must not be.
        $timedSink = $this->sinkOf($timed);
        self::assertNotNull($timedSink);
        self::assertNotContains($timedSink->id(), $this->rootClientIds($this->other), 'a refused subscribe leaves no root client behind');
        $after = (array) $this->call($this->gateway->callRoot($this->other, 'session.unsubscribe', (object) ['sessionId' => $sessionId]));
        self::assertFalse($after['unsubscribed'] ?? true, 'the compensating unsubscribe already left the host with no subscription');
    }

    public function testAnOverlappingSubscribeKeepsTheLaterSubscription(): void
    {
        $sessionId = $this->createOther();
        $timed = $this->timedDoor();
        $refusals = $this->refusalsOf($timed->subscribeSession($sessionId, null, null));
        $this->deadlines->fireDeadlines();
        self::assertCount(1, $refusals, 'the first subscribe is refused while its answer is still on its way');

        $second = $this->call($timed->subscribeSession($sessionId, null, null));

        self::assertIsArray($second);
        self::assertSame($this->other, $second['root']);
        self::assertTrue($this->runUntil(fn (): bool => $this->inFlightOf($this->other) === 0), 'both host calls have answered');
        self::assertSame([$sessionId], $this->call($timed->followedSessions()), 'the sink follows the session through the later subscribe');
        self::assertTrue($this->gateway->hasSubscribers($sessionId));
        $kept = (array) $this->call($this->gateway->callRoot($this->other, 'session.unsubscribe', (object) ['sessionId' => $sessionId]));
        self::assertTrue($kept['unsubscribed'] ?? false, 'the late first answer sent no compensation that would cancel the later subscribe');
    }

    public function testALateCreateIsDeletedOnTheHostSoARetryCreatesOneSession(): void
    {
        $timed = $this->timedDoor();
        $refusals = $this->refusalsOf($timed->createSessionIn($this->other, null, null));

        $this->deadlines->fireDeadlines();
        self::assertCount(1, $refusals);
        self::assertSame('timeout', $refusals[0]->refusalKind());
        self::assertTrue($this->runUntil(fn (): bool => $this->inFlightOf($this->other) === 0), 'the late create answer and its compensation land');

        $listed = (array) $this->call($this->gateway->callRoot($this->other, 'session.list', (object) []));
        self::assertSame([], $listed['items'] ?? [], 'the late session was deleted, so a retry is the only one the host holds');
    }

    public function testARoutedCallTouchesTheIdleClockOfItsHost(): void
    {
        $sessionId = $this->createOther();
        $this->fixture->run(1.1);
        $host = $this->gateway->host($this->other);
        self::assertNotNull($host);
        self::assertGreaterThan(1.0, $host->idleSeconds(), 'the host has been idle for the wait');

        $this->call($this->door->sessionSnapshot($sessionId, 10));

        self::assertLessThan(1.0, $host->idleSeconds(), 'the routed call touched the host idle clock');
    }

    public function testTheSubscribeGenerationRowsStayWithinTheirCap(): void
    {
        (new \ReflectionProperty(Gateway::class, 'maxSessionRoots'))->setValue($this->gateway, 2);

        foreach (['no-such-1', 'no-such-2', 'no-such-3', 'no-such-4', 'no-such-5'] as $sessionId) {
            $this->fixture->await($this->gateway->callRoot($this->other, 'session.subscribe', (object) ['sessionId' => $sessionId]));
        }

        $rows = (new \ReflectionProperty(Gateway::class, 'subscribeGen'))->getValue($this->gateway);
        self::assertSame(['no-such-4', 'no-such-5'], \array_map('strval', \array_keys($rows)), 'the oldest rows go first, and the cap holds');
    }

    /** A door whose 20 s call deadlines are fired by the test, connected on first use. */
    private function timedDoor(): InProcessHostServices
    {
        if ($this->timed === null) {
            $this->timed = InProcessHostServices::new($this->fixture->dispatcher, $this->deadlines, $this->gateway);
            $this->call($this->timed->connect());
        }

        return $this->timed;
    }

    /** A session on the other root, created through the door. */
    private function createOther(): string
    {
        return (string) $this->call($this->door->createSessionIn($this->other, null, null))['id'];
    }

    /**
     * Collects what a promise rejects with, without throwing at the test; it stays empty for an answer.
     * The rejection may arrive after this returns, so the collector is shared by reference, not copied.
     *
     * @return \ArrayObject<int, RpcError>
     */
    private function refusalsOf(PromiseInterface $promise): \ArrayObject
    {
        $refusals = new \ArrayObject();
        $promise->then(null, function (\Throwable $e) use ($refusals): void {
            $refusals[] = $e;
        });

        return $refusals;
    }

    private function inFlightOf(string $root): int
    {
        $counts = (new \ReflectionProperty(Gateway::class, 'inFlight'))->getValue($this->gateway);

        return (int) ($counts[$root] ?? 0);
    }

    /** @return array<mixed> the starting-host table: a call for a root in it waits on that start */
    private function startingOf(): array
    {
        return (array) (new \ReflectionProperty(Gateway::class, 'starting'))->getValue($this->gateway);
    }

    /** @return list<string> the target ids registered for $root */
    private function rootClientIds(string $root): array
    {
        $clients = (new \ReflectionProperty(Gateway::class, 'rootClients'))->getValue($this->gateway);

        return \array_map('strval', \array_keys($clients[$root] ?? []));
    }

    private function sinkOf(InProcessHostServices $door): ?\SugarCraft\Code\Host\InProcessEventSink
    {
        return (new \ReflectionProperty(InProcessHostServices::class, 'sink'))->getValue($door);
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
