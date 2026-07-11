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

    public function testDisposeAllDisposesEveryOneEvenWhenFirstThrows(): void
    {
        // The FIRST subscription throws on unsubscribe. Every subscription must
        // still be disposed, and the FIRST caught exception must be rethrown
        // (not the later one, and not swallowed).
        $first = new ThrowingSubscription('first fail');
        $second = new ThrowingSubscription(null);
        $third = new ThrowingSubscription('third fail');

        $composite = Subscriptions::compose($first, $second, $third);

        $caught = null;
        try {
            $composite->unsubscribe();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        // No subscription leaked despite the first one throwing.
        $this->assertTrue($first->wasUnsubscribed);
        $this->assertTrue($second->wasUnsubscribed);
        $this->assertTrue($third->wasUnsubscribed);

        // The FIRST failure surfaced (later throwers do not mask it).
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame('first fail', $caught->getMessage());

        // Composite is still marked disposed / emptied.
        $this->assertFalse($composite->isActive());
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

/**
 * @internal Test helper whose unsubscribe() optionally throws, while still
 * recording that it was invoked. Used to prove disposeAll() disposes every
 * subscription even when one throws.
 */
final class ThrowingSubscription implements Subscription
{
    public bool $wasUnsubscribed = false;

    public function __construct(private ?string $throwMessage = null)
    {
    }

    public function unsubscribe(): void
    {
        $this->wasUnsubscribed = true;
        if ($this->throwMessage !== null) {
            throw new \RuntimeException($this->throwMessage);
        }
    }

    public function isActive(): bool
    {
        return !$this->wasUnsubscribed;
    }
}
