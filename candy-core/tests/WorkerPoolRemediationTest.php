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
        $promise1 = $pool->dispatch('getmypid');
        $id1 = $this->waitForResult($promise1);
        $this->assertSame(0, $id1->workerId);

        // Kill the worker behind the pool's back, then spin the loop until
        // the pool's poll tick reaps it (feof on the worker's stdout).
        $prop = new \ReflectionProperty(WorkerPool::class, 'workers');
        $workers = $prop->getValue($pool);
        $worker = reset($workers);
        \proc_terminate($worker->process, 9);

        $deadline = \microtime(true) + 5.0;
        $timer = $this->loop->addPeriodicTimer(0.05, function () use ($pool, $prop, $deadline): void {
            if ($prop->getValue($pool) === [] || \microtime(true) > $deadline) {
                $this->loop->stop();
            }
        });
        $this->loop->run();
        $this->loop->cancelTimer($timer);
        $this->assertSame([], $prop->getValue($pool), 'pool must reap the killed worker');

        // The replacement worker gets a fresh id — ids are never reused.
        $promise2 = $pool->dispatch('time');
        $id2 = $this->waitForResult($promise2);
        $this->assertNotSame($id1->workerId, $id2->workerId);

        $pool->stop();
    }

    public function testWorkerTempScriptCleanedOnStop(): void
    {
        // Baseline BEFORE the pool exists — /tmp may hold stale sc_worker_*
        // files from unrelated (or crashed) runs; only OUR delta matters.
        $baseline = glob(sys_get_temp_dir() . '/sc_worker_*');

        $pool = new WorkerPool($this->loop, 1);

        // Dispatch a job to force worker spawning.
        $promise = $pool->dispatch('getmypid');
        $this->waitForResult($promise);

        $pool->stop();
        // After stop: every script this pool created must be gone again.
        $after = glob(sys_get_temp_dir() . '/sc_worker_*');
        $this->assertCount(
            count($baseline),
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

    /**
     * Audit 2026-09-30: an ERRORED completion must still reconcile the queue. Previously
     * resolveJob() only handed the next queued job to the worker when the
     * completion carried no error, so one failing job stalled everything
     * queued behind it — nothing else pairs idle workers with queued jobs
     * (tick() only polls stdout).
     */
    public function testQueuedJobIsHandedOffAfterAnErroredCompletion(): void
    {
        $pool = new WorkerPool($this->loop, 1);

        // Job A fails inside the worker (concurrency 1, so the worker is busy).
        $pool->dispatch('this_function_does_not_exist_anywhere')
            ->catch(static function (): void {
            });

        // Job B queues behind A and must run the moment A's worker frees up.
        $resolved = null;
        $pool->dispatch('php_sapi_name')
            ->then(function (WorkerResultMsg $msg) use (&$resolved): void {
                $resolved = $msg;
                $this->loop->stop();
            })
            ->catch(function (\Throwable $e): void {
                $this->loop->stop();
                $this->fail('queued job rejected: ' . $e->getMessage());
            });

        $this->loop->addTimer(10.0, function (): void {
            $this->loop->stop();
            $this->fail('queued job stalled behind an errored completion');
        });

        $this->loop->run();

        $this->assertInstanceOf(WorkerResultMsg::class, $resolved);
        $this->assertSame('cli', $resolved->result);
        $pool->stop();
    }

    /**
     * Audit 2026-09-30: stop() must clear the started flag. Previously a later dispatch()
     * skipped start() (no tick timer re-armed) yet still spawned a worker and
     * wrote the job to its stdin — the child ran, its stdout was never polled,
     * and the promise could never settle: a resurrected pool that only looks
     * alive.
     */
    public function testDispatchAfterStopRestartsThePool(): void
    {
        $pool = new WorkerPool($this->loop, 1);
        $pool->dispatch('php_sapi_name')->catch(static function (): void {
        });
        $pool->stop();

        $resolved = null;
        $pool->dispatch('php_sapi_name')
            ->then(function (WorkerResultMsg $msg) use (&$resolved): void {
                $resolved = $msg;
                $this->loop->stop();
            })
            ->catch(function (\Throwable $e): void {
                $this->loop->stop();
                $this->fail('post-stop dispatch rejected: ' . $e->getMessage());
            });

        $this->loop->addTimer(10.0, function (): void {
            $this->loop->stop();
            $this->fail('post-stop dispatch never settled — the tick timer was not re-armed');
        });

        $this->loop->run();

        $this->assertInstanceOf(WorkerResultMsg::class, $resolved);
        $this->assertSame('cli', $resolved->result);
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
