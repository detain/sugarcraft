<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Host\InProcessEventSink;
use SugarCraft\Code\Host\InProcessHostServices;
use SugarCraft\Code\Host\RemoteSessionHost;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Server\Workspace\RelayTarget;
use SugarCraft\Code\Server\Workspace\WorkspaceHostClient;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * PL-5c-A: the gateway's side of the routed event sink (PL-5c-A design, sections 3.1 to 3.5).
 * The events go through the gateway's own relay() and lost() paths, driven by reflection, and
 * the hosts are fakes: a workspace host with a stub RPC that stop() closes without a socket, so
 * stop() runs its exit listeners the way a real stop does. No child process is started here.
 */
final class GatewayEventSinkTest extends TestCase
{
    /** Fixed seeds for the interleaving property (design 5.3): a failure replays from its seed. */
    private const SEEDS = [11, 47, 2024];

    private ProtocolFixture $fixture;

    private Gateway $gateway;

    private int $sinkSeq = 0;

    /** @var list<InProcessEventSink> every sink sink() built, closed in tearDown() */
    private array $sinks = [];

    /** @var array<string, list<array<string, mixed>>> what each sink's listener heard, by sink id */
    private array $heardBy = [];

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
        // The interleaving leaves earlier seeds' sinks subscribed and holding hundreds of
        // undelivered entries, which the fixture's 1 ms teardown run would drain one budget per
        // zero-delay timer. Under load that run stops mid-drain with a re-armed timer still on
        // the shared loop, and the residue guard fails the shard. A closed sink drops its queue
        // and never re-arms, so the teardown run only consumes the pending future ticks.
        foreach ($this->sinks as $sink) {
            $sink->close();
        }
        $this->fixture->tearDown();
    }

    public function testStopSendsEachFollowedSinkAResubscribeNoticeAndRefusesANewCall(): void
    {
        $root = $this->root('stop');
        $host = $this->fakeHost($root);
        $this->setHosts([$root => $host]);
        $sink = $this->sink();
        $this->follow($sink, $root, 'sess-a');

        $this->gateway->stop('test over');
        $sink->flush();

        self::assertSame(
            [['sessionId' => 'sess-a', 'dropped' => 0, 'action' => 'resubscribe', 'root' => $root]],
            $this->overflowsOf($sink),
            'the stop tells the sink to resubscribe, once, naming its root',
        );
        self::assertSame([], $sink->followedSessions(), 'the lost session is no longer followed');
        self::assertFalse($this->gateway->hasSubscribers('sess-a'));

        $refusal = $this->fixture->await($this->gateway->callRoot($root, 'session.list', (object) []));
        self::assertInstanceOf(RpcError::class, $refusal);
        self::assertSame('draining', $refusal->refusalKind(), 'a resubscribe made during the stop is refused');
        self::assertSame(0, $this->cursorFor($sink->id(), 'sess-a'), 'the stop moves no cursor');
    }

    public function testLostNoticesReachEverySinkFollowingALostHostsSessionsOnly(): void
    {
        $rootA = $this->root('lost-a');
        $rootB = $this->root('lost-b');
        $hostA = $this->fakeHost($rootA);
        $hostB = $this->fakeHost($rootB);
        $this->setHosts([$rootA => $hostA, $rootB => $hostB]);
        $first = $this->sink();
        $second = $this->sink();
        $other = $this->sink();
        $this->follow($first, $rootA, 'a1');
        $this->follow($second, $rootA, 'a1');
        $this->follow($other, $rootB, 'b1');

        (new \ReflectionMethod(Gateway::class, 'lost'))->invoke($this->gateway, $rootA, $hostA);
        foreach ([$first, $second, $other] as $sink) {
            $sink->flush();
        }

        $notice = [['sessionId' => 'a1', 'dropped' => 0, 'action' => 'resubscribe', 'root' => $rootA]];
        self::assertSame($notice, $this->overflowsOf($first));
        self::assertSame($notice, $this->overflowsOf($second));
        self::assertSame([], $this->overflowsOf($other), 'a host that did not lose the session sends its sink nothing');
        self::assertSame([], $first->followedSessions());
        self::assertSame(['b1'], $other->followedSessions());
        self::assertFalse($this->gateway->hasSubscribers('a1'));
        self::assertTrue($this->gateway->hasSubscribers('b1'));
    }

    public function testARefusingTargetIsSkippedBeforeItsCursorMoves(): void
    {
        $root = $this->root('accepts');
        $this->bind('s9', $root);
        $refusedLog = new \ArrayObject();
        $acceptedLog = new \ArrayObject();
        $this->gateway->attachSubscriber('s9', $this->target('refuses', false, $refusedLog));
        $this->gateway->attachSubscriber('s9', $this->target('accepts', true, $acceptedLog));

        $this->relayDurable($root, 's9', 5);

        self::assertCount(0, $refusedLog, 'a target that refuses the session hears nothing');
        self::assertNull($this->cursorFor('refuses', 's9'), 'the refused target moves no cursor, because accepts() comes first');
        self::assertSame([5], $acceptedLog->getArrayCopy(), 'the accepting target hears the event');
        self::assertSame(5, $this->cursorFor('accepts', 's9'));
    }

    public function testTheSinkCursorFollowsWhatIsQueuedAndRewindsToWhatWasDeliveredOnOverflow(): void
    {
        $root = $this->root('cursor');
        $sink = $this->sink();
        $this->follow($sink, $root, 'sc');

        for ($seq = 1; $seq <= 3; $seq++) {
            $this->relayDurable($root, 'sc', $seq);
        }
        self::assertSame(3, $this->cursorFor($sink->id(), 'sc'), 'the cursor moves at queue time');
        $this->relayDurable($root, 'sc', 2);
        $sink->flush();
        self::assertSame([1, 2, 3], $this->seqsOf($sink, 'sc'), 'a re-relayed seq is queued once');

        // Fill the queue behind the three delivered seqs, then overflow it.
        $last = 3 + InProcessEventSink::QUEUE_BOUND;
        for ($seq = 4; $seq <= $last; $seq++) {
            $this->relayDurable($root, 'sc', $seq);
        }
        $this->relayDurable($root, 'sc', $last + 1);

        self::assertFalse($sink->accepts('sc'), 'the overflowed session is stopped until it resubscribes');
        self::assertSame(3, $this->cursorFor($sink->id(), 'sc'), 'the cursor rewinds to the last seq the listener received, not the last queued');
        $sink->flush();
        self::assertSame(
            [['sessionId' => 'sc', 'dropped' => InProcessEventSink::QUEUE_BOUND, 'action' => 'resubscribe', 'root' => $root]],
            \array_slice($this->overflowsOf($sink), -1),
            'the queued seqs are replaced by one notice counting them',
        );
    }

    /**
     * Regression for PL-5c-A design section 12 (impl review, finding 1): the cursor moves at queue
     * time, so inside the overflow window it runs ahead of delivery. An overflow must rewind it to
     * the last seq the listener received, never to a queued seq the listener has not seen.
     */
    public function testTheOverflowWindowCursorEqualsTheLastDeliveredSeq(): void
    {
        $root = $this->root('window');
        $sink = $this->sink();
        $this->follow($sink, $root, 'sw');

        $this->relayDurable($root, 'sw', 1);
        $sink->flush();
        self::assertSame([1], $this->seqsOf($sink, 'sw'));

        // Seqs 2 .. QUEUE_BOUND + 1 fill the queue undelivered, so the cursor is ahead of the listener.
        $last = 1 + InProcessEventSink::QUEUE_BOUND;
        for ($seq = 2; $seq <= $last; $seq++) {
            $this->relayDurable($root, 'sw', $seq);
        }
        self::assertSame($last, $this->cursorFor($sink->id(), 'sw'), 'the queue-time cursor runs ahead of delivery');

        $this->relayDurable($root, 'sw', $last + 1);

        self::assertFalse($sink->accepts('sw'), 'the overflowed session is stopped until it resubscribes');
        self::assertSame(1, $this->cursorFor($sink->id(), 'sw'), 'the overflow window rewinds to the last delivered seq, not the last queued');

        $sink->flush();
        self::assertSame([1], $this->seqsOf($sink, 'sw'), 'no undelivered queued seq reaches the listener after the overflow');
        self::assertSame(
            [['sessionId' => 'sw', 'dropped' => InProcessEventSink::QUEUE_BOUND, 'action' => 'resubscribe', 'root' => $root]],
            \array_slice($this->overflowsOf($sink), -1),
            'the undelivered window is replaced by one notice counting every removed envelope',
        );
    }

    public function testThePrimaryFeedDeliversAtMostTheBudgetPerTick(): void
    {
        $door = InProcessHostServices::new($this->fixture->dispatcher, Loop::get(), $this->gateway);
        $this->fixture->await($door->connect());
        $heard = [];
        $this->fixture->await($door->subscribeEvents(function (array $envelope) use (&$heard): void {
            $heard[] = $envelope;
        }));

        $total = InProcessEventSink::DELIVERY_BUDGET * 2 + 5;
        $enqueue = new \ReflectionMethod(InProcessHostServices::class, 'enqueue');
        for ($n = 0; $n < $total; $n++) {
            $enqueue->invoke($door, EventEnvelope::server(EventType::SERVER_OVERFLOW, ['sessionId' => 'p1', 'dropped' => $n, 'action' => 'resubscribe']));
        }

        (new \ReflectionMethod(InProcessHostServices::class, 'tick'))->invoke($door);
        self::assertCount(InProcessEventSink::DELIVERY_BUDGET, $heard, 'one primary tick delivers the budget and no more');

        $this->fixture->run(0.2);
        self::assertCount($total, $heard, 'the rest arrive on the later ticks');
        self::assertSame(
            \range(0, $total - 1),
            \array_map(static fn (array $e): int => (int) $e['data']['dropped'], $heard),
            'the primary feed keeps emit order across ticks',
        );
        $this->fixture->await($door->disconnect());
    }

    public function testSeededInterleavingsKeepEachSessionInSeqOrderAndNeverDeliverASeqTwice(): void
    {
        foreach (self::SEEDS as $seed) {
            $this->interleave($seed);
        }
    }

    /**
     * Two hosts (roots), three sessions, one sink. The seeded steps relay durable events in order,
     * re-relay an earlier seq, relay live deltas, and let the sink deliver on random budgets (the
     * stalls). Every session must come out as 1..n in order, each seq once.
     */
    private function interleave(int $seed): void
    {
        \mt_srand($seed);
        $rootA = $this->root('seed-a-' . $seed);
        $rootB = $this->root('seed-b-' . $seed);
        $roots = ['s1' => $rootA, 's2' => $rootA, 's3' => $rootB];
        $sink = $this->sink();
        foreach ($roots as $sessionId => $root) {
            $this->follow($sink, $root, (string) $sessionId);
        }
        $pump = new \ReflectionMethod(InProcessEventSink::class, 'pump');
        $next = ['s1' => 1, 's2' => 1, 's3' => 1];

        for ($step = 0; $step < 600; $step++) {
            $sessionId = (string) \array_keys($roots)[\mt_rand(0, 2)];
            $root = $roots[$sessionId];
            $roll = \mt_rand(0, 99);
            if ($roll < 55) {
                $this->relayDurable($root, $sessionId, $next[$sessionId]);
                $next[$sessionId]++;
            } elseif ($roll < 65) {
                // An earlier seq is already queued: the gateway cursor skips it, so it moves nothing.
                if ($next[$sessionId] > 1) {
                    $this->relayDurable($root, $sessionId, \mt_rand(1, $next[$sessionId] - 1));
                }
            } elseif ($roll < 85) {
                $this->relayLive($root, $sessionId, 'x' . $step);
            } else {
                $pump->invoke($sink, \mt_rand(1, 40));
            }
        }
        $sink->flush();

        foreach (\array_keys($roots) as $sessionId) {
            $expected = $next[$sessionId] > 1 ? \range(1, $next[$sessionId] - 1) : [];
            self::assertSame($expected, $this->seqsOf($sink, (string) $sessionId), \sprintf('seed %d: %s is delivered in seq order, each seq once', $seed, $sessionId));
        }
    }

    private function sink(): InProcessEventSink
    {
        $id = 'inproc:gw-' . (++$this->sinkSeq);

        return $this->sinks[] = new InProcessEventSink(
            $id,
            $this->gateway,
            Loop::get(),
            function (array $envelope) use ($id): void {
                $this->heardBy[$id][] = $envelope;
            },
        );
    }

    /** Makes $sink follow $sessionId of $root: the gateway's bindings, as a routed subscribe leaves them. */
    private function follow(InProcessEventSink $sink, string $root, string $sessionId): void
    {
        $this->gateway->useRoot($root, $sink);
        $this->gateway->attachSubscriber($sessionId, $sink);
        $this->bind($sessionId, $root);
        $sink->follow($sessionId, 0);
    }

    private function bind(string $sessionId, string $root): void
    {
        $property = new \ReflectionProperty(Gateway::class, 'sessionRoots');
        $roots = $property->getValue($this->gateway);
        $roots[$sessionId] = $root;
        $property->setValue($this->gateway, $roots);
    }

    /** @param array<string, WorkspaceHostClient> $hosts */
    private function setHosts(array $hosts): void
    {
        (new \ReflectionProperty(Gateway::class, 'hosts'))->setValue($this->gateway, $hosts);
    }

    private function root(string $name): string
    {
        $path = $this->fixture->dir . '/' . $name;
        \mkdir($path, 0o700, true);

        return (string) \realpath($path);
    }

    /**
     * A workspace host with no child. Its RPC is a stub that stop() closes without a socket, and
     * its exit listener is the gateway's own lost() for $root, as hostFor() registers it.
     */
    private function fakeHost(string $root): WorkspaceHostClient
    {
        $rpc = (new \ReflectionClass(RemoteSessionHost::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RemoteSessionHost::class, 'open'))->setValue($rpc, false);

        $hostClass = new \ReflectionClass(WorkspaceHostClient::class);
        $host = $hostClass->newInstanceWithoutConstructor();
        $hostClass->getProperty('running')->setValue($host, true);
        \Closure::bind(function () use ($rpc): void {
            $this->rpc = $rpc;
        }, $host, WorkspaceHostClient::class)();

        $lost = new \ReflectionMethod(Gateway::class, 'lost');
        $gateway = $this->gateway;
        $host->onExit(static function () use ($lost, $gateway, $root, $host): void {
            $lost->invoke($gateway, $root, $host);
        });

        return $host;
    }

    /** A relay target that accepts or refuses every session, and logs the seq of each event it is handed. */
    private function target(string $id, bool $accepts, \ArrayObject $log): RelayTarget
    {
        return new class ($id, $accepts, $log) implements RelayTarget {
            public function __construct(private readonly string $id, private readonly bool $accepts, private readonly \ArrayObject $log)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function isOpen(): bool
            {
                return true;
            }

            public function accepts(?string $sessionId): bool
            {
                return $this->accepts;
            }

            public function viewerSessions(): ?array
            {
                return null;
            }

            public function deliver(?array $message, string $text, ?string $coalesceKey, bool $ephemeral, ?int $seq, ?string $sessionId): void
            {
                $this->log[] = $seq;
            }

            public function unfollowed(string $sessionId): void
            {
            }
        };
    }

    private function relayDurable(string $root, string $sessionId, int $seq): void
    {
        $this->relay($root, ['sessionId' => $sessionId, 'type' => 'turn.started', 'durable' => true, 'seq' => $seq, 'data' => []]);
    }

    private function relayLive(string $root, string $sessionId, string $text): void
    {
        $this->relay($root, ['sessionId' => $sessionId, 'type' => 'assistant.delta', 'durable' => false, 'turnId' => 't1', 'data' => ['text' => $text]]);
    }

    /** One event from $root's host, through the gateway's own relay(), as its host callback delivers it. */
    private function relay(string $root, array $envelope): void
    {
        $payload = (string) \json_encode(['jsonrpc' => '2.0', 'method' => 'event', 'params' => $envelope]);
        (new \ReflectionMethod(Gateway::class, 'relay'))->invoke($this->gateway, $root, $envelope, $payload);
    }

    private function cursorFor(string $targetId, string $sessionId): ?int
    {
        $cursors = (new \ReflectionProperty(Gateway::class, 'cursors'))->getValue($this->gateway);

        return isset($cursors[$targetId][$sessionId]) ? (int) $cursors[$targetId][$sessionId] : null;
    }

    /** @return list<int> the durable seqs the sink's listener heard for $sessionId, in order */
    private function seqsOf(InProcessEventSink $sink, string $sessionId): array
    {
        $seqs = [];
        foreach ($this->heardBy[$sink->id()] ?? [] as $envelope) {
            if (($envelope['sessionId'] ?? null) === $sessionId && \is_int($envelope['seq'] ?? null)) {
                $seqs[] = $envelope['seq'];
            }
        }

        return $seqs;
    }

    /** @return list<array{sessionId: mixed, dropped: mixed, action: mixed, root: mixed}> the overflow notices the listener heard */
    private function overflowsOf(InProcessEventSink $sink): array
    {
        $notices = [];
        foreach ($this->heardBy[$sink->id()] ?? [] as $envelope) {
            if (($envelope['type'] ?? null) !== EventType::SERVER_OVERFLOW) {
                continue;
            }
            $notices[] = [
                'sessionId' => $envelope['data']['sessionId'],
                'dropped' => $envelope['data']['dropped'],
                'action' => $envelope['data']['action'],
                'root' => $envelope['root'] ?? null,
            ];
        }

        return $notices;
    }
}
