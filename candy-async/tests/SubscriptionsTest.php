<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Subscription;
use SugarCraft\Async\Subscriptions;

/**
 * @covers \SugarCraft\Async\Subscriptions
 */
final class SubscriptionsTest extends TestCase
{
    public function testComposeCreatesSingleSubscription(): void
    {
        $inner1 = new TestSubscription();
        $inner2 = new TestSubscription();

        $composite = Subscriptions::compose($inner1, $inner2);

        $this->assertInstanceOf(Subscription::class, $composite);
        $this->assertTrue($composite->isActive());
    }

    public function testUnsubscribeDisposesAllUnderlying(): void
    {
        $inner1 = new TestSubscription();
        $inner2 = new TestSubscription();
        $inner3 = new TestSubscription();

        $composite = Subscriptions::compose($inner1, $inner2, $inner3);
        $composite->unsubscribe();

        $this->assertFalse($inner1->isActive());
        $this->assertFalse($inner2->isActive());
        $this->assertFalse($inner3->isActive());
    }

    public function testUnsubscribeIsIdempotent(): void
    {
        $inner = new TestSubscription();

        $composite = Subscriptions::compose($inner);
        $composite->unsubscribe();
        $composite->unsubscribe();
        $composite->unsubscribe();

        $this->assertFalse($inner->isActive());
    }

    public function testIsActiveReturnsFalseAfterUnsubscribe(): void
    {
        $inner = new TestSubscription();

        $composite = Subscriptions::compose($inner);
        $this->assertTrue($composite->isActive());

        $composite->unsubscribe();
        $this->assertFalse($composite->isActive());
    }

    public function testAddToDisposedComposerDisposesImmediately(): void
    {
        $inner1 = new TestSubscription();
        $inner2 = new TestSubscription();

        $composite = Subscriptions::compose($inner1);
        $composite->unsubscribe();

        $composite->add($inner2);

        $this->assertFalse($inner2->isActive());
    }

    public function testEmptyComposeIsActive(): void
    {
        $composite = Subscriptions::compose();
        $this->assertTrue($composite->isActive());
    }

    public function testEmptyComposeUnsubscribeIsIdempotent(): void
    {
        $composite = Subscriptions::compose();
        $composite->unsubscribe();
        $composite->unsubscribe();
        $this->assertFalse($composite->isActive());
    }

    public function testLateAddedSubscriptionIsDisposedOnUnsubscribe(): void
    {
        $inner1 = new TestSubscription();
        $inner2 = new TestSubscription();

        $composite = Subscriptions::compose($inner1);
        $this->assertTrue($composite->isActive());

        // Add second subscription while composite is still active
        $composite->add($inner2);

        $composite->unsubscribe();

        // Both should be disposed
        $this->assertFalse($inner1->isActive());
        $this->assertFalse($inner2->isActive());
    }

    public function testCountAndIsEmpty(): void
    {
        $empty = Subscriptions::compose();
        $this->assertSame(0, $empty->count());
        $this->assertTrue($empty->isEmpty());

        $inner1 = new TestSubscription();
        $inner2 = new TestSubscription();
        $composite = Subscriptions::compose($inner1, $inner2);
        $this->assertSame(2, $composite->count());
        $this->assertFalse($composite->isEmpty());
    }

    public function testComposeSelfIsGuarded(): void
    {
        $composite = Subscriptions::compose();
        $this->assertTrue($composite->isEmpty());

        // Adding self should be silently ignored (no infinite recursion)
        $composite->add($composite);
        $this->assertTrue($composite->isEmpty());
        $this->assertSame(0, $composite->count());
    }
}

/**
 * @internal Test helper implementing Subscription
 */
final class TestSubscription implements Subscription
{
    private bool $active = true;

    public function unsubscribe(): void
    {
        $this->active = false;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
