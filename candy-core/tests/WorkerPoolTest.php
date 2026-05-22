<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\Cmd\WorkerCmd;
use SugarCraft\Core\Msg\ExceptionMsg;
use SugarCraft\Core\Msg\WorkerResultMsg;
use SugarCraft\Core\WorkerPool;
use SugarCraft\Core\WorkerState;

function worker_task_add(int $a, int $b): int
{
    return $a + $b;
}

function worker_task_return_string(): string
{
    return 'hello worker';
}

function worker_task_throw(): void
{
    throw new \RuntimeException('task error in worker');
}

function worker_task_double(int $x): int
{
    return $x * 2;
}

function worker_task_add_seven_three(): int
{
    return 7 + 3;
}

function worker_task_value_1(): int
{
    return 1 * 2;
}
function worker_task_value_2(): int
{
    return 2 * 2;
}
function worker_task_value_3(): int
{
    return 3 * 2;
}
function worker_task_value_4(): int
{
    return 4 * 2;
}
function worker_task_value_5(): int
{
    return 5 * 2;
}
function worker_task_value_6(): int
{
    return 6 * 2;
}

final class WorkerPoolTest extends TestCase
{
    private StreamSelectLoop $loop;

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
    }

    public function testWorkerResultMsgStructure(): void
    {
        $msg = new WorkerResultMsg(result: 42, error: null, workerId: 1);
        $this->assertSame(42, $msg->result);
        $this->assertNull($msg->error);
        $this->assertSame(1, $msg->workerId);
    }

    public function testWorkerResultMsgWithError(): void
    {
        $error = new \RuntimeException('task failed');
        $msg = new WorkerResultMsg(result: null, error: $error, workerId: 2);
        $this->assertNull($msg->result);
        $this->assertSame($error, $msg->error);
        $this->assertSame(2, $msg->workerId);
    }

    public function testPoolDispatchReturnsPromise(): void
    {
        $pool = new WorkerPool($this->loop, 2);
        $promise = $pool->dispatch('1 + 1');
        $this->assertInstanceOf(\React\Promise\PromiseInterface::class, $promise);
        $pool->stop();
    }

    public function testPoolResolvesPromiseWithResult(): void
    {
        $pool = new WorkerPool($this->loop, 2);

        $promise = $pool->dispatch('"hello worker"');

        $resolved = null;
        $promise->then(function (WorkerResultMsg $msg) use (&$resolved): void {
            $resolved = $msg;
            $this->loop->stop();
        });

        $this->spinLoop();

        $this->assertInstanceOf(WorkerResultMsg::class, $resolved);
        $this->assertSame('hello worker', $resolved->result);
        $this->assertNull($resolved->error);
        $pool->stop();
    }

    public function testPoolHandlesExceptionFromTask(): void
    {
        $pool = new WorkerPool($this->loop, 2);

        $promise = $pool->dispatch('(function() { throw new RuntimeException("task error in worker"); })()');

        $resolved = null;
        $promise->then(function (WorkerResultMsg $msg) use (&$resolved): void {
            $resolved = $msg;
            $this->loop->stop();
        })->catch(function (\Throwable $e) use (&$resolved): void {
            $resolved = new WorkerResultMsg(result: null, error: $e, workerId: 0);
            $this->loop->stop();
        });

        $this->spinLoop();

        $this->assertInstanceOf(WorkerResultMsg::class, $resolved);
        $this->assertNull($resolved->result);
        $this->assertInstanceOf(\RuntimeException::class, $resolved->error);
        $this->assertSame('task error in worker', $resolved->error->getMessage());
        $pool->stop();
    }

    public function testPoolDefaultConcurrency(): void
    {
        $pool = new WorkerPool($this->loop);
        $this->assertSame(4, $pool->concurrency());
        $pool->stop();
    }

    public function testPoolCustomConcurrency(): void
    {
        $pool = new WorkerPool($this->loop, 8);
        $this->assertSame(8, $pool->concurrency());
        $pool->stop();

        $pool2 = new WorkerPool($this->loop, 1);
        $this->assertSame(1, $pool2->concurrency());
        $pool2->stop();
    }

    public function testPoolConcurrencyIsBounded(): void
    {
        $pool = new WorkerPool($this->loop, 2);

        $codes = ['(1) * 2', '(2) * 2', '(3) * 2', '(4) * 2', '(5) * 2', '(6) * 2'];

        $promises = [];
        foreach ($codes as $code) {
            $promises[] = $pool->dispatch($code);
        }

        $results = [];
        $expectedCount = count($codes);
        foreach ($promises as $promise) {
            $promise->then(function (WorkerResultMsg $msg) use (&$results, $expectedCount): void {
                $results[] = $msg->result;
                if (count($results) === $expectedCount) {
                    $this->loop->stop();
                }
            });
        }

        $this->loop->addTimer(10.0, function () use (&$results, $expectedCount): void {
            $this->fail('Test timed out: got ' . count($results) . ' of ' . $expectedCount . ' results');
        });

        $this->spinLoop();

        $this->assertCount(6, $results);
        sort($results);
        $this->assertSame([2, 4, 6, 8, 10, 12], $results);
        $pool->stop();
    }

    public function testWorkerCmdRunReturnsClosure(): void
    {
        $pool = new WorkerPool($this->loop, 2);
        $cmd = WorkerCmd::run($pool, '1 + 1');
        $this->assertInstanceOf(\Closure::class, $cmd);
        $pool->stop();
    }

    public function testWorkerCmdRunReturnsAsyncCmdWhenExecuted(): void
    {
        $pool = new WorkerPool($this->loop, 2);
        $cmd = WorkerCmd::run($pool, '1 + 1');
        $result = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $result);
        $pool->stop();
    }

    public function testWorkerCmdReturnsResultViaMsg(): void
    {
        $pool = new WorkerPool($this->loop, 2);
        $asyncCmd = WorkerCmd::run($pool, '7 + 3')();

        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);

        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
            $this->loop->stop();
        });

        $this->loop->addTimer(5.0, function () use ($pool): void {
            $pool->stop();
            $this->fail('Test timed out');
        });

        $this->spinLoop();

        $this->assertInstanceOf(WorkerResultMsg::class, $resolved);
        $this->assertSame(10, $resolved->result);
        $pool->stop();
    }

    public function testWorkerStateProperties(): void
    {
        $state = new WorkerState(
            id: 5,
            process: null,
            stdin: null,
            stdout: null,
            stderr: null,
        );

        $this->assertSame(5, $state->id);
        $this->assertTrue($state->idle);
        $this->assertNull($state->currentJobId);
        $this->assertSame('', $state->buffer);
    }

    private function spinLoop(): void
    {
        $this->loop->run();
    }
}
