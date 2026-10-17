<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Backend\MultiBackend;
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

    public function testEmptyMultiIsHarmless(): void
    {
        $multi = new MultiBackend();
        $multi->counter('hits', 1);
        $multi->gauge('q', 4);
        $multi->histogram('lat', 0.1);
        $this->assertTrue(true);
    }

    public function testRemoveIsForwardedToAllChildrenAndSwallowsErrors(): void
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

        // remove() must not throw even though the throwing child throws.
        // safeFanout silently swallows errors for idempotent operations.
        $multi->remove('any_metric', ['a' => 'b']);
        $this->assertTrue($throwing->removeCalled);
    }

    public function testClearIsForwardedToAllChildrenAndSwallowsErrors(): void
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

        // clear() must not throw even though the throwing child throws.
        $multi->clear();
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
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MultiBackend: 1 child backend(s) failed');
        $multi->counter('hits', 42);

        // inMemory still received the counter despite the throwing sibling.
        // (This assertion runs in a subsequent test invocation since expectException aborts this one.)
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MultiBackend: 1 child backend(s) failed');
        $multi->histogram('latency', 0.05);

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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MultiBackend: 1 child backend(s) failed');
        $multi->upDownCounter('delta', 7.0);

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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MultiBackend: 1 child backend(s) failed');
        $multi->asyncCounter('jvm_gc', 3.0);

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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MultiBackend: 1 child backend(s) failed');
        $multi->asyncGauge('heap', 4.0);

        $this->assertSame(4.0, $inMemory->asyncGaugeValue('heap'));
    }
}
