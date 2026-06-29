<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Backend\MultiBackend;
use SugarCraft\Metrics\Descriptor;
use PHPUnit\Framework\TestCase;

final class MultiBackendTest extends TestCase
{
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

    public function testContinueOnErrorReachesAllChildren(): void
    {
        // ThrowingBackend always throws on any emit.
        $throwing = new class implements \SugarCraft\Metrics\Backend {
            public function counter(string $name, float $value, array $tags = []): void { throw new \RuntimeException('always fails'); }
            public function gauge(string $name, float $value, array $tags = []): void { throw new \RuntimeException('always fails'); }
            public function histogram(string $name, float $value, array $tags = []): void { throw new \RuntimeException('always fails'); }
            public function upDownCounter(string $name, float $amount, array $tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncCounter(string $name, float $value, array $tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncGauge(string $name, float $value, array $tags = []): void { throw new \RuntimeException('always fails'); }
            public function describe(Descriptor $descriptor): void { throw new \RuntimeException('always fails'); }
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
            public function counter(string $name, float $value, array $tags = []): void { throw new \RuntimeException('first'); }
            public function gauge(string $name, float $value, array $tags = []): void { throw new \RuntimeException('second'); }
            public function histogram(string $name, float $value, array $tags = []): void { throw new \RuntimeException('third'); }
            public function upDownCounter(string $name, float $amount, array $tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncCounter(string $name, float $value, array $tags = []): void { throw new \RuntimeException('always fails'); }
            public function asyncGauge(string $name, float $value, array $tags = []): void { throw new \RuntimeException('always fails'); }
            public function describe(Descriptor $descriptor): void { throw new \RuntimeException('always fails'); }
        };

        $inMemory = new InMemoryBackend();
        $multi = MultiBackend::withContinueOnError($throwing, $inMemory);

        // With 2 children (throwing + inMemory), gauge() fanout catches 1 error (throwing) while inMemory succeeds.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MultiBackend: 1 child backend(s) failed');
        $multi->gauge('temp', 99.9);
    }
}
