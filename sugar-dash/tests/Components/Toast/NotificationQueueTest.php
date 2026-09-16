<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Toast;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Toast\Level;
use SugarCraft\Dash\Components\Toast\Notification;
use SugarCraft\Dash\Components\Toast\NotificationQueue;

final class NotificationQueueTest extends TestCase
{
    public function testPushAndCurrent(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('first'))
            ->push(Notification::warning('second'));

        $this->assertSame('first', $q->current()?->message);
        $this->assertSame(Level::Info, $q->current()?->level);
    }

    public function testPush25ItemsCapsItemsAt20AndHistoryAt50(): void
    {
        $q = NotificationQueue::new();

        for ($i = 1; $i <= 25; $i++) {
            $q = $q->push(Notification::info("msg $i"));
        }

        $this->assertSame(20, $q->count());
        $this->assertSame(5, $q->historyCount());
    }

    public function testDismissAdvancesCurrent(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('first'))
            ->push(Notification::warning('second'))
            ->push(Notification::error('third'));

        $this->assertSame('first', $q->current()?->message);

        $q = $q->dismiss();
        $this->assertSame('second', $q->current()?->message);

        $q = $q->dismiss();
        $this->assertSame('third', $q->current()?->message);

        $q = $q->dismiss();
        $this->assertNull($q->current());
    }

    public function testDismissedItemsGoToHistory(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('first'))
            ->push(Notification::warning('second'));

        $this->assertSame(0, $q->historyCount());

        $q = $q->dismiss();

        $this->assertSame(1, $q->historyCount());
        $this->assertSame('first', $q->history()[0]?->message);
    }

    public function testRecentReturnsLastNNewestFirst(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('msg1'))
            ->push(Notification::warning('msg2'))
            ->push(Notification::error('msg3'))
            ->push(Notification::success('msg4'))
            ->push(Notification::info('msg5'));

        $q = $q->dismiss()->dismiss()->dismiss();

        $recent = $q->recent(3);

        $this->assertCount(3, $recent);
        $this->assertSame('msg3', $recent[0]->message);
        $this->assertSame('msg2', $recent[1]->message);
        $this->assertSame('msg1', $recent[2]->message);
    }

    public function testRecentWithNLessThanOneReturnsEmpty(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('only'));

        $this->assertSame([], $q->recent(0));
        $this->assertSame([], $q->recent(-1));
    }

    public function testRecentWithEmptyHistoryReturnsEmpty(): void
    {
        $q = NotificationQueue::new();
        $this->assertSame([], $q->recent(5));
    }

    public function testRecentExcludesCurrentItemsRing(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('active'))
            ->push(Notification::warning('active2'));

        $this->assertSame([], $q->recent(5));
    }

    public function testDismissToEmptyThenRecent(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('first'));

        $q = $q->dismiss();
        $this->assertNull($q->current());
        $recent = $q->recent(5);
        $this->assertCount(1, $recent);
        $this->assertSame('first', $recent[0]->message);
    }

    public function testWithersReturnNewInstance(): void
    {
        $q = NotificationQueue::new();

        $q2 = $q->withMaxItems(10);
        $q3 = $q->withMaxHistory(100);

        $this->assertNotSame($q, $q2);
        $this->assertNotSame($q, $q3);
        $this->assertNotSame($q2, $q3);
    }

    public function testImmutabilityPushedInstanceUnchanged(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('first'));

        $q2 = $q->push(Notification::warning('second'));

        $this->assertSame(1, $q->count());
        $this->assertSame(2, $q2->count());
        $this->assertSame(0, $q2->historyCount());
    }

    public function testImmutabilityDismissedInstanceUnchanged(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('first'))
            ->push(Notification::warning('second'));

        $q2 = $q->dismiss();

        $this->assertSame(2, $q->count());
        $this->assertSame(1, $q2->count());
        $this->assertSame(0, $q->historyCount());
        $this->assertSame(1, $q2->historyCount());
    }

    /**
     * Saturates BOTH small caps through push-driven eviction, pinning
     * exact ring contents (the pre-fix sibling of this test asserted
     * `<= 3` on a queue that never reached the cap — vacuous).
     */
    public function testPushOverflowSaturatesSmallCaps(): void
    {
        $q = NotificationQueue::new()->withMaxItems(2)->withMaxHistory(3);

        for ($i = 1; $i <= 7; $i++) {
            $q = $q->push(Notification::info("msg$i"));
        }

        $this->assertSame(2, $q->count());
        $this->assertSame('msg6', $q->current()?->message);
        $this->assertSame(3, $q->historyCount());
        $this->assertSame(
            ['msg3', 'msg4', 'msg5'],
            array_map(static fn (Notification $n): string => $n->message, $q->history()),
        );

        $recent = $q->recent(5);
        $this->assertCount(3, $recent);
        $this->assertSame('msg5', $recent[0]->message);
        $this->assertSame('msg4', $recent[1]->message);
        $this->assertSame('msg3', $recent[2]->message);
    }

    /**
     * The step-09 headline figure: history really caps at 50, not just
     * "stays under some bound".
     */
    public function testHistorySaturatesExactlyAtDefaultCap(): void
    {
        $q = NotificationQueue::new();

        for ($i = 1; $i <= 75; $i++) {
            $q = $q->push(Notification::info("n$i"));
        }

        $this->assertSame(20, $q->count());
        $this->assertSame(50, $q->historyCount());

        $q = $q->push(Notification::info('n76'));

        $this->assertSame(20, $q->count());
        $this->assertSame(50, $q->historyCount());
        $this->assertSame('n57', $q->current()?->message);

        $recent = $q->recent(5);
        $this->assertSame(
            ['n56', 'n55', 'n54', 'n53', 'n52'],
            array_map(static fn (Notification $n): string => $n->message, $recent),
        );
    }

    public function testWithMaxItemsPreservesRings(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('a'))
            ->push(Notification::info('b'))
            ->push(Notification::info('c'))
            ->dismiss();

        $shrunk = $q->withMaxItems(2);

        $this->assertNotSame($q, $shrunk);
        $this->assertSame(2, $shrunk->count());
        $this->assertSame('b', $shrunk->current()?->message);
        $this->assertSame(1, $shrunk->historyCount());
        $this->assertSame('a', $shrunk->history()[0]?->message);
    }

    public function testWithMaxItemsShrinkEvictsOldestIntoHistory(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('a'))
            ->push(Notification::info('b'))
            ->push(Notification::info('c'))
            ->withMaxItems(2);

        $this->assertSame(2, $q->count());
        $this->assertSame('b', $q->current()?->message);
        $this->assertSame(1, $q->historyCount());
        $this->assertSame('a', $q->history()[0]?->message);
    }

    public function testWithMaxHistoryPreservesRings(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('a'))
            ->push(Notification::info('b'))
            ->push(Notification::info('c'))
            ->dismiss();

        $grown = $q->withMaxHistory(10);

        $this->assertNotSame($q, $grown);
        $this->assertSame(2, $grown->count());
        $this->assertSame('b', $grown->current()?->message);
        $this->assertSame(1, $grown->historyCount());
        $this->assertSame('a', $grown->history()[0]?->message);
    }

    public function testWithMaxHistoryShrinkDropsOldest(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('a'))
            ->push(Notification::info('b'))
            ->push(Notification::info('c'))
            ->push(Notification::info('d'))
            ->dismiss()
            ->dismiss()
            ->dismiss()
            ->withMaxHistory(2);

        $this->assertSame(1, $q->count());
        $this->assertSame('d', $q->current()?->message);
        $this->assertSame(2, $q->historyCount());
        $this->assertSame(
            ['b', 'c'],
            array_map(static fn (Notification $n): string => $n->message, $q->history()),
        );
    }

    public function testSubUnitCapsClampToOneThroughConstructor(): void
    {
        $q = new NotificationQueue(maxItems: -3, maxHistory: 0);

        $q = $q
            ->push(Notification::info('a'))
            ->push(Notification::info('b'))
            ->push(Notification::info('c'));

        $this->assertSame(1, $q->count());
        $this->assertSame('c', $q->current()?->message);
        $this->assertSame(1, $q->historyCount());
        $this->assertSame('b', $q->history()[0]?->message);
    }

    public function testWithMaxItemsNegativeClampsToOne(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('a'))
            ->push(Notification::info('b'))
            ->push(Notification::info('c'))
            ->withMaxItems(-5);

        $this->assertSame(1, $q->count());
        $this->assertSame('c', $q->current()?->message);
        $this->assertSame(2, $q->historyCount());
    }

    public function testRecentClampsWhenNExceedsHistory(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('a'))
            ->push(Notification::info('b'))
            ->dismiss()
            ->dismiss();

        $recent = $q->recent(9);

        $this->assertCount(2, $recent);
        $this->assertSame('b', $recent[0]->message);
        $this->assertSame('a', $recent[1]->message);
    }

    public function testHasHistory(): void
    {
        $empty = NotificationQueue::new()->push(Notification::info('a'));
        $this->assertFalse($empty->hasHistory());

        $dismissed = $empty->dismiss();
        $this->assertTrue($dismissed->hasHistory());
    }

    public function testAllReturnsAllItemsInOrder(): void
    {
        $q = NotificationQueue::new()
            ->push(Notification::info('first'))
            ->push(Notification::warning('second'))
            ->push(Notification::error('third'));

        $all = $q->all();

        $this->assertCount(3, $all);
        $this->assertSame('first', $all[0]->message);
        $this->assertSame('second', $all[1]->message);
        $this->assertSame('third', $all[2]->message);
    }

    public function testIsEmpty(): void
    {
        $empty = NotificationQueue::new();
        $withItem = $empty->push(Notification::info('msg'));

        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($withItem->isEmpty());
    }
}
