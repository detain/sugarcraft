<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\WorkerPool;
use SugarCraft\Core\WorkerState;

/**
 * The stdout line scan walks a read cursor and cuts the consumed prefix once
 * (audit rank 109). The old loop re-copied the whole tail for every line.
 */
final class WorkerPoolLineCursorTest extends TestCase
{
    public function testDrainHandsOverEveryCompleteLineAndKeepsOnlyTheTail(): void
    {
        $loop = new StreamSelectLoop();
        $pool = new WorkerPool($loop, 1);
        $worker = new WorkerState(1, null, null, null, null);
        // READY lines are consumed by handleWorkerLine() without side effects, so the
        // scan can run on a bare WorkerState.
        $worker->buffer = str_repeat("READY\n", 5000) . 'partial';

        $drain = new \ReflectionMethod(WorkerPool::class, 'drainWorkerLines');
        $drain->invoke($pool, $worker);

        $this->assertSame('partial', $worker->buffer, 'the unterminated tail stays buffered');
        $this->assertSame(0, $worker->cursor, 'the cursor is back at zero between polls');

        $pool->stop();
    }

    public function testDrainWithNoCompleteLineLeavesTheBufferAlone(): void
    {
        $loop = new StreamSelectLoop();
        $pool = new WorkerPool($loop, 1);
        $worker = new WorkerState(2, null, null, null, null);
        $worker->buffer = 'no newline yet';

        $drain = new \ReflectionMethod(WorkerPool::class, 'drainWorkerLines');
        $drain->invoke($pool, $worker);

        $this->assertSame('no newline yet', $worker->buffer);
        $this->assertSame(0, $worker->cursor);

        $pool->stop();
    }
}
