<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Host\InProcessEventSink;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Tests\Server\Support\ProtocolFixture;

/**
 * PL-5c-A: the routed-event sink on its own, with the gateway only as its cursor table.
 * The events are handed to deliver() directly, shaped as the gateway's relay builds them.
 */
final class InProcessEventSinkTest extends TestCase
{
    private ProtocolFixture $fixture;

    private Gateway $gateway;

    /** @var list<array<string, mixed>> the envelopes the listeners heard, in order */
    private array $heard = [];

    private ?InProcessEventSink $last = null;

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

    public function testOwedEventsReachTheListenerInSeqOrderAndAFlushDeliversThemAll(): void
    {
        $sink = $this->sink();
        $sink->follow('s1', 0);
        for ($seq = 1; $seq <= 3; $seq++) {
            $this->deliverDurable($sink, 's1', $seq);
        }
        $sink->flush();

        self::assertSame([1, 2, 3], $this->seqsOf('s1'));
        self::assertSame('/r', $this->heard[0]['root'], 'a routed event names its root');
        self::assertSame([], $this->heard[0]['data'], 'an empty data is an array, not an object');
    }

    public function testTheDeliveryBudgetCapsEachTickAndTheRestArmsTheNextTick(): void
    {
        $sink = $this->sink();
        $sink->follow('s1', 0);
        $total = InProcessEventSink::DELIVERY_BUDGET * 2 + 10;
        for ($seq = 1; $seq <= $total; $seq++) {
            $this->deliverDurable($sink, 's1', $seq);
        }
        // One pump is one tick's worth of delivery: it stops at the budget and leaves the rest queued.
        (new \ReflectionMethod(InProcessEventSink::class, 'pump'))->invoke($sink, InProcessEventSink::DELIVERY_BUDGET);
        self::assertCount(InProcessEventSink::DELIVERY_BUDGET, $this->heard, 'one tick delivers at most the budget');

        // The loop's own ticks deliver the rest, in order.
        $this->fixture->run(0.2);
        self::assertSame(\range(1, $total), $this->seqsOf('s1'), 'every queued event arrives, in order, across ticks');
    }

    public function testLiveDeltasOverTheByteBoundCoalesceIntoOneEventWithTheirTextInOrder(): void
    {
        $sink = $this->sink();
        $sink->follow('s1', 0);
        $chunk = \str_repeat('x', 300_000);
        $parts = [];
        for ($n = 1; $n <= 6; $n++) {
            $text = $chunk . $n;
            $parts[] = $text;
            $this->deliverLive($sink, 's1', $text);
        }
        $sink->flush();

        $deltas = \array_values(\array_filter($this->heard, static fn (array $e): bool => ($e['type'] ?? null) === 'assistant.delta'));
        self::assertLessThan(6, \count($deltas), 'over the bound the deltas merge');
        self::assertSame(\implode('', $parts), \implode('', \array_map(static fn (array $e): string => (string) $e['data']['text'], $deltas)), 'no text is lost or reordered');
    }

    public function testADurableOverflowStopsTheSessionCountsWhatWasRemovedAndRewindsTheCursor(): void
    {
        $sink = $this->sink();
        $sink->follow('s1', 0);
        for ($seq = 1; $seq <= InProcessEventSink::QUEUE_BOUND; $seq++) {
            $this->deliverDurable($sink, 's1', $seq);
        }
        $this->deliverDurable($sink, 's1', InProcessEventSink::QUEUE_BOUND + 1);

        self::assertFalse($sink->accepts('s1'), 'the session is stopped until it resubscribes');
        self::assertTrue($sink->accepts('s2'), 'other sessions are unaffected');
        $sink->flush();

        self::assertCount(1, $this->heard, 'the listener hears one overflow notice, and nothing else');
        self::assertSame('server.overflow', $this->heard[0]['type']);
        self::assertSame(['sessionId' => 's1', 'dropped' => InProcessEventSink::QUEUE_BOUND, 'action' => 'resubscribe'], $this->heard[0]['data']);
        self::assertSame(0, $this->cursorOf('s1'), 'the cursor is rewound to the last seq the listener received');
    }

    public function testAnotherSessionKeepsFlowingThroughAnOverflowOfOneSession(): void
    {
        $sink = $this->sink();
        $sink->follow('s1', 0);
        $sink->follow('s2', 0);
        $this->deliverDurable($sink, 's2', 1);
        for ($seq = 1; $seq < InProcessEventSink::QUEUE_BOUND; $seq++) {
            $this->deliverDurable($sink, 's1', $seq);
        }
        $this->deliverDurable($sink, 's1', InProcessEventSink::QUEUE_BOUND);
        $this->deliverDurable($sink, 's2', 2);
        $sink->flush();

        self::assertSame(['s2:1', 'overflow:s1:' . (InProcessEventSink::QUEUE_BOUND - 1), 's2:2'], $this->trace());
    }

    public function testAResubscribeFromTheCursorDeliversEachSeqOnceWithNoGapAndNoDuplicate(): void
    {
        $sink = $this->sink();
        $sink->follow('s1', 0);
        $this->deliverDurable($sink, 's1', 1);
        $this->deliverDurable($sink, 's1', 2);
        // The listener never drained them: a re-follow from the cursor it applied (0) replays them.
        $sink->follow('s1', 0);
        $this->deliverDurable($sink, 's1', 1);
        $this->deliverDurable($sink, 's1', 2);
        $sink->flush();

        self::assertSame([1, 2], $this->seqsOf('s1'), 'the purge leaves each seq once');
    }

    public function testAClosedSinkDeliversNothingFurther(): void
    {
        $sink = $this->sink();
        $sink->follow('s1', 0);
        $this->deliverDurable($sink, 's1', 1);
        $sink->close();
        $this->deliverDurable($sink, 's1', 2);
        $sink->flush();

        self::assertFalse($sink->isOpen());
        self::assertSame([], $this->heard);
    }

    public function testUnfollowForgetsTheSessionAndItsQueuedEvents(): void
    {
        $sink = $this->sink();
        $sink->follow('s1', 0);
        $sink->follow('s2', 0);
        $this->deliverDurable($sink, 's1', 1);
        $this->deliverDurable($sink, 's2', 1);
        $sink->unfollow('s1');
        $sink->flush();

        self::assertSame(['s2:1'], $this->trace());
        self::assertSame(['s2'], $sink->followedSessions());
    }

    public function testADurableEventShedsAQueuedDeltaBeforeItOverflowsTheSession(): void
    {
        $sink = $this->sink();
        $sink->follow('s1', 0);
        // Fill the queue with live deltas only, each under its own text so none coalesces (the byte bound is not reached).
        for ($n = 1; $n <= InProcessEventSink::QUEUE_BOUND; $n++) {
            $this->deliverLive($sink, 's1', 'd' . $n);
        }
        $this->deliverDurable($sink, 's1', 1);

        self::assertTrue($sink->accepts('s1'), 'a delta made room, so the session did not overflow');
        self::assertTrue($sink->isOpen(), 'the sink stays open');
        $sink->flush();

        self::assertSame([1], $this->seqsOf('s1'), 'the durable event is queued behind the deltas and delivered');
        self::assertSame('server.overflow', $this->heard[0]['type'], 'the shed is reported first, at the first shed position');
        self::assertSame(['sessionId' => 's1', 'dropped' => 1, 'action' => 'resubscribe'], $this->heard[0]['data']);
        self::assertSame('s1', $this->heard[\count($this->heard) - 1]['sessionId'], 'the durable event is the last one heard');
        self::assertSame(InProcessEventSink::QUEUE_BOUND - 1 + 2, \count($this->heard), 'the notice, the 2047 kept deltas and the durable event');
    }

    public function testALaterLiveShedAddsNoSecondNoticeAndCountsEveryShed(): void
    {
        $sink = $this->sink();
        $sink->follow('s1', 0);
        // Distinct texts, so no delta coalesces: the queue is full of deltas, and the next two shed the oldest.
        for ($n = 1; $n <= InProcessEventSink::QUEUE_BOUND; $n++) {
            $this->deliverLive($sink, 's1', 'd' . $n);
        }
        $this->deliverLive($sink, 's1', 'e1');
        $this->deliverLive($sink, 's1', 'e2');
        $sink->flush();

        $notices = \array_values(\array_filter($this->heard, static fn (array $e): bool => ($e['type'] ?? null) === 'server.overflow'));
        self::assertCount(1, $notices, 'one notice per session, however many live events were shed');
        self::assertSame(['sessionId' => 's1', 'dropped' => 2, 'action' => 'resubscribe'], $notices[0]['data'], 'the first shed raised the notice and the second only counted');
        self::assertSame($notices[0], $this->heard[0], 'the notice sits at the first shed position');

        $expected = [];
        for ($n = 3; $n <= InProcessEventSink::QUEUE_BOUND; $n++) {
            $expected[] = 'd' . $n;
        }
        $expected[] = 'e1';
        $expected[] = 'e2';
        $texts = \array_map(
            static fn (array $e): string => (string) $e['data']['text'],
            \array_values(\array_filter($this->heard, static fn (array $e): bool => ($e['type'] ?? null) === 'assistant.delta')),
        );
        self::assertSame($expected, $texts, 'every surviving delta arrives, in order, with the two oldest shed');
    }

    public function testUnfollowDropsTheOwedEventsAsTheDocumentedRoutedDivergence(): void
    {
        // Pins the divergence documented on InProcessEventSink::unfollow(): the routed purge drops
        // owed events with no notice, where the primary feed keeps envelopes it already queued.
        $sink = $this->sink();
        $sink->follow('s1', 0);
        $this->deliverDurable($sink, 's1', 1);
        $this->deliverDurable($sink, 's1', 2);
        $sink->unfollow('s1');
        $sink->flush();

        self::assertSame([], $this->heard, 'the owed events are purged and no notice is sent');
        self::assertSame([], $sink->followedSessions());
        self::assertTrue($sink->accepts('s1'), 'an unfollow is not a stop: a later follow is accepted');
    }

    private function sink(): InProcessEventSink
    {
        static $n = 0;

        return $this->last = new InProcessEventSink(
            'inproc:test-' . ++$n,
            $this->gateway,
            Loop::get(),
            function (array $envelope): void {
                $this->heard[] = $envelope;
            },
        );
    }

    private function deliverDurable(InProcessEventSink $sink, string $sessionId, int $seq): void
    {
        $params = ['sessionId' => $sessionId, 'seq' => $seq, 'type' => 'turn.started', 'durable' => true, 'data' => [], 'root' => '/r'];
        $message = ['jsonrpc' => '2.0', 'method' => 'event', 'params' => $params];
        $sink->deliver($message, (string) \json_encode($message), null, false, $seq, $sessionId);
    }

    private function deliverLive(InProcessEventSink $sink, string $sessionId, string $text): void
    {
        $params = ['sessionId' => $sessionId, 'type' => 'assistant.delta', 'durable' => false, 'turnId' => 't1', 'data' => ['text' => $text], 'root' => '/r'];
        $message = ['jsonrpc' => '2.0', 'method' => 'event', 'params' => $params];
        $sink->deliver($message, (string) \json_encode($message), $sessionId . '|assistant.delta|t1', true, null, $sessionId);
    }

    /** @return list<int> */
    private function seqsOf(string $sessionId): array
    {
        $seqs = [];
        foreach ($this->heard as $envelope) {
            if (($envelope['sessionId'] ?? null) === $sessionId && \is_int($envelope['seq'] ?? null)) {
                $seqs[] = $envelope['seq'];
            }
        }

        return $seqs;
    }

    /** @return list<string> one entry per envelope: `s1:3` for a durable seq, `overflow:s1:n` for a notice */
    private function trace(): array
    {
        return \array_map(static function (array $e): string {
            if (($e['type'] ?? null) === 'server.overflow') {
                return 'overflow:' . $e['data']['sessionId'] . ':' . $e['data']['dropped'];
            }

            return $e['sessionId'] . ':' . $e['seq'];
        }, $this->heard);
    }

    /** The gateway's durable cursor of the sink under test for $sessionId (-1 when none is set). */
    private function cursorOf(string $sessionId): int
    {
        $cursors = (new \ReflectionProperty(Gateway::class, 'cursors'))->getValue($this->gateway);

        return (int) ($cursors[$this->last?->id() ?? ''][$sessionId] ?? -1);
    }
}
