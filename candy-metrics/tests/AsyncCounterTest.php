<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Instrument\AsyncCounter;
use SugarCraft\Metrics\Registry;
use PHPUnit\Framework\TestCase;

final class AsyncCounterTest extends TestCase
{
    public function testObserveCallsCallbackAndRecordsValue(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = new AsyncCounter($r, 'db.pool.connections', 'Active DB connections', fn() => 42.0);
        $counter->observe();
        $this->assertSame(42.0, $b->asyncCounterValue('db.pool.connections'));
    }

    public function testObserveUpdatesValueOnSubsequentCalls(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = new AsyncCounter($r, 'queue.size', 'Queue size', fn() => 10.0);
        $counter->observe();
        $this->assertSame(10.0, $b->asyncCounterValue('queue.size'));
        $counter = new AsyncCounter($r, 'queue.size', 'Queue size', fn() => 25.0);
        $counter->observe();
        $this->assertSame(25.0, $b->asyncCounterValue('queue.size'));
    }

    public function testObserveWithTags(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = new AsyncCounter($r, 'gc.collections', 'GC runs', fn() => 5.0, ['generation' => 'gen0']);
        $counter->observe();
        $this->assertSame(5.0, $b->asyncCounterValue('gc.collections', ['generation' => 'gen0']));
    }

    public function testNameAndHelpAreAccessible(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $counter = new AsyncCounter($r, 'memory.usage', 'Memory usage in MB', fn() => 128.0);
        $this->assertSame('memory.usage', $counter->name());
        $this->assertSame('Memory usage in MB', $counter->help());
    }
}
