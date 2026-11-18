<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\WorkerPool;
use SugarCraft\Core\WorkerState;

/**
 * Regression for audit rank 109: draining a worker's stdout re-sliced the
 * whole buffer once per line (O(lines x buffer)). The line cursor walks the
 * buffer once and cuts the consumed prefix once per drain.
 */
final class WorkerPoolLineCursorTest extends TestCase
{
    private function drain(WorkerPool $pool, WorkerState $worker): void
    {
        (new \ReflectionMethod(WorkerPool::class, 'drainWorkerLines'))->invoke($pool, $worker);
    }

    private function worker(): WorkerState
    {
        return new WorkerState(id: 1, process: null, stdin: null, stdout: null, stderr: null);
    }

    public function testAHugeBufferOfLinesDrainsInOnePassWithoutQuadraticCost(): void
    {
        $pool = new WorkerPool(new StreamSelectLoop(), 1);
        $worker = $this->worker();
        // 200k "READY" lines is about 1.2 MB: a per-line re-slice would copy the
        // remaining buffer 200k times, which takes minutes, not milliseconds.
        $worker->buffer = str_repeat("READY\n", 200_000);

        $started = microtime(true);
        $this->drain($pool, $worker);
        $elapsed = microtime(true) - $started;

        self::assertSame('', $worker->buffer, 'every complete line was consumed');
        self::assertSame(0, $worker->bufferOffset);
        self::assertLessThan(2.0, $elapsed, 'the cursor walks the buffer once');
        $pool->stop();
    }

    public function testAPartialLineWaitsForItsNewlineAcrossReads(): void
    {
        $pool = new WorkerPool(new StreamSelectLoop(), 1);
        $worker = $this->worker();

        $worker->buffer = 'REA';
        $this->drain($pool, $worker);
        self::assertSame('REA', $worker->buffer, 'no newline yet, so nothing is consumed');

        $worker->buffer .= "DY\nREADY\nRE";
        $this->drain($pool, $worker);
        self::assertSame('RE', $worker->buffer, 'the two complete lines went, the fragment stays');
        self::assertSame(0, $worker->bufferOffset);
        $pool->stop();
    }

    public function testTheCursorSurvivesAHandlerThatClearsTheBuffer(): void
    {
        $pool = new WorkerPool(new StreamSelectLoop(), 1);
        $worker = $this->worker();
        $worker->buffer = "READY\nREADY\n";

        // A line is consumed, so the cursor is past the first newline; a buffer
        // emptied under the cursor must end the drain without a ValueError.
        $worker->bufferOffset = strlen($worker->buffer) + 5;
        $this->drain($pool, $worker);

        self::assertSame(0, $worker->bufferOffset);
        $pool->stop();
    }
}
