<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\AddComponentMsg;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Component;
use SugarCraft\Core\ComponentAddressedMsg;
use SugarCraft\Core\Composite;
use SugarCraft\Core\Model;
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

        $c1 = new class () implements Component {
            use SubscriptionCapable;
            public string $id = 'c1';
            public function init(): ?\Closure
            {
                return null;
            }
            public function update(Msg $msg): array
            {
                return [$this, null];
            }
            public function view(): string
            {
                return '';
            }
            public function onMount(): ?\Closure
            {
                return null;
            }
            public function onUnmount(): ?\Closure
            {
                return null;
            }
        };
        $c2 = new class () implements Component {
            use SubscriptionCapable;
            public string $id = 'c2';
            public function init(): ?\Closure
            {
                return null;
            }
            public function update(Msg $msg): array
            {
                return [$this, null];
            }
            public function view(): string
            {
                return '';
            }
            public function onMount(): ?\Closure
            {
                return null;
            }
            public function onUnmount(): ?\Closure
            {
                return null;
            }
        };
        $c3 = new class () implements Component {
            use SubscriptionCapable;
            public string $id = 'c3';
            public function init(): ?\Closure
            {
                return null;
            }
            public function update(Msg $msg): array
            {
                return [$this, null];
            }
            public function view(): string
            {
                return '';
            }
            public function onMount(): ?\Closure
            {
                return null;
            }
            public function onUnmount(): ?\Closure
            {
                return null;
            }
        };

        $composite = $composite->withChildren(['c1' => $c1, 'c2' => $c2, 'c3' => $c3]);
        $this->assertCount(3, $composite->children());
    }

    public function testAddComponentMsgCallsOnMountOnce(): void
    {
        $counter = new class () {
            public int $mountCount = 0;
            public int $unmountCount = 0;
        };

        $component = new class ($counter) implements Component {
            use SubscriptionCapable;
            private object $counter;

            public function __construct(object $counter)
            {
                $this->counter = $counter;
            }

            public function init(): ?\Closure
            {
                return null;
            }
            public function update(Msg $msg): array
            {
                return [$this, null];
            }
            public function view(): string
            {
                return '';
            }

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
        $counter = new class () {
            public int $mountCount = 0;
            public int $unmountCount = 0;
        };

        $component = new class ($counter) implements Component {
            use SubscriptionCapable;
            private object $counter;

            public function __construct(object $counter)
            {
                $this->counter = $counter;
            }

            public function init(): ?\Closure
            {
                return null;
            }
            public function update(Msg $msg): array
            {
                return [$this, null];
            }
            public function view(): string
            {
                return '';
            }

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
        $counter = new class () {
            public array $mountCounts = ['a' => 0, 'b' => 0, 'c' => 0];
            public array $unmountCounts = ['a' => 0, 'b' => 0, 'c' => 0];
        };

        $components = [];
        foreach (['a', 'b', 'c'] as $id) {
            $components[$id] = new class ($id, $counter) implements Component {
                use SubscriptionCapable;
                public string $id;
                private object $counter;

                public function __construct(string $id, object $counter)
                {
                    $this->id = $id;
                    $this->counter = $counter;
                }

                public function init(): ?\Closure
                {
                    return null;
                }
                public function update(Msg $msg): array
                {
                    return [$this, null];
                }
                public function view(): string
                {
                    return '';
                }
                public function onMount(): ?\Closure
                {
                    $this->counter->mountCounts[$this->id]++;
                    return null;
                }
                public function onUnmount(): ?\Closure
                {
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

    // ─── sc-12 · sugar-core-010/016: nested pending Cmds and replace parity ───

    public function testReplacingChildUnmountsOutgoingAndMountsIncoming(): void
    {
        $counter = new \ArrayObject();
        $a1 = new Sc12Leaf('v1', $counter);
        $a2 = new Sc12Leaf('v2', $counter);

        [$with1] = (new Composite())->update(new AddComponentMsg('slot', $a1));
        $this->assertSame(1, $counter['mount:v1'] ?? 0);
        $this->assertSame(0, $counter['unmount:v1'] ?? 0);

        [$with2] = $with1->update(new AddComponentMsg('slot', $a2));
        $this->assertSame(1, $counter['unmount:v1'] ?? 0, 'sugar-core-016: replacement unmounts the outgoing child');
        $this->assertSame(1, $counter['mount:v2'] ?? 0, 'sugar-core-016: replacement mounts the incoming child');
        $this->assertSame($a2, $with2->children()['slot'], 'the incoming child takes the slot');
        // Identical re-adds stay no-ops — pinned by
        // testAddComponentMsgCallsOnMountOnce above.
    }

    public function testReplacementQueuesUnmountThenMountCmds(): void
    {
        $counter = new \ArrayObject();
        $out = static fn(): ?Msg => null;
        $in = static fn(): ?Msg => null;
        $a1 = new Sc12Leaf('v1', $counter, null, $out);
        $a2 = new Sc12Leaf('v2', $counter, $in);

        [$with1] = (new Composite())->update(new AddComponentMsg('slot', $a1));
        $this->assertSame([], $with1->pendingCmds());

        [$with2] = $with1->update(new AddComponentMsg('slot', $a2));
        $this->assertSame([$out, $in], $with2->pendingCmds(), 'outgoing unmount Cmd first, incoming mount Cmd second');
    }

    public function testRoutedChildUpdateKeepsQueuedLifecycleCmds(): void
    {
        $counter = new \ArrayObject();
        $q = static fn(): ?Msg => null;
        $leaf = new Sc12Leaf('x', $counter, $q);

        [$withQueue] = (new Composite())->update(new AddComponentMsg('x', $leaf));
        $this->assertSame([$q], $withQueue->pendingCmds(), 'mount Cmd is queued');

        // Route a msg that CHANGES the child: the parent rebuilds its
        // children map here. sugar-core-010: this rebuild used to
        // construct `new self($children)` and drop the pending queue,
        // so a Cmd queued earlier in the cycle evaporated before the
        // end-of-cycle drain could run it.
        [$after] = $withQueue->update(new Sc12RoutedMsg('x'));
        $this->assertSame([$q], $after->pendingCmds(), 'sugar-core-010: forwarded update must carry the queue');
        $this->assertSame(1, $after->children()['x']->ticks, 'the routed child really was replaced');
    }

    public function testDrainWalksNestedComposites(): void
    {
        $counter = new \ArrayObject();
        $leaf = new Sc12Leaf('x', $counter);
        $rootCmd = static fn(): ?Msg => null;
        $nestedCmd = static fn(): ?Msg => null;

        $inner = new Composite(['x' => $leaf], [$nestedCmd]);
        $outer = new Composite(['n' => $inner], [$rootCmd]);

        [$cleared, $cmds] = $outer->drainPendingCmds();
        $this->assertSame([$rootCmd, $nestedCmd], $cmds, 'tree order: own queue first, then each child subtree');
        $this->assertSame([], $cleared->pendingCmds(), 'root queue cleared');
        $this->assertSame([], $cleared->children()['n']->pendingCmds(), 'nested queue cleared too');
        $this->assertSame(
            $leaf,
            $cleared->children()['n']->children()['x'],
            'untouched leaves keep object identity across the walk'
        );

        [$again, $more] = $cleared->drainPendingCmds();
        $this->assertSame($cleared, $again, 'a settled tree drains through the same instance');
        $this->assertSame([], $more);
    }

    public function testDrainOfSettledTreeAllocatesNothing(): void
    {
        $counter = new \ArrayObject();
        $settled = new Composite(['n' => new Composite(['x' => new Sc12Leaf('x', $counter)])]);

        [$same, $cmds] = $settled->drainPendingCmds();
        $this->assertSame($settled, $same, 'the every-tick path through a settled tree must not rebuild');
        $this->assertSame([], $cmds);
    }

    public function testWithChildrenMergesByIdAndKeepsQueue(): void
    {
        $counter = new \ArrayObject();
        $q = static fn(): ?Msg => null;
        $c1 = new Sc12Leaf('c1', $counter);

        [$base] = (new Composite())->update(new AddComponentMsg('c1', new Sc12Leaf('c1', $counter, $q)));
        $this->assertSame([$q], $base->pendingCmds());

        $merged = $base->withChildren(['c2' => $c1]);
        $this->assertArrayHasKey('c1', $merged->children(), 'sugar-core-010: existing children survive the merge');
        $this->assertArrayHasKey('c2', $merged->children());
        $this->assertSame([$q], $merged->pendingCmds(), 'queued lifecycle Cmds carry across withChildren()');

        $c1b = new Sc12Leaf('c1', $counter);
        $replaced = $merged->withChildren(['c1' => $c1b]);
        $this->assertSame($c1b, $replaced->children()['c1'], 'incoming wins on id collisions');
        $this->assertArrayHasKey('c2', $replaced->children());
    }

    public function testNestedQuitCmdActuallyQuitsTheProgram(): void
    {
        // End-to-end sugar-core-010: the quit Cmd is queued inside a
        // NESTED composite (one level below the root model). The old
        // root-only drain never collected it, so the program kept
        // running until the watchdog killed it. The input writer stays
        // open; this quit can only have come from the drain walk.
        $model = new Composite(['n' => new Composite([], [Cmd::quit()])]);

        [$in, $out, $writer] = $this->pipes();
        $loop = new StreamSelectLoop();
        $options = new ProgramOptions(
            useAltScreen: false,
            catchInterrupts: false,
            hideCursor: false,
            input: $in,
            output: $out,
            loop: $loop,
        );

        $program = new Program($model, $options);
        $watchdogFired = false;
        $loop->addTimer(0.01, static function () use ($writer): void {
            \fwrite($writer, 'z');
        });
        $loop->addTimer(3.0, static function () use (&$watchdogFired, $program): void {
            $watchdogFired = true;
            $program->quit();
        });

        try {
            $program->run();
        } finally {
            $this->keepAlive([$in, $out, $writer]);
        }

        $this->assertFalse($watchdogFired, 'nested Cmd::quit() must terminate the run, not the watchdog');
        $final = $program->model();
        $this->assertInstanceOf(Composite::class, $final, 'the end-of-cycle drain swaps the cleared tree into the program');
        $this->assertNotSame($model, $final);
        $this->assertSame([], $final->children()['n']->pendingCmds(), 'the drain clears the nested queue it collected');
        $this->assertCount(1, $model->children()['n']->pendingCmds(), 'immutability: the seeded instance is never mutated by the walk');
    }

    /** @return array{0:resource, 1:resource, 2:resource} input, output, inputWriter */
    private function pipes(): array
    {
        $sockets = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertNotFalse($sockets);
        [$reader, $writer] = $sockets;
        $output = \fopen('php://memory', 'w+');
        $this->assertNotFalse($output);
        return [$reader, $output, $writer];
    }

    /** Release the held streams only after the run has ended. */
    private function keepAlive(array $streams): void
    {
        foreach ($streams as $stream) {
            if (\is_resource($stream)) {
                @\fclose($stream);
            }
        }
    }
}

/**
 * A leaf Component for sc-12 pins: counts mount/unmount hooks on a
 * shared {@see \ArrayObject}, replaces itself when a routed msg names
 * its slot, and can hand back configured lifecycle Cmds.
 */
final class Sc12Leaf implements Component
{
    use SubscriptionCapable;

    public function __construct(
        public readonly string $slot,
        private readonly \ArrayObject $counter,
        private readonly ?\Closure $mountCmd = null,
        private readonly ?\Closure $unmountCmd = null,
        public readonly int $ticks = 0,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /** @return array{0: Model, 1: ?\Closure} */
    public function update(Msg $msg): array
    {
        if ($msg instanceof ComponentAddressedMsg && $msg->componentId() === $this->slot) {
            return [new self($this->slot, $this->counter, $this->mountCmd, $this->unmountCmd, $this->ticks + 1), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return '';
    }

    public function onMount(): ?\Closure
    {
        $key = 'mount:' . $this->slot;
        $this->counter[$key] = (int) ($this->counter[$key] ?? 0) + 1;
        return $this->mountCmd;
    }

    public function onUnmount(): ?\Closure
    {
        $key = 'unmount:' . $this->slot;
        $this->counter[$key] = (int) ($this->counter[$key] ?? 0) + 1;
        return $this->unmountCmd;
    }
}

/**
 * Routes to a child slot without being an add/remove reconcile msg.
 */
final class Sc12RoutedMsg implements ComponentAddressedMsg
{
    public function __construct(private readonly string $id)
    {
    }

    public function componentId(): ?string
    {
        return $this->id;
    }
}
