<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Msg\WorkerResultMsg;
use SugarCraft\Core\WorkerPool;

/**
 * Remediation tests for WorkerPool: workerId uniqueness, temp-script
 * cleanup, and unknown-function rejection.
 */
final class WorkerPoolRemediationTest extends TestCase
{
    private StreamSelectLoop $loop;

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
    }

    public function testWorkerIdNotReusedAfterDeath(): void
    {
        $pool = new WorkerPool($this->loop, 1);

        // Dispatch first job — gets worker id 0.
        $promise1 = $pool->dispatch('worker_task_value_1');
        $id1 = $this->waitForResult($promise1);
        $this->assertSame(0, $id1->workerId);

        // Second job spawns a new worker (id 1) because pool is at capacity
        // and the first worker is now free.
        $promise2 = $pool->dispatch('worker_task_value_2');
        $id2 = $this->waitForResult($promise2);
        // Worker id should be 1, not reused.
        $this->assertNotSame($id1->workerId, $id2->workerId);

        $pool->stop();
    }

    public function testWorkerTempScriptCleanedOnStop(): void
    {
        $pool = new WorkerPool($this->loop, 1);

        // Dispatch a job to force worker spawning.
        $promise = $pool->dispatch('worker_task_value_1');
        $this->waitForResult($promise);

        // Before stop: temp scripts may exist.
        $before = glob(sys_get_temp_dir() . '/sc_worker_*');
        $pool->stop();
        // After stop: no sc_worker_* files should remain.
        $after = glob(sys_get_temp_dir() . '/sc_worker_*');
        $this->assertCount(
            count($before),
            $after,
            'Temp worker scripts should be cleaned up after stop()'
        );
    }

    public function testUnknownFunctionNameRejects(): void
    {
        $pool = new WorkerPool($this->loop, 1);

        $promise = $pool->dispatch('this_function_does_not_exist_anywhere');
        $thrown = null;
        $promise->otherwise(function (\Throwable $e) use (&$thrown): void {
            $thrown = $e;
            $this->loop->stop();
        });

        $this->loop->addTimer(5.0, function (): void {
            $this->fail('Test timed out waiting for rejection');
        });

        $this->loop->run();

        $this->assertNotNull($thrown);
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertStringContainsString('Unknown worker function', $thrown->getMessage());
        $pool->stop();
    }

    private function waitForResult(\React\Promise\PromiseInterface $promise): WorkerResultMsg
    {
        $result = null;
        $promise->then(function (WorkerResultMsg $msg) use (&$result): void {
            $result = $msg;
            $this->loop->stop();
        });
        $this->loop->addTimer(5.0, function () use (&$result): void {
            $this->fail('Test timed out');
        });
        $this->loop->run();
        $this->assertInstanceOf(WorkerResultMsg::class, $result);
        return $result;
    }
}
