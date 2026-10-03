<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents\Live;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Live\ActivityItem;
use SugarCraft\Crush\Agents\Live\SubAgentActivityBuffer;

/**
 * The child-side coalescer's flush rules and caps (Appendix P §4.3), on a
 * pinned clock.
 */
final class SubAgentActivityBufferTest extends TestCase
{
    private float $now = 1000.0;

    public function testNothingIsDueWhileEmptyAndTheFirstItemIsDueAtOnce(): void
    {
        $buffer = $this->buffer();

        $this->assertFalse($buffer->due());
        $buffer->add(ActivityItem::thinking());
        $this->assertTrue($buffer->due(), 'nothing has been flushed yet, so the first item goes out');
    }

    public function testAfterAFlushItemsWaitOutTheInterval(): void
    {
        $buffer = $this->buffer();
        $buffer->flush();

        $buffer->add(ActivityItem::toolStarted('c1', 'Read', 'a.php'));
        $this->now += 0.1;
        $this->assertFalse($buffer->due(), 'within 250 ms of the last flush even a tool start waits (the 4 Hz ceiling)');

        $this->now += 0.15;
        $this->assertTrue($buffer->due());
        $this->assertCount(1, $buffer->flush());
        $this->assertTrue($buffer->isEmpty());
        $this->assertFalse($buffer->due());
    }

    public function testAnyTwoDueFlushesAreAtLeastAQuarterSecondApart(): void
    {
        $buffer = $this->buffer();
        $flushes = [];
        for ($tick = 0; $tick < 100; $tick++) {
            $buffer->add(ActivityItem::toolStarted('c' . $tick, 'Grep', ''));
            if ($buffer->due()) {
                $flushes[] = $this->now;
                $buffer->flush();
            }
            $this->now += 0.02;
        }

        $this->assertLessThanOrEqual(SubAgentActivityBuffer::FRAMES_PER_SECOND * 2 + 1, count($flushes), '2 s of activity, at most 4 frames a second');
        for ($i = 1; $i < count($flushes); $i++) {
            $this->assertGreaterThanOrEqual(SubAgentActivityBuffer::FLUSH_INTERVAL_SECONDS - 1e-9, $flushes[$i] - $flushes[$i - 1]);
        }
    }

    public function testTextDeltasMergeAndRepeatedThinkingCollapses(): void
    {
        $buffer = $this->buffer();
        $buffer->add(ActivityItem::thinking());
        $buffer->add(ActivityItem::thinking());
        $buffer->add(ActivityItem::text('The guard '));
        $buffer->add(ActivityItem::text('is attached'));

        $items = $buffer->flush();

        $this->assertSame([ActivityItem::THINKING, ActivityItem::TEXT], array_map(static fn (ActivityItem $i): string => $i->type, $items));
        $this->assertSame('The guard is attached', $items[1]->delta);
    }

    public function testAFlushCarriesAtMostThirtyTwoItemsAndKeepsTheNewest(): void
    {
        $buffer = $this->buffer();
        for ($i = 0; $i < 50; $i++) {
            $buffer->add(ActivityItem::toolStarted('c' . $i, 'Read', ''));
        }

        $items = $buffer->flush();

        $this->assertCount(SubAgentActivityBuffer::MAX_ITEMS, $items);
        $this->assertSame('c49', $items[31]->callId);
    }

    public function testOverTheByteBudgetOldTextGoesBeforeToolEvents(): void
    {
        $buffer = $this->buffer();
        $buffer->add(ActivityItem::text(str_repeat('x', 500)));
        $buffer->add(ActivityItem::toolStarted('c1', 'Read', 'a.php'));
        $buffer->add(ActivityItem::toolFinished('c1', 'Read', true, 3));
        $buffer->add(ActivityItem::text(str_repeat('y', 500)));

        $items = $buffer->flush(850);

        $this->assertSame(
            [ActivityItem::TOOL_STARTED, ActivityItem::TOOL_FINISHED, ActivityItem::TEXT],
            array_map(static fn (ActivityItem $i): string => $i->type, $items),
            'the older text went first; the newest item survives',
        );
        $this->assertLessThanOrEqual(850, strlen(serialize(array_map(static fn (ActivityItem $i): array => $i->toArray(), $items))));

        $buffer->add(ActivityItem::toolStarted('c1', 'Read', ''));
        $buffer->add(ActivityItem::toolStarted('c2', 'Read', ''));
        $kept = $buffer->flush(150);
        $this->assertSame(['c2'], array_map(static fn (ActivityItem $i): string => $i->callId, $kept), 'then the oldest tool events');

        $buffer->add(ActivityItem::text(str_repeat('z', 500)));
        $this->assertSame([], $buffer->flush(10), 'an item that cannot fit the budget at all is not sent');
    }

    private function buffer(): SubAgentActivityBuffer
    {
        return SubAgentActivityBuffer::new(fn (): float => $this->now);
    }
}
