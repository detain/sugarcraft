<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Backend\MultiBackend;
use SugarCraft\Metrics\Backend\MultiBackendException;
use SugarCraft\Metrics\Descriptor;
use PHPUnit\Framework\TestCase;

final class MultiBackendTest extends TestCase
{
    public function testDescribeIsForwardedToAllChildren(): void
    {
        $spyA = new class implements \SugarCraft\Metrics\Backend {
            public array $describeCalls = [];
            public function counter(string $name, float $value, array $tags = []): void {}
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void {}
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(Descriptor $descriptor): void { $this->describeCalls[] = $descriptor; }
            public function flush(): void {}
            public function remove(string $name, array $tags = []): void {}
            public function clear(): void {}
        };

        $spyB = new class implements \SugarCraft\Metrics\Backend {
            public array $describeCalls = [];
            public function counter(string $name, float $value, array $tags = []): void {}
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void {}
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(Descriptor $descriptor): void { $this->describeCalls[] = $descriptor; }
            public function flush(): void {}
            public function remove(string $name, array $tags = []): void {}
            public function clear(): void {}
        };

        $multi = new MultiBackend($spyA, $spyB);
        $descriptor = new Descriptor('my_metric', 'A counter metric', 'counter');
        $multi->describe($descriptor);

        $this->assertCount(1, $spyA->describeCalls);
        $this->assertSame($descriptor, $spyA->describeCalls[0]);
        $this->assertCount(1, $spyB->describeCalls);
        $this->assertSame($descriptor, $spyB->describeCalls[0]);
    }

    public function testFanoutToAllChildren(): void
    {
        $a = new InMemoryBackend();
        $b = new InMemoryBackend();
        $c = new InMemoryBackend();
        $multi = new MultiBackend($a, $b, $c);

        $multi->counter('hits', 1);
        $multi->gauge('q', 4);
        $multi->histogram('lat', 0.1);

        foreach ([$a, $b, $c] as $child) {
            $this->assertSame(1.0,         $child->counterValue('hits'));
            $this->assertSame(4.0,         $child->gaugeValue('q'));
            $this->assertSame([0.1],       $child->histogramValues('lat'));
        }
    }

    public function testZeroChildrenThrow(): void
    {
        // A zero-child fanout would swallow every emit in silence; the
        // wiring mistake must fail loud at construction.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one child backend');
        new MultiBackend();
    }

    public function testRemovePropagatesChildErrorsByDefault(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public bool $removeCalled = false;
            public function counter(string $name, float $value, array $tags = []): void {}
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void {}
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(Descriptor $descriptor): void {}
            public function flush(): void {}
            public function remove(string $name, array $tags = []): void { $this->removeCalled = true; throw new \RuntimeException('remove fails'); }
            public function clear(): void {}
        };

        $inMemory = new InMemoryBackend();
        $multi = new MultiBackend($throwing, $inMemory);
        $multi->counter('hits', 1); // seed the series (counter is a no-op on the thrower)

        // Default (fail-fast) mode: a throwing child propagates — the old
        // swallow-regardless-of-mode behavior is gone.
        try {
            $multi->remove('hits');
            $this->fail('expected the child failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('remove fails', $e->getMessage());
        }
        $this->assertTrue($throwing->removeCalled);
        // sugar-metrics-040: the recorded partial-fanout semantics — fail-fast
        // aborted at the thrower, so the second child's remove was never
        // attempted and the seeded series survives there.
        $this->assertSame(1.0, $inMemory->counterValue('hits'));
    }

    public function testFailFastAbortsTheFanoutAtTheFirstThrower(): void
    {
        // sugar-metrics-040: counter-side leg of the partial-fanout contract
        // mirrored in the class docblock — a throwing first child means the
        // later children never see the emit at all in fail-fast mode.
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public function counter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('first child fails'); }
            public function gauge(string $_name, float $_value, array $_tags = []): void {}
            public function histogram(string $_name, float $_value, array $_tags = []): void {}
            public function upDownCounter(string $_name, float $_amount, array $_tags = []): void {}
            public function asyncCounter(string $_name, float $_value, array $_tags = []): void {}
            public function asyncGauge(string $_name, float $_value, array $_tags = []): void {}
            public function describe(Descriptor $_descriptor): void {}
            public function flush(): void {}
            public function remove(string $_name, array $_tags = []): void {}
            public function clear(): void {}
        };

        $inMemory = new InMemoryBackend();
        $multi = new MultiBackend($throwing, $inMemory);

        try {
            $multi->counter('hits', 42);
            $this->fail('expected the child failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('first child fails', $e->getMessage());
        }
        $this->assertSame(0.0, $inMemory->counterValue('hits'), 'later children must not be attempted in fail-fast mode');
    }

    public function testRemoveWithContinueOnErrorTriesAllChildrenAndAggregates(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public function counter(string $name, float $value, array $tags = []): void {}
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void {}
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(Descriptor $descriptor): void {}
            public function flush(): void {}
            public function remove(string $name, array $tags = []): void { throw new \RuntimeException('remove fails'); }
            public function clear(): void {}
        };
        $witness = new class implements \SugarCraft\Metrics\Backend {
            public bool $removeCalled = false;
            public function counter(string $name, float $value, array $tags = []): void {}
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void {}
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(Descriptor $descriptor): void {}
            public function flush(): void {}
            public function remove(string $name, array $tags = []): void { $this->removeCalled = true; }
            public function clear(): void {}
        };

        $multi = MultiBackend::withContinueOnError($throwing, $witness);
        try {
            $multi->remove('any_metric');
            $this->fail('expected an aggregated failure');
        } catch (MultiBackendException $e) {
            $this->assertStringContainsString('remove fails', $e->getMessage());
        }
        $this->assertTrue($witness->removeCalled, 'every child must be attempted in isolating mode');
    }

    public function testClearPropagatesChildErrorsByDefault(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public bool $clearCalled = false;
            public function counter(string $name, float $value, array $tags = []): void {}
            public function gauge(string $name, float $value, array $tags = []): void {}
            public function histogram(string $name, float $value, array $tags = []): void {}
            public function upDownCounter(string $name, float $amount, array $tags = []): void {}
            public function asyncCounter(string $name, float $value, array $tags = []): void {}
            public function asyncGauge(string $name, float $value, array $tags = []): void {}
            public function describe(Descriptor $descriptor): void {}
            public function flush(): void {}
            public function remove(string $name, array $tags = []): void {}
            public function clear(): void { $this->clearCalled = true; throw new \RuntimeException('clear fails'); }
        };

        $inMemory = new InMemoryBackend();
        $multi = new MultiBackend($throwing, $inMemory);

        // Default (fail-fast) mode: clear() propagates too.
        try {
            $multi->clear();
            $this->fail('expected the child failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('clear fails', $e->getMessage());
        }
        $this->assertTrue($throwing->clearCalled);
    }

    public function testFlushIsForwardedToAllChildren(): void
    {
        $a = new InMemoryBackend();
        $b = new InMemoryBackend();
        $multi = new MultiBackend($a, $b);
        $multi->counter('hits', 1);
        $multi->flush(); // Must not throw.
        $this->assertTrue(true);
    }

    public function testUpDownCounterIsForwardedToAllChildren(): void
    {
        $a = new InMemoryBackend();
        $b = new InMemoryBackend();
        $multi = new MultiBackend($a, $b);
        $multi->upDownCounter('conns', 3.0);

        $this->assertSame(3.0, $a->upDownCounterValue('conns'));
        $this->assertSame(3.0, $b->upDownCounterValue('conns'));
    }

    public function testAsyncCounterIsForwardedToAllChildren(): void
    {
        $a = new InMemoryBackend();
        $b = new InMemoryBackend();
        $multi = new MultiBackend($a, $b);
        $multi->asyncCounter('jvm_gc', 5.0);

        $this->assertSame(5.0, $a->asyncCounterValue('jvm_gc'));
        $this->assertSame(5.0, $b->asyncCounterValue('jvm_gc'));
    }

    public function testAsyncGaugeIsForwardedToAllChildren(): void
    {
        $a = new InMemoryBackend();
        $b = new InMemoryBackend();
        $multi = new MultiBackend($a, $b);
        $multi->asyncGauge('heap', 2.5);

        $this->assertSame(2.5, $a->asyncGaugeValue('heap'));
        $this->assertSame(2.5, $b->asyncGaugeValue('heap'));
    }

    public function testContinueOnErrorReachesAllChildren(): void
    {
        // ThrowingBackend always throws on any emit.
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public function counter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function gauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function histogram(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function upDownCounter(string $_name, float $_amount, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncCounter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncGauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function describe(Descriptor $_descriptor): void { throw new \RuntimeException('always fails'); }
            public function flush(): void { throw new \RuntimeException('always fails'); }
            public function remove(string $_name, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function clear(): void { throw new \RuntimeException('always fails'); }
        };

        $inMemory = new InMemoryBackend();
        $multi = MultiBackend::withContinueOnError($throwing, $inMemory);

        // In continue-on-error mode, every child receives the emit even if others throw.
        // A counter is recorded; the aggregate exception is thrown after the fanout.
        // sugar-metrics-012: restructured off expectException — the old form aborted
        // the method before the child-received assertion below, so the headline
        // "reaches all children" claim ran nowhere (the :248 excuse was fiction).
        try {
            $multi->counter('hits', 42);
            $this->fail('expected the aggregated MultiBackendException');
        } catch (MultiBackendException $e) {
            $this->assertStringContainsString('1 child backend(s) failed', $e->getMessage());
        }

        // inMemory still received the counter despite the throwing sibling.
        $this->assertSame(42.0, $inMemory->counterValue('hits'));
    }

    public function testContinueOnErrorAggregatesAndRethrows(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public function counter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('first'); }
            public function gauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('second'); }
            public function histogram(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('third'); }
            public function upDownCounter(string $_name, float $_amount, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncCounter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncGauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function describe(Descriptor $_descriptor): void { throw new \RuntimeException('always fails'); }
            public function flush(): void { throw new \RuntimeException('always fails'); }
            public function remove(string $_name, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function clear(): void { throw new \RuntimeException('always fails'); }
        };

        $inMemory = new InMemoryBackend();
        $multi = MultiBackend::withContinueOnError($throwing, $inMemory);

        // With 2 children (throwing + inMemory), gauge() fanout catches 1 error (throwing) while inMemory succeeds.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MultiBackend: 1 child backend(s) failed');
        $multi->gauge('temp', 99.9);
    }

    public function testContinueOnErrorWithHistogram(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public function counter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function gauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function histogram(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function upDownCounter(string $_name, float $_amount, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncCounter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncGauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function describe(Descriptor $_descriptor): void { throw new \RuntimeException('always fails'); }
            public function flush(): void { throw new \RuntimeException('always fails'); }
            public function remove(string $_name, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function clear(): void { throw new \RuntimeException('always fails'); }
        };

        $inMemory = new InMemoryBackend();
        $multi = MultiBackend::withContinueOnError($throwing, $inMemory);

        // sugar-metrics-012: try/fail/catch so the child-received assertion runs.
        try {
            $multi->histogram('latency', 0.05);
            $this->fail('expected the aggregated MultiBackendException');
        } catch (MultiBackendException $e) {
            $this->assertStringContainsString('1 child backend(s) failed', $e->getMessage());
        }

        // inMemory received the histogram despite the throwing sibling.
        $this->assertSame([0.05], $inMemory->histogramValues('latency'));
    }

    public function testContinueOnErrorWithUpDownCounter(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public function counter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function gauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function histogram(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function upDownCounter(string $_name, float $_amount, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncCounter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncGauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function describe(Descriptor $_descriptor): void { throw new \RuntimeException('always fails'); }
            public function flush(): void { throw new \RuntimeException('always fails'); }
            public function remove(string $_name, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function clear(): void { throw new \RuntimeException('always fails'); }
        };

        $inMemory = new InMemoryBackend();
        $multi = MultiBackend::withContinueOnError($throwing, $inMemory);

        // sugar-metrics-012: try/fail/catch so the child-received assertion runs.
        try {
            $multi->upDownCounter('delta', 7.0);
            $this->fail('expected the aggregated MultiBackendException');
        } catch (MultiBackendException $e) {
            $this->assertStringContainsString('1 child backend(s) failed', $e->getMessage());
        }

        $this->assertSame(7.0, $inMemory->upDownCounterValue('delta'));
    }

    public function testContinueOnErrorWithAsyncCounter(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public function counter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function gauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function histogram(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function upDownCounter(string $_name, float $_amount, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncCounter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncGauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function describe(Descriptor $_descriptor): void { throw new \RuntimeException('always fails'); }
            public function flush(): void { throw new \RuntimeException('always fails'); }
            public function remove(string $_name, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function clear(): void { throw new \RuntimeException('always fails'); }
        };

        $inMemory = new InMemoryBackend();
        $multi = MultiBackend::withContinueOnError($throwing, $inMemory);

        // sugar-metrics-012: try/fail/catch so the child-received assertion runs.
        try {
            $multi->asyncCounter('jvm_gc', 3.0);
            $this->fail('expected the aggregated MultiBackendException');
        } catch (MultiBackendException $e) {
            $this->assertStringContainsString('1 child backend(s) failed', $e->getMessage());
        }

        $this->assertSame(3.0, $inMemory->asyncCounterValue('jvm_gc'));
    }

    public function testContinueOnErrorWithAsyncGauge(): void
    {
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public function counter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function gauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function histogram(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function upDownCounter(string $_name, float $_amount, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncCounter(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncGauge(string $_name, float $_value, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function describe(Descriptor $_descriptor): void { throw new \RuntimeException('always fails'); }
            public function flush(): void { throw new \RuntimeException('always fails'); }
            public function remove(string $_name, array $_tags = []): void { throw new \RuntimeException('always fails'); }
            public function clear(): void { throw new \RuntimeException('always fails'); }
        };

        $inMemory = new InMemoryBackend();
        $multi = MultiBackend::withContinueOnError($throwing, $inMemory);

        // sugar-metrics-012: try/fail/catch so the child-received assertion runs.
        try {
            $multi->asyncGauge('heap', 4.0);
            $this->fail('expected the aggregated MultiBackendException');
        } catch (MultiBackendException $e) {
            $this->assertStringContainsString('1 child backend(s) failed', $e->getMessage());
        }

        $this->assertSame(4.0, $inMemory->asyncGaugeValue('heap'));
    }

    /**
     * sugar-metrics-027: the fanout hot path must not allocate a closure
     * per emit. Structural pin over the source text: no verb may hand
     * fanout() an arrow/function closure, and the discriminator match
     * dispatch must exist. (Behavior pins for byte-delegation are the
     * existing "reaches all children" suite — same calls, same throws.)
     */
    public function testFanoutDispatchesByDiscriminatorAndNeverAllocatesPerCallClosure(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Backend/MultiBackend.php');
        // No fanout() call site passes a closure any more.
        $this->assertStringNotContainsString('$this->fanout(fn(', $src);
        $this->assertStringNotContainsString('function (Backend $b)', $src);
        $this->assertStringNotContainsString('fn(Backend $b)', $src);
        // The match-dispatch seam exists and covers all ten verbs.
        $this->assertStringContainsString('private function dispatch(Backend $b, string $op, array $args): void', $src);
        $this->assertStringContainsString('match ($op)', $src);
        foreach (['OP_COUNTER', 'OP_GAUGE', 'OP_HISTOGRAM', 'OP_UP_DOWN_COUNTER', 'OP_ASYNC_COUNTER', 'OP_ASYNC_GAUGE', 'OP_DESCRIBE', 'OP_REMOVE', 'OP_CLEAR', 'OP_FLUSH'] as $const) {
            $this->assertMatchesRegularExpression('/private const ' . $const . ' =/', $src);
        }
    }
}
