<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Host\InProcessHostServices;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\JsonRpc;
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

    public function testALateSubscribeLeavesAnotherSinksSubscriptionInPlace(): void
    {
        $sessionId = $this->createOther();
        $this->call($this->door->subscribeSession($sessionId, null, null));
        $timed = $this->timedDoor();
        $refusals = $this->refusalsOf($timed->subscribeSession($sessionId, null, null));

        $this->deadlines->fireDeadlines();
        self::assertCount(1, $refusals);
        self::assertSame('timeout', $refusals[0]->refusalKind());
        self::assertTrue($this->runUntil(fn (): bool => $this->inFlightOf($this->other) === 0), 'the late subscribe answer lands');

        // The main door's sink is a recorded subscriber, so the late answer must not compensate.
        self::assertTrue($this->gateway->hasSubscribers($sessionId), 'the main door\'s subscription is still recorded');
        $kept = (array) $this->call($this->gateway->callRoot($this->other, 'session.unsubscribe', (object) ['sessionId' => $sessionId]));
        self::assertTrue($kept['unsubscribed'] ?? false, 'no compensating unsubscribe cancelled the subscription another sink owns');
    }

    public function testServerScopeEventsReachOnlyTheSinksOfTheRootsTheyUsed(): void
    {
        $this->createOther();
        $used = $this->other;
        $unused = $this->fixture->dir . '/unused';
        $deleted = fn (string $root): array => \array_values(\array_filter(
            $this->heard,
            static fn (array $e): bool => ($e['type'] ?? null) === EventType::SESSION_DELETED && ($e['root'] ?? null) === $root,
        ));

        // A root-wide event is relayed to the clients of its own root only (no host needed: relay() is the gateway's seam).
        $event = EventEnvelope::server(EventType::SESSION_DELETED, ['id' => 'gone-1']);
        $relay = new \ReflectionMethod(Gateway::class, 'relay');
        $relay->invoke($this->gateway, $used, $event->toArray(), JsonRpc::encode($event->notification()));
        $relay->invoke($this->gateway, $unused, $event->toArray(), JsonRpc::encode($event->notification()));

        self::assertTrue($this->runUntil(fn (): bool => $deleted($used) !== [], 10.0), 'the used root\'s server-scope event never reached its sink');
        self::assertCount(1, $deleted($used));
        self::assertSame([], $deleted($unused), 'a root this client never used reaches none of its sinks');
    }

    public function testADisconnectFromASinkListenerSkipsTheLaterListenersOfThatEnvelope(): void
    {
        $sessionId = $this->createOther();
        $this->call($this->door->subscribeSession($sessionId, null, null));
        $disconnected = false;
        $laterHeard = [];
        $this->call($this->door->subscribeEvents(function (array $envelope) use (&$disconnected): void {
            if (!$disconnected && ($envelope['root'] ?? null) === $this->other) {
                $disconnected = true;
                $this->door->disconnect();
            }
        }));
        $this->call($this->door->subscribeEvents(function (array $envelope) use (&$laterHeard): void {
            if (($envelope['root'] ?? null) === $this->other) {
                $laterHeard[] = $envelope;
            }
        }));
        $this->call($this->door->subscribeEvents(function (array $envelope) use (&$laterHeard): void {
            if (($envelope['root'] ?? null) === $this->other) {
                $laterHeard[] = $envelope;
            }
        }));

        $this->call($this->door->sendTurn($sessionId, 'leave mid-turn', 'queue', null, [], []));
        // An arrow function would copy $disconnected at creation, so the condition reads it by reference.
        $gone = function () use (&$disconnected): bool {
            return $disconnected;
        };
        self::assertTrue($this->runUntil($gone, 30.0), 'the sink never delivered a routed event to the listener that disconnects');
        self::assertFalse($this->call($this->door->isConnected()));
        self::assertNull($this->sinkOf($this->door), 'the disconnect cleared the sink');
        self::assertSame([], $laterHeard, 'the listeners after the disconnecting one never hear the envelope it was delivering');
    }

    /** A door whose 20 s call deadlines are fired by the test, connected on first use. */
    public function testARoutedAnswerPastTheFrameCeilingIsRefusedTooLarge(): void
    {
        // The routed conversion is the one gate a routed answer takes before a bundle (the door's
        // wire() conversion, which every routed call shares). Driven directly: an end-to-end answer
        // past the ceiling is not practical in a test.
        $wire = new \ReflectionMethod(InProcessHostServices::class, 'wire');
        try {
            $wire->invoke(null, ['text' => \str_repeat('x', Dispatcher::MAX_SERVER_FRAME_BYTES)]);
            self::fail('an answer past the frame ceiling must be refused');
        } catch (RpcError $refusal) {
            self::assertSame('too_large', $refusal->refusalKind());
        }
        self::assertSame(['text' => 'ok'], $wire->invoke(null, ['text' => 'ok']), 'a small answer passes unchanged');
    }

    public function testARoutedAnswerNestedPastTheWireDecodeDepthIsRefusedTooDeep(): void
    {
        // Encodes under the value depth (511) but decodes past the wire client's depth (64): too_deep.
        $nested = 'leaf';
        for ($level = 0; $level < JsonRpc::MAX_DEPTH + 10; $level++) {
            $nested = ['n' => $nested];
        }
        $wire = new \ReflectionMethod(InProcessHostServices::class, 'wire');
        try {
            $wire->invoke(null, $nested);
            self::fail('an answer nested past the wire decode depth must be refused');
        } catch (RpcError $refusal) {
            self::assertSame('too_deep', $refusal->refusalKind());
        }
    }

    public function testARoutedSubscribeAnswerPastTheFrameCeilingIsCompensatedAndRecordsNoSink(): void
    {
        $this->assertRoutedSubscribeRefused(['text' => \str_repeat('x', Dispatcher::MAX_SERVER_FRAME_BYTES)], 'too_large');
    }

    public function testARoutedSubscribeAnswerNestedPastTheWireDecodeDepthIsCompensatedAndRecordsNoSink(): void
    {
        $nested = 'leaf';
        for ($level = 0; $level < JsonRpc::MAX_DEPTH + 10; $level++) {
            $nested = ['n' => $nested];
        }

        $this->assertRoutedSubscribeRefused($nested, 'too_deep');
    }

    /**
     * The host holds a subscription the door never records, as a late answer leaves behind. The
     * conversion refuses the answer, so the compensating unsubscribe must take that subscription back.
     */
    private function assertRoutedSubscribeRefused(mixed $answer, string $kind): void
    {
        $sessionId = $this->createOther();
        $this->call($this->gateway->callRoot($this->other, 'session.subscribe', (object) ['sessionId' => $sessionId]));
        $generation = $this->gateway->subscribeGeneration($sessionId);
        $sink = $this->sinkOf($this->door);
        self::assertNotNull($sink);

        $subscribeAnswer = new \ReflectionMethod(InProcessHostServices::class, 'subscribeAnswer');
        try {
            $subscribeAnswer->invoke($this->door, $this->other, $sessionId, null, $sink, $answer, $generation);
            self::fail('a routed subscribe answer the wire cannot carry must be refused');
        } catch (RpcError $refusal) {
            self::assertSame($kind, $refusal->refusalKind());
        }

        self::assertTrue($this->runUntil(fn (): bool => $this->inFlightOf($this->other) === 0), 'the compensating unsubscribe settled');
        self::assertFalse($this->gateway->hasSubscribers($sessionId), 'a refused subscribe records no subscriber');
        self::assertNotContains($sessionId, $this->call($this->door->followedSessions()), 'the sink follows nothing for the refused subscribe');
        $after = (array) $this->call($this->gateway->callRoot($this->other, 'session.unsubscribe', (object) ['sessionId' => $sessionId]));
        self::assertFalse($after['unsubscribed'] ?? true, 'the compensation took the host subscription back');
    }

    public function testTheDoorIsNeverRefusedViewerSessionDeniedOrOwnerOnly(): void
    {
        // Design 2.5: the in-process door is an owner with no connection, so the gateway's scope
        // law never applies to it. call() rethrows any refusal, so a refused call fails here.
        $sessionId = (string) $this->call($this->door->createSessionIn($this->other, null, null))['id'];
        $this->call($this->door->subscribeSession($sessionId, null, null));
        self::assertIsArray($this->call($this->door->sessionSnapshot($sessionId, 10)));
        self::assertIsArray($this->call($this->door->pendingPermissionsIn($this->other)));
        $this->call($this->door->unsubscribeSession($sessionId));
    }

    public function testAFailedHostStartRejectsTheRoutedCallWorkspaceFailed(): void
    {
        // The start-failure path: a host that cannot start rejects the call with workspace_failed,
        // and the gateway keeps no host for the root. The start table is set directly to a rejected start.
        $starting = new \ReflectionProperty(Gateway::class, 'starting');
        $starting->setValue($this->gateway, [$this->other => \React\Promise\reject(new \RuntimeException('the child would not start'))]);
        try {
            $answer = $this->fixture->await($this->gateway->callRoot($this->other, 'session.list', (object) []));
        } finally {
            $starting->setValue($this->gateway, []);
        }

        self::assertInstanceOf(RpcError::class, $answer);
        self::assertSame('workspace_failed', $answer->refusalKind());
        self::assertNotContains($this->other, $this->gateway->hostRoots());
        self::assertSame(0, $this->inFlightOf($this->other), 'the failed call leaves no in-flight count');
    }

    public function testAColdCallStartsTheRootsHostAndAnswersThroughIt(): void
    {
        self::assertNotContains($this->other, $this->gateway->hostRoots(), 'no host runs for the root yet');
        $answer = $this->call($this->gateway->callRoot($this->other, 'session.list', (object) []));

        self::assertNotNull($answer);
        self::assertContains($this->other, $this->gateway->hostRoots(), 'the cold call started the host');
    }

    public function testASendTurnFromInsideAListenerQueuesItsEventBehindTheOwedOnes(): void
    {
        $sessionId = (string) $this->call($this->door->createSessionIn($this->other, null, null))['id'];
        $this->call($this->door->subscribeSession($sessionId, null, null));
        $reentered = null;
        $depth = 0;
        $nested = false;
        $this->call($this->door->subscribeEvents(function (array $envelope) use (&$reentered, &$depth, &$nested, $sessionId): void {
            if ($depth > 0) {
                $nested = true;
            }
            $depth++;
            try {
                if (($envelope['type'] ?? null) === 'turn.completed' && $reentered === null) {
                    // Re-entrant: a routed call made from a delivery returns a promise and never runs the drain again.
                    $reentered = $this->door->sendTurn($sessionId, 'second', 'queue', null, [], []);
                }
            } finally {
                $depth--;
            }
        }));

        $first = $this->call($this->door->sendTurn($sessionId, 'first', 'queue', null, [], []));
        self::assertSame('started', $first['admitted']);
        self::assertTrue($this->runUntil(fn (): bool => \count($this->eventsOf('turn.completed')) >= 2, 40.0), 'the turn sent from inside the listener never completed');

        self::assertInstanceOf(PromiseInterface::class, $reentered, 'the listener made the re-entrant call');
        $second = $this->call($reentered);
        self::assertIsArray($second);
        self::assertSame('started', $second['admitted']);
        $completed = $this->eventsOf('turn.completed');
        self::assertSame([$sessionId, $sessionId], \array_column($completed, 'sessionId'), 'both turns completed, in order, on the routed session');
        self::assertSame([$first['turnId'], $second['turnId']], \array_column($completed, 'turnId'), 'the turns completed in the order they were sent');
        self::assertFalse($nested, 'the listener was never re-entered: the event sent from inside it waits for the outer delivery');

        // The re-entrant turn starts only after the turn whose delivery sent it has completed.
        $firstCompleted = $this->positionOf('turn.completed', $first['turnId']);
        $secondStarted = $this->positionOf('turn.started', $second['turnId']);
        self::assertNotNull($firstCompleted);
        self::assertNotNull($secondStarted, 'the second turn started on the routed session');
        self::assertLessThan($secondStarted, $firstCompleted, 'the event sent from inside the listener arrives after the owed ones');

        // Session events arrive in sequence order, so none of the owed events overtakes another.
        $seqs = [];
        foreach ($this->heard as $envelope) {
            if (($envelope['sessionId'] ?? null) === $sessionId && isset($envelope['seq'])) {
                $seqs[] = (int) $envelope['seq'];
            }
        }
        self::assertNotEmpty($seqs);
        for ($i = 1; $i < \count($seqs); $i++) {
            self::assertGreaterThan($seqs[$i - 1], $seqs[$i], 'the session events arrive in strictly increasing sequence');
        }
    }

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

    /** The index in the envelopes the door's listener heard of the first $type event for $turnId, or null. */
    private function positionOf(string $type, string $turnId): ?int
    {
        foreach ($this->heard as $index => $envelope) {
            if (($envelope['type'] ?? null) === $type && ($envelope['turnId'] ?? null) === $turnId) {
                return $index;
            }
        }

        return null;
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
