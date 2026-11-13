<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\Cmd\WorkerCmd;
use SugarCraft\Core\Exception\ProgramException;
use SugarCraft\Core\Msg\ExceptionMsg;
use SugarCraft\Core\Msg\WorkerResultMsg;
use SugarCraft\Core\WorkerPool;
use SugarCraft\Core\WorkerState;

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
        $promise = $pool->dispatch([WorkerPoolTasks::class, 'pi']);
        $this->assertInstanceOf(\React\Promise\PromiseInterface::class, $promise);

        // stop() rejects every unsettled job (E716). Handle that rejection
        // here: a discarded rejected promise dumps an unhandled-rejection
        // stack trace over an otherwise green suite.
        $rejected = null;
        $promise->catch(function (\Throwable $e) use (&$rejected): void {
            $rejected = $e;
        });
        $pool->stop();

        $this->assertInstanceOf(\RuntimeException::class, $rejected);
        $this->assertStringContainsString('Worker pool stopped before job 1 completed', $rejected->getMessage());
    }

    public function testPoolResolvesPromiseWithResult(): void
    {
        $pool = new WorkerPool($this->loop, 2);

        // Class callables resolve in the child through the autoloader the
        // pool hands it via argv (sc-29): the fixture class below is defined
        // in this test directory, exactly where the old string channel could
        // never reach.
        $promise = $pool->dispatch([WorkerPoolTasks::class, 'sapiName']);

        $resolved = null;
        $promise->then(function (WorkerResultMsg $msg) use (&$resolved): void {
            $resolved = $msg;
            $this->loop->stop();
        })->catch(function (\Throwable $e): void {
            $this->loop->stop();
            $this->fail('dispatch rejected: ' . $e->getMessage());
        });

        $this->loop->addTimer(10.0, function (): void {
            $this->loop->stop();
            $this->fail('Test timed out waiting for worker result');
        });

        $this->spinLoop();

        $this->assertInstanceOf(WorkerResultMsg::class, $resolved);
        $this->assertSame('cli', $resolved->result);
        $this->assertNull($resolved->error);
        $pool->stop();
    }

    public function testPoolHandlesExceptionFromTask(): void
    {
        $pool = new WorkerPool($this->loop, 2);

        // A task that throws inside the worker subprocess must round-trip
        // its RuntimeException back over the pipe as an errored result.
        $promise = $pool->dispatch([WorkerPoolTasks::class, 'boom']);

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
        $this->assertStringContainsString('task exploded', $resolved->error->getMessage());
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

        // Six jobs against two workers: every result is a worker PID, so the
        // distinct-PID count proves the concurrency bound.
        $promises = [];
        for ($i = 0; $i < 6; $i++) {
            $promises[] = $pool->dispatch([WorkerPoolTasks::class, 'pid']);
        }

        $results = [];
        $expectedCount = count($promises);
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
        foreach ($results as $pid) {
            $this->assertIsInt($pid);
            $this->assertGreaterThan(0, $pid);
        }
        $this->assertLessThanOrEqual(2, count(array_unique($results)), 'no more than 2 worker processes may exist');
        $pool->stop();
    }

    public function testWorkerCmdRunReturnsClosure(): void
    {
        $pool = new WorkerPool($this->loop, 2);
        $cmd = WorkerCmd::run($pool, [WorkerPoolTasks::class, 'pi']);
        $this->assertInstanceOf(\Closure::class, $cmd);
        $pool->stop();
    }

    public function testWorkerCmdRunReturnsAsyncCmdWhenExecuted(): void
    {
        $pool = new WorkerPool($this->loop, 2);
        $cmd = WorkerCmd::run($pool, [WorkerPoolTasks::class, 'pi']);
        $result = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $result);
        $pool->stop();
    }

    public function testWorkerCmdReturnsResultViaMsg(): void
    {
        $pool = new WorkerPool($this->loop, 2);
        $asyncCmd = WorkerCmd::run($pool, [WorkerPoolTasks::class, 'sapiName'])();

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
        $this->assertSame('cli', $resolved->result);
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

    public function testStringTaskRefusedByName(): void
    {
        // sc-29: the global-function channel is removed. A string task throws
        // a named InvalidArgumentException synchronously at dispatch — no
        // promise is created, nothing is parked, no child is contacted.
        $pool = new WorkerPool($this->loop, 1);
        try {
            $pool->dispatch('strlen');
            $this->fail('string dispatch must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('string worker task', $e->getMessage());
            $this->assertStringContainsString('strlen', $e->getMessage());
        }
        $pending = new \ReflectionProperty(WorkerPool::class, 'pending');
        $this->assertSame([], $pending->getValue($pool), 'refused dispatch parks nothing');
        $pool->stop();
    }

    public function testChildSeesMergedEnvironment(): void
    {
        $parentPath = getenv('PATH');
        if ($parentPath === false || $parentPath === '') {
            $this->markTestSkipped('no PATH in the parent environment to merge');
        }

        $pool = new WorkerPool($this->loop, 1);
        $promise = $pool->dispatch([WorkerPoolTasks::class, 'childPath']);

        $resolved = null;
        $promise->then(function (WorkerResultMsg $msg) use (&$resolved): void {
            $resolved = $msg;
            $this->loop->stop();
        })->catch(function (\Throwable $e): void {
            $this->loop->stop();
            $this->fail('dispatch rejected: ' . $e->getMessage());
        });
        $this->loop->addTimer(10.0, function (): void {
            $this->loop->stop();
            $this->fail('test timed out waiting for the env probe');
        });
        $this->spinLoop();

        $this->assertInstanceOf(WorkerResultMsg::class, $resolved);
        $this->assertSame($parentPath, $resolved->result, 'child PATH must be the merged parent environment');
        $pool->stop();
    }

    public function testLibraryExceptionRoundTripsRealClass(): void
    {
        // The response allow-list names sugar-core's own exception classes.
        // sc-29 proves BOTH arms: a thrown library exception rejects the
        // promise as the REAL class, and a returned exception VALUE
        // re-materialises as the real class inside the resolved message —
        // in either case never as __PHP_Incomplete_Class.
        $pool = new WorkerPool($this->loop, 1);
        $rejected = null;
        $pool->dispatch([WorkerPoolTasks::class, 'throwProgramException'])
            ->then(null, function (\Throwable $e) use (&$rejected): void {
                $rejected = $e;
                $this->loop->stop();
            });
        $this->loop->addTimer(10.0, function (): void {
            $this->loop->stop();
            $this->fail('test timed out waiting for the thrower');
        });
        $this->spinLoop();

        $this->assertInstanceOf(ProgramException::class, $rejected);
        $this->assertStringContainsString('worker-side program failure', $rejected->getMessage());

        $resolved = null;
        $pool->dispatch([WorkerPoolTasks::class, 'programException'])
            ->then(function (WorkerResultMsg $msg) use (&$resolved): void {
                $resolved = $msg;
                $this->loop->stop();
            });
        $this->loop->addTimer(10.0, function (): void {
            $this->loop->stop();
            $this->fail('test timed out waiting for the value');
        });
        $this->spinLoop();

        $this->assertInstanceOf(WorkerResultMsg::class, $resolved);
        $this->assertInstanceOf(ProgramException::class, $resolved->result);
        $this->assertSame(ProgramException::class, get_class($resolved->result));
        $pool->stop();
    }

    public function testInvokableObjectTask(): void
    {
        // An invokable object is a serializable callable (no closure inside):
        // the envelope carries its class in the allow-list and the child
        // reconstructs it from the autoloader.
        $pool = new WorkerPool($this->loop, 1);
        $promise = $pool->dispatch(new WorkerPoolTasks());

        $resolved = null;
        $promise->then(function (WorkerResultMsg $msg) use (&$resolved): void {
            $resolved = $msg;
            $this->loop->stop();
        })->catch(function (\Throwable $e): void {
            $this->loop->stop();
            $this->fail('dispatch rejected: ' . $e->getMessage());
        });
        $this->loop->addTimer(10.0, function (): void {
            $this->loop->stop();
            $this->fail('test timed out waiting for the invokable probe');
        });
        $this->spinLoop();

        $this->assertInstanceOf(WorkerResultMsg::class, $resolved);
        $this->assertSame('invoked', $resolved->result);
        $pool->stop();
    }

    public function testMalformedResponseRedactsBytes(): void
    {
        // sc-29: garbage on a worker's stdout must reject the parked job with
        // a size+digest message, never with the raw frame bytes.
        $pool = new WorkerPool($this->loop, 1);
        $rejected = null;
        $pool->dispatch([WorkerPoolTasks::class, 'pi'])
            ->then(null, function (\Throwable $e) use (&$rejected): void {
                $rejected = $e;
            });

        $workersProp = new \ReflectionProperty(WorkerPool::class, 'workers');
        $workers = $workersProp->getValue($pool);
        $worker = reset($workers);

        $handle = new \ReflectionMethod(WorkerPool::class, 'handleWorkerLine');
        $junk = 'xxNOT-A-SERIALIZED-FRAME-9f3a!';
        $handle->invoke($pool, $worker, $junk);

        $this->assertInstanceOf(\RuntimeException::class, $rejected, 'malformed line rejects the parked job');
        $this->assertStringContainsString('malformed worker response', $rejected->getMessage());
        $this->assertStringContainsString((string) strlen($junk), $rejected->getMessage(), 'frame length is disclosed');
        $this->assertStringNotContainsString('NOT-A-SERIALIZED-FRAME', $rejected->getMessage(), 'raw bytes are withheld');
        $pool->stop();
    }

    private function spinLoop(): void
    {
        $this->loop->run();
    }
}
