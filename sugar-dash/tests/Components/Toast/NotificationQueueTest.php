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

    public function testHistoryMaxCapsAtLimit(): void
    {
        $q = NotificationQueue::new()->withMaxHistory(3);

        for ($i = 1; $i <= 10; $i++) {
            $q = $q->push(Notification::info("msg$i"));
        }

        $q = $q->dismiss();

        $this->assertLessThanOrEqual(3, $q->historyCount());
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
