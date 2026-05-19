<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\AddComponentMsg;
use SugarCraft\Core\Component;
use SugarCraft\Core\Composite;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\RemoveComponentMsg;
use SugarCraft\Core\SubscriptionCapable;
use React\EventLoop\StreamSelectLoop;

/**
 * Tests for Component lifecycle within a Composite model.
 *
 * Verifies that:
 * - onMount() is called when a Component is first added
 * - onUnmount() is called exactly once when a Component is removed
 * - Lifecycle is accurate across multiple add/remove cycles
 */
final class ComponentLifecycleTest extends TestCase
{
    public function testCompositeHoldsThreeComponents(): void
    {
        $composite = new Composite();

        $this->assertEmpty($composite->children());

        $c1 = new class implements Component {
            use SubscriptionCapable;
            public string $id = 'c1';
            public function init(): ?\Closure { return null; }
            public function update(Msg $msg): array { return [$this, null]; }
            public function view(): string { return ''; }
            public function onMount(): ?\Closure { return null; }
            public function onUnmount(): ?\Closure { return null; }
        };
        $c2 = new class implements Component {
            use SubscriptionCapable;
            public string $id = 'c2';
            public function init(): ?\Closure { return null; }
            public function update(Msg $msg): array { return [$this, null]; }
            public function view(): string { return ''; }
            public function onMount(): ?\Closure { return null; }
            public function onUnmount(): ?\Closure { return null; }
        };
        $c3 = new class implements Component {
            use SubscriptionCapable;
            public string $id = 'c3';
            public function init(): ?\Closure { return null; }
            public function update(Msg $msg): array { return [$this, null]; }
            public function view(): string { return ''; }
            public function onMount(): ?\Closure { return null; }
            public function onUnmount(): ?\Closure { return null; }
        };

        $composite = $composite->withChildren(['c1' => $c1, 'c2' => $c2, 'c3' => $c3]);
        $this->assertCount(3, $composite->children());
    }

    public function testAddComponentMsgCallsOnMountOnce(): void
    {
        $counter = new class {
            public int $mountCount = 0;
            public int $unmountCount = 0;
        };

        $component = new class($counter) implements Component {
            use SubscriptionCapable;
            private object $counter;

            public function __construct(object $counter)
            {
                $this->counter = $counter;
            }

            public function init(): ?\Closure { return null; }
            public function update(Msg $msg): array { return [$this, null]; }
            public function view(): string { return ''; }

            public function onMount(): ?\Closure
            {
                $this->counter->mountCount++;
                return null;
            }

            public function onUnmount(): ?\Closure
            {
                $this->counter->unmountCount++;
                return null;
            }
        };

        $composite = new Composite();

        // First add — should trigger onMount
        [$next, ] = $composite->update(new AddComponentMsg('a', $component));
        $this->assertSame(1, $counter->mountCount, 'onMount should be called on first add');
        $this->assertSame(0, $counter->unmountCount, 'onUnmount should not be called on first add');

        // Second add with same id — onMount should NOT fire again
        [$next2, ] = $next->update(new AddComponentMsg('a', $component));
        $this->assertSame(1, $counter->mountCount, 'onMount should not be called on replace');
    }

    public function testRemoveComponentMsgCallsOnUnmountOnce(): void
    {
        $counter = new class {
            public int $mountCount = 0;
            public int $unmountCount = 0;
        };

        $component = new class($counter) implements Component {
            use SubscriptionCapable;
            private object $counter;

            public function __construct(object $counter)
            {
                $this->counter = $counter;
            }

            public function init(): ?\Closure { return null; }
            public function update(Msg $msg): array { return [$this, null]; }
            public function view(): string { return ''; }

            public function onMount(): ?\Closure
            {
                $this->counter->mountCount++;
                return null;
            }

            public function onUnmount(): ?\Closure
            {
                $this->counter->unmountCount++;
                return null;
            }
        };

        $composite = new Composite();

        // Add the component first
        [$withChild, ] = $composite->update(new AddComponentMsg('x', $component));
        $this->assertSame(1, $counter->mountCount);
        $this->assertSame(0, $counter->unmountCount);

        // Remove the component — onUnmount should fire exactly once
        [$withoutChild, ] = $withChild->update(new RemoveComponentMsg('x'));
        $this->assertSame(1, $counter->mountCount, 'mountCount should stay at 1');
        $this->assertSame(1, $counter->unmountCount, 'onUnmount should be called exactly once');

        // Remove again — should not call onUnmount again (component already gone)
        [$stillWithout, ] = $withoutChild->update(new RemoveComponentMsg('x'));
        $this->assertSame(1, $counter->unmountCount, 'onUnmount should not fire for already-gone component');
    }

    public function testThreeComponentsAddedOneRemovedMidSession(): void
    {
        $counter = new class {
            public array $mountCounts = ['a' => 0, 'b' => 0, 'c' => 0];
            public array $unmountCounts = ['a' => 0, 'b' => 0, 'c' => 0];
        };

        $components = [];
        foreach (['a', 'b', 'c'] as $id) {
            $components[$id] = new class($id, $counter) implements Component {
                use SubscriptionCapable;
                public string $id;
                private object $counter;

                public function __construct(string $id, object $counter)
                {
                    $this->id = $id;
                    $this->counter = $counter;
                }

                public function init(): ?\Closure { return null; }
                public function update(Msg $msg): array { return [$this, null]; }
                public function view(): string { return ''; }
                public function onMount(): ?\Closure {
                    $this->counter->mountCounts[$this->id]++;
                    return null;
                }
                public function onUnmount(): ?\Closure {
                    $this->counter->unmountCounts[$this->id]++;
                    return null;
                }
            };
        }

        $composite = new Composite();

        // Add all three components
        foreach (['a', 'b', 'c'] as $id) {
            [$composite, ] = $composite->update(new AddComponentMsg($id, $components[$id]));
        }

        $this->assertSame(1, $counter->mountCounts['a']);
        $this->assertSame(1, $counter->mountCounts['b']);
        $this->assertSame(1, $counter->mountCounts['c']);
        $this->assertSame(0, $counter->unmountCounts['a']);
        $this->assertSame(0, $counter->unmountCounts['b']);
        $this->assertSame(0, $counter->unmountCounts['c']);

        // Remove component 'b' mid-session
        [$composite, ] = $composite->update(new RemoveComponentMsg('b'));

        $this->assertSame(1, $counter->mountCounts['a']);
        $this->assertSame(1, $counter->mountCounts['b']);
        $this->assertSame(1, $counter->mountCounts['c']);
        $this->assertSame(0, $counter->unmountCounts['a']);
        $this->assertSame(1, $counter->unmountCounts['b'], 'onUnmount should fire exactly once for b');
        $this->assertSame(0, $counter->unmountCounts['c']);

        // Components 'a' and 'c' should still be there
        $this->assertCount(2, $composite->children());
        $this->assertArrayHasKey('a', $composite->children());
        $this->assertArrayHasKey('c', $composite->children());
        $this->assertArrayNotHasKey('b', $composite->children());
    }
}
