<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Support\ForkedChild;

/**
 * Tests for AgentWorkerPool - parallel execution worker pool.
 */
final class AgentWorkerPoolTest extends TestCase
{
    private CompleteRequest $request;

    /** @var string Per-test temp root to ensure test isolation */
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset the static counter used by makeAgent() for test isolation.
        // Without this, a prior test that advanced the counter would cause
        // this test's makeAgent() calls (that rely on implicit IDs) to get
        // unexpected values like 'agent-4' instead of 'agent-1'.
        $GLOBALS['__agentCounterReset'] = true;

        $this->tempRoot = sys_get_temp_dir() . '/sc_pool_' . uniqid('test_', true) . '/';
        mkdir($this->tempRoot, 0755, true);
        $this->request = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Hello!']],
        );
    }

    protected function tearDown(): void
    {
        // Clean up per-test temp directory and all result files
        if (!empty($this->tempRoot) && is_dir($this->tempRoot)) {
            $files = glob($this->tempRoot . '*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->tempRoot);
        }

        parent::tearDown();
    }

    /**
     * Helper: create a SubAgent with a unique ID.
     */
    private function makeAgent(string $id = null, string $name = 'TestAgent'): SubAgent
    {
        static $counter = 0;

        // Reset counter when requested (from setUp) before the first agent is
        // created for this test. This ensures test isolation for tests that
        // rely on implicit (counter-based) IDs.
        if ($GLOBALS['__agentCounterReset'] ?? false) {
            $GLOBALS['__agentCounterReset'] = false;
            $counter = 0;
        }

        $id = $id ?? 'agent-' . ++$counter;

        return new SubAgent(
            id: $id,
            agent: new Agent(
                name: $name,
                description: 'Test agent',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'Test task for ' . $id,
        );
    }

    /**
     * Helper: build a blocking mock executor that records calls and returns a completed result.
     *
     * Returns predictable results for each agent ID.
     */
    private function makeBlockingExecutor(array $resultsByAgentId = []): ExecutorInterface
    {
        $executor = $this->createMock(ExecutorInterface::class);

        $executor->method('execute')
            ->willReturnCallback(function (SubAgent $agent) use ($resultsByAgentId) {
                if (isset($resultsByAgentId[$agent->id])) {
                    return $resultsByAgentId[$agent->id];
                }
                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Completed,
                    output: 'Output from ' . $agent->id,
                );
            });

        $executor->method('executeStream')
            ->willReturnCallback(function (SubAgent $agent) use ($resultsByAgentId) {
                if (isset($resultsByAgentId[$agent->id])) {
                    yield $resultsByAgentId[$agent->id];
                } else {
                    yield new AgentResult(
                        agentId: $agent->id,
                        status: AgentStatus::Completed,
                        output: 'Output from ' . $agent->id,
                    );
                }
            });

        $executor->method('cancel')->willReturnCallback(function (string $agentId) {});
        $executor->method('cancelAll')->willReturnCallback(function () {});

        return $executor;
    }

    /**
     * Run $body under a hard SIGALRM deadline.
     *
     * EVERY test in this file that drives a real executeAll() over real forks
     * must be wrapped in this, because the regression those tests exist to
     * catch is a HANG: phpunit.xml sets no enforceTimeLimit, so an unbounded
     * real run wedges the entire suite instead of reporting a failure — the
     * pool breaks and CI tells you nothing. Measuring elapsed time after the
     * run is not a bound either; the assertion is never reached.
     *
     * The alarm is armed in the parent only: POSIX clears pending alarms in a
     * forked child, so no pool worker can inherit it. The handler throws, which
     * PHP delivers at the next VM boundary once pcntl_async_signals() is on —
     * interrupting the usleep()/stream_select() a hung pool is sitting in.
     *
     * @template T
     * @param \Closure():T $body
     * @return T
     */
    private function underDeadline(int $seconds, \Closure $body): mixed
    {
        if (!function_exists('pcntl_alarm') || !function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl signal handling is required to bound this test.');
        }

        $previousAsync = pcntl_async_signals(true);
        // Restore the PREVIOUS handler rather than SIG_DFL: resetting to the
        // default silently disarms any outer alarm-based guard, so a nested or
        // enclosing deadline would stop being a deadline the moment this one
        // finished.
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, static function () use ($seconds): void {
            throw new \RuntimeException(sprintf(
                'Exceeded the %ds deadline — the worker pool never settled. A hang IS the '
                . 'regression under test here, so this is a failure, not a flake.',
                $seconds,
            ));
        });
        pcntl_alarm($seconds);

        try {
            return $body();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }
    }

    /**
     * Fork a child that dies immediately without writing anything — the shape
     * of a worker killed by a signal, OOM-ed, or fataled by
     * ProcessExecutor::checkBackpressure()/spawnWorker() throwing.
     *
     * ForkedChild::exitNow() rather than a plain exit(): this is a fork of the
     * PHPUnit process, so a normal exit would run PHPUnit's shutdown functions
     * and every destructor in the child (result printers, temp-dir cleanup,
     * an inherited raw-mode Tty in any process that has one). Production's
     * startAgent() child exits plainly for a documented reason that does not
     * apply here.
     */
    private function forkChildThatDiesSilently(): int
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            ForkedChild::exitNow(0);
        }

        $this->assertNotSame(-1, $pid, 'pcntl_fork() failed.');

        return $pid;
    }

    /**
     * Fork a child that exits at once, and return only once the parent has
     * OBSERVED it exit — so the pid handed back is already dead, but not yet
     * reaped.
     *
     * The witness is EOF on an inherited socket pair rather than
     * pcntl_waitpid(): a wait would collect the child itself, and the whole
     * point is to hand the caller a corpse nobody has claimed yet. A child that
     * is already dead is collectable on a reaper's very FIRST WNOHANG attempt,
     * before it reaches a usleep() — which is what lets a caller assert "the
     * reap ran" without the bounded 100ms reap budget being an available
     * explanation for either answer.
     */
    private function forkChildThatHasAlreadyExited(): int
    {
        if (!function_exists('stream_socket_pair')) {
            $this->markTestSkipped('stream_socket_pair() is required to witness an exit without reaping it.');
        }

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair() is unavailable in this environment.');
        }

        [$parentEnd, $childEnd] = $pair;

        $pid = pcntl_fork();
        if ($pid === 0) {
            // Nothing to do but die: the child's copy of $childEnd closes with
            // the process, and that close is exactly what the parent reads as
            // EOF below.
            ForkedChild::exitNow(0);
        }

        $this->assertNotSame(-1, $pid, 'pcntl_fork() failed.');

        // The parent must drop ITS copy of the write end, or the peer never
        // reaches EOF and the bounded wait below becomes a false failure.
        fclose($childEnd);

        $read = [$parentEnd];
        $write = [];
        $except = [];
        // Bounded, and exhausting it IS the failure: the child does nothing but
        // exit, so 10s without an EOF means it never ran at all.
        $ready = stream_select($read, $write, $except, 10);
        $this->assertSame(
            1,
            $ready,
            'The child never exited, so its pid is not the already-dead corpse this helper promises.',
        );
        $this->assertSame('', (string) fread($parentEnd, 1), 'Expected EOF from the exited child.');
        fclose($parentEnd);

        return $pid;
    }

    /**
     * Drive waitForCompletion() until it settles something, over a bounded
     * number of its own 5ms poll cycles. The cap is a witness, not a timeout —
     * exhausting it is itself the failure.
     */
    private function pollUntilSettled(AgentWorkerPool $pool, int $maxPolls = 400): ?string
    {
        $waitForCompletion = new \ReflectionMethod(AgentWorkerPool::class, 'waitForCompletion');
        $waitForCompletion->setAccessible(true);

        $completedId = null;
        for ($poll = 0; $poll < $maxPolls && $completedId === null; $poll++) {
            $completedId = $waitForCompletion->invoke($pool);
        }

        return $completedId;
    }

    /**
     * Seed a pool's in-flight bookkeeping directly, for the reap paths that
     * need a child in a state a real run cannot be steered into on demand
     * (already dead, already reaped by someone else, still running).
     */
    private function seedInFlight(AgentWorkerPool $pool, SubAgent $agent, int $pid): void
    {
        $activeProp = new \ReflectionProperty(AgentWorkerPool::class, 'active');
        $activeProp->setAccessible(true);
        $activeProp->setValue($pool, [$agent->id => $agent]);

        $activePidsProp = new \ReflectionProperty(AgentWorkerPool::class, 'activePids');
        $activePidsProp->setAccessible(true);
        $activePidsProp->setValue($pool, [$agent->id => $pid]);
    }

    private function readPrivate(AgentWorkerPool $pool, string $property): mixed
    {
        $prop = new \ReflectionProperty(AgentWorkerPool::class, $property);
        $prop->setAccessible(true);

        return $prop->getValue($pool);
    }

    private function extractResultOf(AgentWorkerPool $pool, string $agentId): ?AgentResult
    {
        $extractResult = new \ReflectionMethod(AgentWorkerPool::class, 'extractResult');
        $extractResult->setAccessible(true);

        return $extractResult->invoke($pool, $agentId);
    }

    // -------------------------------------------------------------------------
    // testExecuteAllHandlesEmptyArray
    // -------------------------------------------------------------------------

    public function testExecuteAllHandlesEmptyArray(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 5);
        $results = iterator_to_array($pool->executeAll([], $this->request));

        $this->assertSame([], $results);
    }

    // -------------------------------------------------------------------------
    // testExecuteAllConcurrently
    // -------------------------------------------------------------------------

    public function testExecuteAllConcurrently(): void
    {
        // Use explicit IDs to ensure test isolation regardless of counter state.
        $agents = [
            $this->makeAgent('concurrent-a'),
            $this->makeAgent('concurrent-b'),
            $this->makeAgent('concurrent-c'),
        ];

        $executor = $this->makeBlockingExecutor();
        $pool = new AgentWorkerPool(maxConcurrent: 3, executor: $executor);

        $results = [];
        foreach ($pool->executeAll($agents, $this->request) as $result) {
            $results[$result->agentId] = $result;
        }

        $this->assertCount(3, $results);
        $this->assertArrayHasKey('concurrent-a', $results);
        $this->assertArrayHasKey('concurrent-b', $results);
        $this->assertArrayHasKey('concurrent-c', $results);
        $this->assertSame(AgentStatus::Completed, $results['concurrent-a']->status);
        $this->assertSame(AgentStatus::Completed, $results['concurrent-b']->status);
        $this->assertSame(AgentStatus::Completed, $results['concurrent-c']->status);
    }

    // -------------------------------------------------------------------------
    // testExecuteAllRespectsMaxConcurrent
    // -------------------------------------------------------------------------

    public function testExecuteAllRespectsMaxConcurrent(): void
    {
        $maxConcurrent = 2;
        $totalAgents = 4;

        // Track how many agents were started before the first result was yielded.
        // The pool dispatches up to maxConcurrent agents before waiting. We
        // intercept this by having the executor record each execute() call.
        $startedBeforeFirstYield = 0;
        $firstYieldDone = false;
        $startMs = null;

        $executor = $this->createMock(ExecutorInterface::class);
        $executor->method('execute')
            ->willReturnCallback(function (SubAgent $agent) use (&$startedBeforeFirstYield, &$firstYieldDone, &$startMs) {
                if (!$firstYieldDone) {
                    $startedBeforeFirstYield++;
                    if ($startMs === null) {
                        $startMs = hrtime(true);
                    }
                }
                // Simulate some work
                usleep(20_000); // 20ms per agent

                return new AgentResult(agentId: $agent->id, status: AgentStatus::Completed);
            });
        $executor->method('executeStream')
            ->willReturnCallback(function (SubAgent $agent) {
                yield new AgentResult(agentId: $agent->id, status: AgentStatus::Completed);
            });
        $executor->method('cancel')->willReturnCallback(function (string $agentId) {});
        $executor->method('cancelAll')->willReturnCallback(function () {});

        $agents = [];
        for ($i = 1; $i <= $totalAgents; $i++) {
            $agents[] = $this->makeAgent("concurrent-{$i}");
        }

        $pool = new AgentWorkerPool(maxConcurrent: $maxConcurrent, executor: $executor);

        $results = [];
        $firstResultSeen = false;
        foreach ($pool->executeAll($agents, $this->request) as $result) {
            if (!$firstResultSeen) {
                $firstResultSeen = true;
                $firstYieldDone = true;
            }
            $results[$result->agentId] = $result;
        }

        $this->assertCount($totalAgents, $results);

        // The pool must never have started more than maxConcurrent agents
        // before the first result was yielded. In the sync custom executor
        // path agents run sequentially (maxConcurrent is a batch size, not a
        // parallelism hint), so exactly 1 agent is started before the first
        // yield — the assertion still verifies the guard is not exceeded.
        $this->assertLessThanOrEqual(
            $maxConcurrent,
            $startedBeforeFirstYield,
            "Pool started {$startedBeforeFirstYield} agents before first yield, "
                . "exceeding maxConcurrent={$maxConcurrent}",
        );
    }

    // -------------------------------------------------------------------------
    // testExecuteAllCancelsOnFirstFailureWhenConfigured
    // -------------------------------------------------------------------------

    public function testExecuteAllCancelsOnFirstFailureWhenConfigured(): void
    {
        // With maxConcurrent=1, agents run one at a time. The order is:
        // success(50ms) -> fail(10ms) -> pending(80ms queued).
        // When fail is found and is a failure, pending is still in the queue
        // (has not been started yet), so cancellation prevents it from running.
        $agents = [
            $this->makeAgent('cancel-success'),
            $this->makeAgent('cancel-fail'),
            $this->makeAgent('cancel-pending'),
        ];

        $resultsById = [
            'cancel-success' => new AgentResult(
                agentId: 'cancel-success',
                status: AgentStatus::Completed,
            ),
            'cancel-fail' => new AgentResult(
                agentId: 'cancel-fail',
                status: AgentStatus::Failed,
                error: new \RuntimeException('Simulated failure'),
            ),
            'cancel-pending' => new AgentResult(
                agentId: 'cancel-pending',
                status: AgentStatus::Completed,
            ),
        ];

        $executor = $this->makeBlockingExecutor($resultsById);
        // maxConcurrent=1 ensures cancel-pending stays queued until fail is found.
        $pool = (new AgentWorkerPool(maxConcurrent: 1, executor: $executor))
            ->withStopOnFirstFailure(true);

        $collected = [];
        foreach ($pool->executeAll($agents, $this->request) as $result) {
            $collected[$result->agentId] = $result;
        }

        // The failed agent must be present
        $this->assertArrayHasKey('cancel-fail', $collected);
        $this->assertTrue($collected['cancel-fail']->isFailure());

        // The pending agent was cancelled before it could start (it was still
        // in the queue when fail was found), so it has no result file and never
        // appears in results.
        $this->assertArrayNotHasKey('cancel-pending', $collected);

        // The success agent ran (it completed before fail was detected).
        $this->assertArrayHasKey('cancel-success', $collected);
        $this->assertFalse($collected['cancel-success']->isFailure());

        // Two agents yielded: success and fail (pending was cancelled).
        $this->assertCount(2, $collected);
    }

    // -------------------------------------------------------------------------
    // testExecuteAllReturnsResultsAsTheyComplete
    // -------------------------------------------------------------------------

    public function testExecuteAllReturnsResultsAsTheyComplete(): void
    {
        // Use explicit IDs for test isolation from counter state.
        $agents = [
            $this->makeAgent('result-a'),
            $this->makeAgent('result-b'),
            $this->makeAgent('result-c'),
        ];

        $resultsById = [
            'result-a' => new AgentResult(agentId: 'result-a', status: AgentStatus::Completed),
            'result-b' => new AgentResult(agentId: 'result-b', status: AgentStatus::Completed),
            'result-c' => new AgentResult(agentId: 'result-c', status: AgentStatus::Completed),
        ];

        $executor = $this->makeBlockingExecutor($resultsById);
        $pool = new AgentWorkerPool(maxConcurrent: 5, executor: $executor);

        $yieldedIds = [];
        foreach ($pool->executeAll($agents, $this->request) as $result) {
            $yieldedIds[] = $result->agentId;
        }

        $this->assertCount(3, $yieldedIds);
        $this->assertContains('result-a', $yieldedIds);
        $this->assertContains('result-b', $yieldedIds);
        $this->assertContains('result-c', $yieldedIds);
    }

    // -------------------------------------------------------------------------
    // executeOne tests
    // -------------------------------------------------------------------------

    public function testExecuteOneReturnsAgentResult(): void
    {
        $agent = $this->makeAgent('single-agent');
        $result = new AgentResult(
            agentId: 'single-agent',
            status: AgentStatus::Completed,
            output: 'Single result',
        );

        $executor = $this->makeBlockingExecutor(['single-agent' => $result]);
        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);

        $actual = $pool->executeOne($agent, $this->request);

        $this->assertSame('single-agent', $actual->agentId);
        $this->assertSame(AgentStatus::Completed, $actual->status);
    }

    public function testExecuteOneWithFailure(): void
    {
        $agent = $this->makeAgent('failing-agent');
        $result = new AgentResult(
            agentId: 'failing-agent',
            status: AgentStatus::Failed,
            error: new \RuntimeException('Execution failed'),
        );

        $executor = $this->makeBlockingExecutor(['failing-agent' => $result]);
        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);

        $actual = $pool->executeOne($agent, $this->request);

        $this->assertSame('failing-agent', $actual->agentId);
        $this->assertTrue($actual->isFailure());
    }

    // -------------------------------------------------------------------------
    // getActiveCount / getQueueSize
    // -------------------------------------------------------------------------

    public function testGetActiveCountStartsAtZero(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 5);
        $this->assertSame(0, $pool->getActiveCount());
    }

    public function testGetQueueSizeStartsAtZero(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 5);
        $this->assertSame(0, $pool->getQueueSize());
    }

    // -------------------------------------------------------------------------
    // testTimeout
    // -------------------------------------------------------------------------

    public function testTimeout(): void
    {
        // Custom executor that returns a TimedOut result to simulate a agent
        // that ran longer than the configured timeout threshold.
        $timedOutExecutor = $this->createMock(ExecutorInterface::class);
        $timedOutExecutor->method('execute')
            ->willReturnCallback(function (SubAgent $agent) {
                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::TimedOut,
                    error: new \RuntimeException('Agent execution timed out'),
                );
            });
        $timedOutExecutor->method('executeStream')
            ->willReturnCallback(function (SubAgent $agent) {
                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::TimedOut,
                    error: new \RuntimeException('Agent execution timed out'),
                );
            });
        $timedOutExecutor->method('cancel')->willReturnCallback(function (string $agentId) {});
        $timedOutExecutor->method('cancelAll')->willReturnCallback(function () {});

        $agents = [
            $this->makeAgent('slow-agent'),
            $this->makeAgent('another-agent'),
        ];

        $pool = new AgentWorkerPool(maxConcurrent: 2, executor: $timedOutExecutor);

        $results = [];
        foreach ($pool->executeAll($agents, $this->request) as $result) {
            $results[$result->agentId] = $result;
        }

        $this->assertCount(2, $results);
        $this->assertSame(AgentStatus::TimedOut, $results['slow-agent']->status);
        $this->assertSame(AgentStatus::TimedOut, $results['another-agent']->status);
        $this->assertTrue($results['slow-agent']->isFailure());
        $this->assertTrue($results['another-agent']->isFailure());
    }

    // -------------------------------------------------------------------------
    // cancel() tests
    // -------------------------------------------------------------------------

    public function testCancelUnknownAgentIsNoOp(): void
    {
        $pool = new AgentWorkerPool(maxConcurrent: 5);
        // Should not throw
        $pool->cancel('nonexistent-id');
        $this->assertSame(0, $pool->getActiveCount());
        $this->assertSame(0, $pool->getQueueSize());
    }

    // -------------------------------------------------------------------------
    // cancelAll() tests
    // -------------------------------------------------------------------------

    public function testCancelAllClearsQueue(): void
    {
        $agents = [
            $this->makeAgent('cancel-1'),
            $this->makeAgent('cancel-2'),
        ];

        $executor = $this->makeBlockingExecutor();
        $pool = new AgentWorkerPool(maxConcurrent: 2, executor: $executor);

        // Cancel all BEFORE executeAll() is iterated.
        // The wasCancelledByUser flag should prevent any agents from running.
        $pool->cancelAll();

        $results = iterator_to_array($pool->executeAll($agents, $this->request));
        $this->assertEmpty($results);
    }

    // -------------------------------------------------------------------------
    // Max concurrent configuration
    // -------------------------------------------------------------------------

    public function testCustomMaxConcurrent(): void
    {
        // Verify that different maxConcurrent values are accepted
        $pool2 = new AgentWorkerPool(maxConcurrent: 2);
        $pool10 = new AgentWorkerPool(maxConcurrent: 10);

        // Queue size should reflect configured max, not current load
        $this->assertSame(0, $pool2->getQueueSize());
        $this->assertSame(0, $pool10->getQueueSize());
    }

    public function testDefaultMaxConcurrent(): void
    {
        $pool = new AgentWorkerPool();
        // Default maxConcurrent is 5 (matching Claude Code's default)
        $this->assertSame(0, $pool->getActiveCount());
    }

    // -------------------------------------------------------------------------
    // withStopOnFirstFailure tests
    // -------------------------------------------------------------------------

    public function testWithStopOnFirstFailureReturnsNewInstance(): void
    {
        $original = new AgentWorkerPool(maxConcurrent: 5);
        $modified = $original->withStopOnFirstFailure(true);

        $this->assertNotSame($original, $modified);
    }

    public function testWithStopOnFirstFailureFalse(): void
    {
        $executor = $this->makeBlockingExecutor();
        $pool = (new AgentWorkerPool(maxConcurrent: 3, executor: $executor))
            ->withStopOnFirstFailure(false);

        $agents = [
            $this->makeAgent('success-1'),
            $this->makeAgent('success-2'),
        ];
        $results = [];
        foreach ($pool->executeAll($agents, $this->request) as $result) {
            $results[$result->agentId] = $result;
        }

        // Both should complete since stopOnFirstFailure is false
        $this->assertCount(2, $results);
    }

    // -------------------------------------------------------------------------
    // Multiple sequential executeAll calls
    // -------------------------------------------------------------------------

    public function testMultipleExecuteAllCalls(): void
    {
        $executor = $this->makeBlockingExecutor();
        $pool = new AgentWorkerPool(maxConcurrent: 3, executor: $executor);

        $batch1 = [
            $this->makeAgent('batch1-a'),
            $this->makeAgent('batch1-b'),
        ];

        $batch2 = [
            $this->makeAgent('batch2-a'),
            $this->makeAgent('batch2-b'),
        ];

        $results1 = [];
        foreach ($pool->executeAll($batch1, $this->request) as $result) {
            $results1[$result->agentId] = $result;
        }

        $results2 = [];
        foreach ($pool->executeAll($batch2, $this->request) as $result) {
            $results2[$result->agentId] = $result;
        }

        $this->assertCount(2, $results1);
        $this->assertCount(2, $results2);
        $this->assertArrayHasKey('batch1-a', $results1);
        $this->assertArrayHasKey('batch2-a', $results2);
    }

    // -------------------------------------------------------------------------
    // R14a: waitForCompletion() sleeping-poll hardening (real fork, no mocks)
    // -------------------------------------------------------------------------

    /**
     * Drives the real default ProcessExecutor + real pcntl_fork() (no mocked
     * executor) to exercise waitForCompletion()'s WNOHANG poll loop end to
     * end. A hot CPU busy-spin cannot be asserted directly in a deterministic
     * CI-safe way (WNOHANG polling as fast as possible does not itself slow
     * down completion detection on an idle box), so this uses a bounded
     * multiple of a single agent's real duration as a deterministic proxy for
     * "the pool executed these concurrently and did not stall" — a
     * regression that reintroduces unbounded sequential execution, or a
     * pathological slowdown in the poll loop, would blow this bound.
     */
    public function testExecuteAllWithRealForkedExecutorCompletesWithinBoundedMultipleOfSingleAgentDuration(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork() is not available in this environment.');
        }

        // Baseline: a single agent run through the real ProcessExecutor
        // directly (executeOne() bypasses fork/pool bookkeeping entirely),
        // establishing the "one unit of real work" latency to compare against.
        $baselinePool = new AgentWorkerPool(maxConcurrent: 1);
        $baselineStart = hrtime(true);
        $baselineResult = $baselinePool->executeOne($this->makeAgent('bounded-baseline'), $this->request);
        $baselineDurationNs = hrtime(true) - $baselineStart;

        $this->assertSame(AgentStatus::Completed, $baselineResult->status);

        // Real run: 3 agents, maxConcurrent=3, default ProcessExecutor, real
        // pcntl_fork(). Exercises startAgent()'s fork path and
        // waitForCompletion()'s WNOHANG poll loop with no test doubles.
        $agents = [
            $this->makeAgent('bounded-a'),
            $this->makeAgent('bounded-b'),
            $this->makeAgent('bounded-c'),
        ];

        $realPool = new AgentWorkerPool(maxConcurrent: 3);

        // The elapsed-time assertion below is NOT a bound: it is evaluated
        // after the loop, so a pool that never settles never reaches it. The
        // deadline is what turns a hang into a failure; it is set far above the
        // assertion's own 15s floor so a merely slow box still fails on the
        // assertion, with its diagnostic, rather than on the alarm.
        $start = hrtime(true);
        $results = $this->underDeadline(120, function () use ($realPool, $agents): array {
            $collected = [];
            foreach ($realPool->executeAll($agents, $this->request) as $result) {
                $collected[$result->agentId] = $result;
            }

            return $collected;
        });
        $elapsedNs = hrtime(true) - $start;

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertSame(AgentStatus::Completed, $result->status);
        }

        // Very generous headroom (8x baseline, floor 15s): this repo's dev/CI
        // boxes run many concurrent proc_open-heavy test suites in parallel,
        // which inflates both measurements non-linearly (spawning 3 workers
        // contends for process/scheduler resources harder than spawning 1).
        // The bound is intentionally loose — its job is to catch a genuine
        // stall/hang regression (e.g. a reintroduced busy-spin starving the
        // scheduler), not to assert a tight speedup ratio.
        $maxAllowedNs = max($baselineDurationNs * 8, 15_000_000_000);
        $this->assertLessThanOrEqual(
            $maxAllowedNs,
            $elapsedNs,
            sprintf(
                'Concurrent execution of 3 agents took %.3fs, exceeding the bound of %.3fs '
                    . '(8x the single-agent baseline of %.3fs) — possible stall/regression '
                    . 'in the worker pool completion loop.',
                $elapsedNs / 1e9,
                $maxAllowedNs / 1e9,
                $baselineDurationNs / 1e9,
            ),
        );
    }

    // -------------------------------------------------------------------------
    // R14a: sequential-fallback warning when pcntl_fork() is unavailable
    // -------------------------------------------------------------------------

    /**
     * AgentWorkerPool is final, so the pcntl-unavailable branch is forced
     * deterministically via the forcePcntlUnavailableForTesting Reflection
     * seam rather than by requiring a real pcntl-less environment; error_log()
     * output is captured by redirecting the 'error_log' ini setting to a temp
     * file for the duration of the assertion.
     */
    public function testSequentialFallbackWarningLogsOnceWhenPcntlForkUnavailable(): void
    {
        $logFile = $this->tempRoot . 'fallback-warning.log';
        $previousErrorLog = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $pool = new AgentWorkerPool(maxConcurrent: 2);

            $forceProp = new \ReflectionProperty(AgentWorkerPool::class, 'forcePcntlUnavailableForTesting');
            $forceProp->setAccessible(true);
            $forceProp->setValue($pool, true);

            // Two agents both hit the (forced) pcntl-unavailable fallback
            // path — the warning must fire exactly once, not per-agent.
            $agents = [
                $this->makeAgent('fallback-a'),
                $this->makeAgent('fallback-b'),
            ];

            $results = [];
            foreach ($pool->executeAll($agents, $this->request) as $result) {
                $results[$result->agentId] = $result;
                $this->assertSame(AgentStatus::Completed, $result->status);
            }
            $this->assertCount(2, $results);

            $this->assertFileExists($logFile);
            $logContents = file_get_contents($logFile);
            $this->assertIsString($logContents);
            $this->assertStringContainsString('pcntl_fork', $logContents);
            $this->assertStringContainsString('sequential', $logContents);
            $this->assertSame(
                1,
                substr_count($logContents, 'AgentWorkerPool: pcntl_fork() is unavailable'),
                'Expected the sequential-fallback warning to be logged exactly once.',
            );
        } finally {
            ini_set('error_log', $previousErrorLog === false ? '' : $previousErrorLog);
            @unlink($logFile);
        }
    }

    // -------------------------------------------------------------------------
    // R14a.fix: storeResult() must not silently drop a result when
    // json_encode() cannot represent it (non-finite costUsd), which
    // previously hung the pool forever waiting on a file that was never
    // written.
    // -------------------------------------------------------------------------

    /**
     * json_encode() returns false — and previously skipped the write
     * entirely — for a costUsd of NAN/INF/-INF. Drives storeResult() and
     * extractResult() directly via Reflection (rather than the full
     * executeAll() loop) so a regression fails with a clear assertion
     * instead of hanging the test process.
     */
    public function testStoreResultRoundTripsNonFiniteCostUsdInsteadOfSilentlyDroppingTheWrite(): void
    {
        $pool = new AgentWorkerPool();

        $storeResult = new \ReflectionMethod(AgentWorkerPool::class, 'storeResult');
        $storeResult->setAccessible(true);
        $hasResult = new \ReflectionMethod(AgentWorkerPool::class, 'hasResult');
        $hasResult->setAccessible(true);
        $extractResult = new \ReflectionMethod(AgentWorkerPool::class, 'extractResult');
        $extractResult->setAccessible(true);

        foreach (['nan-cost' => NAN, 'inf-cost' => INF, 'neg-inf-cost' => -INF] as $agentId => $costUsd) {
            $result = new AgentResult(
                agentId: $agentId,
                status: AgentStatus::Completed,
                output: 'ok',
                costUsd: $costUsd,
            );

            $storeResult->invoke($pool, $agentId, $result);

            $this->assertTrue(
                $hasResult->invoke($pool, $agentId),
                "Expected a result file to be written for {$agentId} even though its costUsd "
                    . "is non-finite — a skipped write here means the agent is stuck in \$active "
                    . 'forever and executeAll() never terminates.',
            );

            $decoded = $extractResult->invoke($pool, $agentId);
            $this->assertNotNull(
                $decoded,
                "Expected extractResult() to recover a valid AgentResult for {$agentId} instead "
                    . 'of silently returning null.',
            );
            $this->assertSame(AgentStatus::Completed, $decoded->status);
            $this->assertTrue(
                is_finite($decoded->costUsd),
                "Expected the round-tripped costUsd for {$agentId} to be sanitized to a finite value.",
            );
        }
    }

    /**
     * End-to-end reproduction of the hang: a mock (customExecutor) result
     * with a NAN costUsd fed through the real executeAll() loop. Guarded by
     * an alarm-based safety timeout (via pcntl_async_signals) so that if the
     * fix regresses, this test fails with a clear timeout exception instead
     * of hanging the whole suite forever, mirroring how the regression was
     * originally reproduced with `timeout 6 php ...`.
     */
    public function testExecuteAllTerminatesWhenResultHasNonFiniteCostUsd(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl_async_signals()/pcntl_alarm() are not available in this environment.');
        }

        $agents = [$this->makeAgent('nan-cost-agent')];
        $executor = $this->makeBlockingExecutor([
            'nan-cost-agent' => new AgentResult(
                agentId: 'nan-cost-agent',
                status: AgentStatus::Completed,
                output: 'ok',
                costUsd: NAN,
            ),
        ]);
        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $executor);

        $previousAsync = pcntl_async_signals();
        pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        pcntl_signal(SIGALRM, function () {
            throw new \RuntimeException(
                'executeAll() did not terminate within the safety timeout — likely the '
                    . 'NAN-costUsd json_encode-failure hang regression.',
            );
        });
        pcntl_alarm(5);

        try {
            $results = [];
            foreach ($pool->executeAll($agents, $this->request) as $result) {
                $results[$result->agentId] = $result;
            }
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
        }

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('nan-cost-agent', $results);
    }

    // -------------------------------------------------------------------------
    // W3.F1: per-instance IPC directory — result files must not be shared
    // between pool instances (or between processes) that use the same agent id.
    // -------------------------------------------------------------------------

    /**
     * Against the old fixed sys_get_temp_dir().'/sc_pool_<id>.result' path,
     * both pools resolved the SAME file, so pool B saw (and could clobber)
     * pool A's result — the collision that produced the intermittent
     * FanOutResearchTest failure when sibling worktrees ran their suites at
     * the same time.
     */
    public function testTwoPoolsUsingTheSameAgentIdDoNotObserveEachOthersResults(): void
    {
        $agentId = 'colliding-agent';

        $poolA = new AgentWorkerPool();
        $poolB = new AgentWorkerPool();

        $storeResult = new \ReflectionMethod(AgentWorkerPool::class, 'storeResult');
        $storeResult->setAccessible(true);
        $hasResult = new \ReflectionMethod(AgentWorkerPool::class, 'hasResult');
        $hasResult->setAccessible(true);
        $extractResult = new \ReflectionMethod(AgentWorkerPool::class, 'extractResult');
        $extractResult->setAccessible(true);

        $storeResult->invoke($poolA, $agentId, new AgentResult(
            agentId: $agentId,
            status: AgentStatus::Completed,
            output: 'result belonging to pool A',
        ));

        $this->assertFalse(
            $hasResult->invoke($poolB, $agentId),
            'A second pool must not see the first pool\'s result file for the same agent id.',
        );
        $this->assertNull(
            $extractResult->invoke($poolB, $agentId),
            'A second pool must not be able to consume (and delete) the first pool\'s result.',
        );

        // Pool A's own result survived pool B's probe intact.
        $this->assertTrue($hasResult->invoke($poolA, $agentId));
        $recovered = $extractResult->invoke($poolA, $agentId);
        $this->assertNotNull($recovered);
        $this->assertSame('result belonging to pool A', $recovered->output);

        // Pool B storing under the same id must likewise not disturb pool A.
        $storeResult->invoke($poolB, $agentId, new AgentResult(
            agentId: $agentId,
            status: AgentStatus::Failed,
            output: 'result belonging to pool B',
        ));
        $this->assertFalse($hasResult->invoke($poolA, $agentId));
        $poolBResult = $extractResult->invoke($poolB, $agentId);
        $this->assertNotNull($poolBResult);
        $this->assertSame('result belonging to pool B', $poolBResult->output);
    }

    /**
     * The IPC directory must be private (0700) and unpredictably named — the
     * old path was a fixed, world-writable-directory filename, i.e. a symlink
     * pre-creation target.
     */
    public function testResultDirectoryIsPrivateToTheProcessAndCleanedUpOnDestruct(): void
    {
        $pool = new AgentWorkerPool();

        $dirProp = new \ReflectionProperty(AgentWorkerPool::class, 'resultDir');
        $dirProp->setAccessible(true);
        $dir = $dirProp->getValue($pool);
        $this->assertIsString($dir);

        $storeResult = new \ReflectionMethod(AgentWorkerPool::class, 'storeResult');
        $storeResult->setAccessible(true);
        $storeResult->invoke($pool, 'perm-check', new AgentResult(
            agentId: 'perm-check',
            status: AgentStatus::Completed,
            output: 'ok',
        ));

        $this->assertDirectoryExists($dir);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($dir)), -4));
        $this->assertStringContainsString((string) getmypid(), basename($dir));

        // Destructing the owning pool removes the directory and any result
        // files still inside it, so the pool does not leak temp state.
        unset($pool);
        $this->assertDirectoryDoesNotExist($dir);
    }

    /**
     * withStopOnFirstFailure() clones the pool; the clone must get its own IPC
     * directory, otherwise the two instances share result files again and the
     * first one destructed deletes the other's.
     */
    public function testCloneGetsItsOwnResultDirectory(): void
    {
        $original = new AgentWorkerPool(maxConcurrent: 2);
        $clone = $original->withStopOnFirstFailure(true);

        $dirProp = new \ReflectionProperty(AgentWorkerPool::class, 'resultDir');
        $dirProp->setAccessible(true);

        $this->assertNotSame($dirProp->getValue($original), $dirProp->getValue($clone));
    }

    // -------------------------------------------------------------------------
    // #54: executeAll() must not be able to wait forever.
    //
    // executeAll()'s outer loop runs until $active drains, so every entry in
    // $active needs exactly one mechanism guaranteed to remove it. Two things
    // broke that: a forked agent occupied TWO $active slots (its own id plus a
    // '__pid:<pid>:<id>' key) removed by two different racing mechanisms, and a
    // child that died without writing a result file left its plain-id slot with
    // nothing for hasResult() to ever match.
    // -------------------------------------------------------------------------

    /**
     * A forked agent must occupy exactly ONE slot in $active.
     *
     * Asserted mid-flight rather than after the run because the broken shape
     * hangs at the end rather than failing: at the moment the pool yields its
     * k-th result, exactly k agents have been settled, so with 3 dispatched
     * agents getActiveCount() is 2 at the first yield. The double-keyed version
     * reported 5 there (6 entries minus the one just settled), which is the same
     * bookkeeping error that later stranded the un-removed keys forever.
     *
     * The doubled count also silently halved the effective concurrency limit,
     * since the fill loop budgets on count($active).
     */
    public function testForkedAgentOccupiesExactlyOneActiveSlot(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork() is not available in this environment.');
        }

        $agents = [
            $this->makeAgent('one-slot-a'),
            $this->makeAgent('one-slot-b'),
            $this->makeAgent('one-slot-c'),
        ];

        // maxConcurrent well above the agent count so all three are dispatched
        // in the first fill pass under either the correct or the doubled count.
        $pool = new AgentWorkerPool(maxConcurrent: 10);

        // Bounded: the double-keyed shape this asserts against does not just
        // report a wrong count, it hangs at the end of the run.
        $results = $this->underDeadline(60, function () use ($pool, $agents): array {
            $collected = [];
            foreach ($pool->executeAll($agents, $this->request) as $result) {
                $collected[] = $result;

                $this->assertSame(
                    count($agents) - count($collected),
                    $pool->getActiveCount(),
                    'After yielding ' . count($collected) . ' result(s), exactly that many agents '
                        . 'must have left $active — a forked agent that occupies two $active '
                        . 'slots leaves one behind with nothing able to remove it.',
                );
            }

            return $collected;
        });

        $this->assertCount(3, $results);
        $this->assertSame(0, $pool->getActiveCount());
    }

    /**
     * A forked worker that dies without writing its result file must still
     * settle to a terminal Failed result.
     *
     * This is the hang proper: pcntl reaping removed the pid bookkeeping, but
     * the agent's $active slot was only ever removed by a hasResult() poll, and
     * for a crashed/killed/OOM-ed child that file never appears — so
     * executeAll() polled forever. Reproduced end to end (SIGKILL a real forked
     * child before it can write) it hung 20/20 runs.
     *
     * Driven through waitForCompletion() directly with a real, already-dead
     * child rather than through executeAll(), so a regression fails on an
     * assertion instead of hanging the whole suite.
     */
    public function testWorkerThatDiesWithoutWritingAResultSettlesToAFailure(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl_fork()/pcntl_waitpid() are not available in this environment.');
        }

        $agent = $this->makeAgent('ghost-worker');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        $pid = $this->forkChildThatDiesSilently();
        $this->seedInFlight($pool, $agent, $pid);

        $completedId = $this->pollUntilSettled($pool);

        $this->assertSame(
            $agent->id,
            $completedId,
            'waitForCompletion() never settled a worker that had already exited.',
        );
        $this->assertSame([], $this->readPrivate($pool, 'active'), 'The dead agent was left in $active.');
        $this->assertSame([], $this->readPrivate($pool, 'activePids'), 'The reaped PID was left tracked.');

        $result = $this->extractResultOf($pool, $agent->id);

        $this->assertInstanceOf(
            AgentResult::class,
            $result,
            'A worker that died without writing a result must still produce one — otherwise '
                . 'executeAll() settles the agent but yields nothing for it.',
        );
        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertTrue($result->isFailure());
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('ghost-worker', $result->error->getMessage());

        // Every other AgentResult in the library carries timestamps, and
        // AgentManager::drain() mirrors them onto the SubAgent — a terminal
        // result with both null makes the status strip and the dashboard report
        // a flat 0s for a sub-agent that plainly ran.
        $this->assertNotNull(
            $result->completedAt,
            'A synthesized failure must still be stamped with when it was settled.',
        );
    }

    /**
     * The pool must reap only its OWN forked children.
     *
     * The waiter used pcntl_wait(), which reaps ANY child of the process. The
     * pool runs inside a process that proc_open()s children of its own (the
     * executor's workers, tool calls, MCP servers), so a stray pcntl_wait()
     * consumed their exit status and left proc_close() reporting -1 for a
     * process that in fact exited 0.
     */
    public function testPoolDoesNotReapForeignChildProcesses(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('proc_open')) {
            $this->markTestSkipped('pcntl_fork()/proc_open() are not available in this environment.');
        }

        // A foreign child that exits 0 well before the pool run finishes, so the
        // pool's poll loop is guaranteed to be running when it becomes reapable.
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $foreign = proc_open([PHP_BINARY, '-r', 'usleep(150000);'], $descriptors, $pipes);
        $this->assertIsResource($foreign);

        // try/finally so a throwing pool run (ProcessExecutor::checkBackpressure()
        // throws outright on a memory-loaded box) still closes the foreign
        // process and its pipes instead of leaking both into the rest of the run.
        try {
            $pool = new AgentWorkerPool(maxConcurrent: 2);
            // Bounded: a pool that stops reaping its own children hangs here.
            $results = $this->underDeadline(60, fn (): array => iterator_to_array($pool->executeAll(
                [$this->makeAgent('foreign-a'), $this->makeAgent('foreign-b')],
                $this->request,
            )));
            $this->assertCount(2, $results);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    stream_get_contents($pipe);
                    fclose($pipe);
                }
            }
            $foreignExit = proc_close($foreign);
        }

        $this->assertSame(
            0,
            $foreignExit,
            'proc_close() reported an unknown exit status, meaning the pool reaped a child '
                . 'process it did not fork.',
        );
    }

    /**
     * cancel() must actually stop a forked worker, and the cancelled worker must
     * still settle to a terminal result.
     *
     * On the default (fork) path $this->executor stays null — the executor lives
     * inside the child — so executor->cancel() was a silent no-op and cancel()
     * did nothing at all for the pool's own workers. Signalling the child is the
     * parent's only channel; and because a signalled child dies without writing
     * a result, this is also the path that most needs waitForCompletion() to
     * synthesize one rather than wait for a file that will never arrive.
     */
    public function testCancelTerminatesTheForkedWorkerAndStillSettlesAResult(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork()/posix_kill() are not available in this environment.');
        }

        $agent = $this->makeAgent('cancel-me');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        // A worker that would outlive the test unless it is genuinely signalled.
        $pid = $this->forkSleepingChild();

        $this->seedInFlight($pool, $agent, $pid);

        $pool->cancel($agent->id);

        // Bounded witness: 400 * 5ms of polling is ~2s, far more than a SIGTERM
        // needs. A worker that never got the signal sleeps for 120s, so
        // exhausting the budget is itself the failure.
        $completedId = $this->pollUntilSettled($pool);

        if ($completedId === null) {
            // Do not leave a 120s sleeper behind when the assertion below fails.
            @posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $throwaway);
        }

        $this->assertSame(
            $agent->id,
            $completedId,
            'cancel() did not stop the forked worker — it was never signalled.',
        );

        $result = $this->extractResultOf($pool, $agent->id);

        $this->assertInstanceOf(AgentResult::class, $result);
        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString(
            'killed by signal ' . SIGTERM,
            (string) $result->error?->getMessage(),
            'The synthesized result must name the signal that stopped the worker.',
        );
    }

    /**
     * cancelAll() must not orphan live forked workers — nor leave them as
     * zombies once it has.
     *
     * It drops $active outright, which ends executeAll()'s loop — so nothing
     * polls afterwards and nothing else will ever stop the children. Clearing
     * the bookkeeping without signalling left them running against a pool no
     * one is reading; signalling without waiting leaves one zombie per worker
     * for the life of a TUI process that runs for hours. EngineBackend built
     * $unreapedChildren for exactly this, and this pool now follows it.
     */
    public function testCancelAllTerminatesAndReapsLiveForkedWorkers(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork()/posix_kill() are not available in this environment.');
        }

        $agent = $this->makeAgent('cancel-all-me');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        $pid = $this->forkSleepingChild();
        $this->seedInFlight($pool, $agent, $pid);

        $pool->cancelAll();

        $this->assertSame([], $this->readPrivate($pool, 'activePids'));
        $this->assertSame([], $this->readPrivate($pool, 'active'));

        $status = 0;

        if ($this->readPrivate($pool, 'unreapedChildren') !== []) {
            // A box loaded enough that the child outlived cancelAll()'s bounded
            // 100ms reap window. It is still TRACKED for the next run's sweep,
            // which is the designed fallback — collect it here so the failure
            // message below can distinguish "slow to die" from "never
            // signalled". An unsignalled worker sleeps for 120s.
            $reaped = 0;
            for ($poll = 0; $poll < 400 && $reaped === 0; $poll++) {
                $reaped = pcntl_waitpid($pid, $status, WNOHANG);
                if ($reaped === 0) {
                    usleep(5_000);
                }
            }

            if ($reaped === 0) {
                @posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
                $this->fail('cancelAll() left the forked worker running — it was never signalled.');
            }

            $this->assertTrue(pcntl_wifsignaled($status));
            $this->assertSame(SIGTERM, pcntl_wtermsig($status));

            return;
        }

        // The normal path: cancelAll() signalled AND collected the child, so it
        // is no longer waitable by anyone — the proof that no zombie was left.
        $this->assertSame(
            -1,
            pcntl_waitpid($pid, $status, WNOHANG),
            'cancelAll() reported nothing outstanding, yet the worker is still waitable — it '
                . 'was signalled but never reaped, which is a zombie for the life of the process.',
        );
    }

    /**
     * cancelAll() must collect the workers it signals, not just track them.
     *
     * The companion test above tolerates a straggler that outlived the 100ms
     * reap budget, because a live sleeper's death depends on the scheduler and
     * a flaky test is worse than a narrow one. This one removes the scheduler
     * from the question entirely: the worker is already exiting before
     * cancelAll() is called, so anything left unreaped afterwards means the
     * reap does not happen at all — the zombie-per-worker leak
     * EngineBackend::$unreapedChildren was built to stop.
     */
    public function testCancelAllReapsTheWorkersItSignals(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork()/posix_kill() are not available in this environment.');
        }

        $agent = $this->makeAgent('cancel-all-already-dying');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        $this->seedInFlight($pool, $agent, $this->forkChildThatDiesSilently());
        $pid = $this->readPrivate($pool, 'activePids')[$agent->id];

        $pool->cancelAll();

        $this->assertSame(
            [],
            $this->readPrivate($pool, 'unreapedChildren'),
            'cancelAll() left a worker it had already signalled uncollected.',
        );

        $status = 0;
        $this->assertSame(
            -1,
            pcntl_waitpid($pid, $status, WNOHANG),
            'The worker is still waitable after cancelAll() — it was signalled but never '
                . 'reaped, i.e. a zombie for the life of the process.',
        );
    }

    /**
     * executeAll() must reset the PID bookkeeping alongside $active/$queue —
     * and must not simply drop live children on the floor while doing it.
     *
     * A caller that abandons the generator mid-iteration (WorkflowEngine does
     * exactly this on stopOnFirstFailure, and any `break` out of the foreach
     * does too) leaves entries in $activePids. Carried into the next run they
     * shadow a same-named agent: waitForCompletion() would skip it in the
     * synchronous sweep — because it looks forked — while waitpid() on the dead
     * PID never matches, so the agent could never settle and the run would spin
     * forever. Wiping the map alone fixes that at the cost of a worker that is
     * now both unstoppable and unreapable, so the reset signals and collects.
     */
    public function testExecuteAllReleasesForkedWorkersLeftByAnAbandonedRun(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork()/posix_kill() are not available in this environment.');
        }

        $pool = new AgentWorkerPool(maxConcurrent: 2, executor: $this->makeBlockingExecutor());

        // A real, live pid: signalling an arbitrary made-up number (a previous
        // draft used 999999, well inside this box's pid_max) risks SIGTERMing
        // an unrelated process.
        $pid = $this->forkSleepingChild();

        $activePidsProp = new \ReflectionProperty(AgentWorkerPool::class, 'activePids');
        $activePidsProp->setAccessible(true);
        $activePidsProp->setValue($pool, ['left-over-from-an-abandoned-run' => $pid]);

        $results = iterator_to_array($pool->executeAll([$this->makeAgent('fresh-run')], $this->request));

        $this->assertCount(1, $results);
        $this->assertSame(
            [],
            $activePidsProp->getValue($pool),
            'executeAll() carried forked-PID bookkeeping over from a previous run.',
        );

        $status = 0;
        if ($this->readPrivate($pool, 'unreapedChildren') !== []) {
            // Loaded box — same fallback as the cancelAll() test.
            @posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            $this->fail(
                'executeAll() did not collect the abandoned run\'s worker within its reap '
                    . 'window; it is still tracked, but check whether the SIGTERM landed at all.',
            );
        }

        $this->assertSame(
            -1,
            pcntl_waitpid($pid, $status, WNOHANG),
            'The abandoned run\'s worker was left alive (or unreaped) by the next executeAll().',
        );
    }

    /**
     * A numeric-string agent id must survive the forked reap path.
     *
     * PHP coerces numeric-string array keys to int, so an agent whose id is
     * '42' is stored in $activePids under int(42) and comes back out of the
     * reap loop's own foreach as an int — while hasResult()/storeResult() are
     * typed string and the method returns ?string, under
     * declare(strict_types=1). The first reap of such an agent therefore
     * fataled with a TypeError. Agent ids are caller-supplied: executeAll()
     * takes SubAgent[] and is public API, and the ids the repo happens to
     * generate today all being prefixed is a coincidence, not a constraint.
     */
    public function testForkedReapHandlesANumericStringAgentId(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl_fork()/pcntl_waitpid() are not available in this environment.');
        }

        $agent = $this->makeAgent('42');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        $pid = $this->forkChildThatDiesSilently();
        $this->seedInFlight($pool, $agent, $pid);

        $completedId = $this->pollUntilSettled($pool);

        $this->assertIsString(
            $completedId,
            'waitForCompletion() must return the agent id as a string, whatever PHP did to the '
                . 'array key it was stored under.',
        );
        $this->assertSame('42', $completedId);

        $result = $this->extractResultOf($pool, '42');
        $this->assertInstanceOf(AgentResult::class, $result);
        $this->assertSame(AgentStatus::Failed, $result->status);
    }

    /**
     * A worker reaped by somebody else must still settle.
     *
     * pcntl_waitpid() answers -1 when the child is gone but unwaitable: a
     * SIGCHLD disposition of SIG_IGN has the kernel auto-reap every child
     * (candy-pty's SignalForwarder can install exactly that handler), and so
     * does any blanket wait() elsewhere in the process. Folding -1 in with 0
     * ("nothing yet") strands the agent in $active with no mechanism left that
     * can remove it — the same permanent-hang shape the whole change-set is
     * about, reached without any result file being involved.
     *
     * Driven with a child this test reaps itself, which puts waitpid() in
     * exactly the -1 state deterministically and without touching the process's
     * global SIGCHLD disposition.
     */
    public function testForkedReapSettlesAWorkerSomeOtherWaiterAlreadyReaped(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl_fork()/pcntl_waitpid() are not available in this environment.');
        }

        $agent = $this->makeAgent('already-reaped');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        $pid = $this->forkChildThatDiesSilently();
        $status = 0;
        $this->assertSame($pid, pcntl_waitpid($pid, $status), 'The test could not reap its own child.');

        $this->seedInFlight($pool, $agent, $pid);

        $completedId = $this->pollUntilSettled($pool, maxPolls: 4);

        $this->assertSame(
            $agent->id,
            $completedId,
            'waitForCompletion() never settled a worker that was already gone — with the pool '
                . 'unable to reap it, nothing else ever could.',
        );
        $this->assertSame([], $this->readPrivate($pool, 'active'));
        $this->assertSame([], $this->readPrivate($pool, 'activePids'));

        $result = $this->extractResultOf($pool, $agent->id);
        $this->assertInstanceOf(AgentResult::class, $result);
        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString(
            'unknowable',
            (string) $result->error?->getMessage(),
            'The result must say the exit status was collected elsewhere rather than inventing one.',
        );
    }

    /**
     * A truncated result file must not be mistaken for a result.
     *
     * hasResult() is file_exists(). A child SIGKILLed between
     * file_put_contents()'s open(O_TRUNC) and its write() leaves a 0-byte file
     * that satisfies it, so the reap declined to synthesize, extractResult()
     * returned null, and executeAll() yielded NOTHING for an agent it had
     * dispatched — breaking the same one-result-per-agent guarantee the
     * synthesis exists to keep.
     */
    public function testForkedReapSynthesizesWhenTheResultFileIsTruncated(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl_fork()/pcntl_waitpid() are not available in this environment.');
        }

        $agent = $this->makeAgent('torn-result');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        // Write a real result first (which creates the pool's private IPC
        // directory), then truncate it to the 0 bytes a killed writer leaves.
        $storeResult = new \ReflectionMethod(AgentWorkerPool::class, 'storeResult');
        $storeResult->setAccessible(true);
        $storeResult->invoke($pool, $agent->id, new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Completed,
            output: 'never finished being written',
        ));

        $resultFile = new \ReflectionMethod(AgentWorkerPool::class, 'resultFile');
        $resultFile->setAccessible(true);
        $file = $resultFile->invoke($pool, $agent->id);
        file_put_contents($file, '');
        $this->assertSame(0, filesize($file));

        $pid = $this->forkChildThatDiesSilently();
        $this->seedInFlight($pool, $agent, $pid);

        $completedId = $this->pollUntilSettled($pool);
        $this->assertSame($agent->id, $completedId);

        $result = $this->extractResultOf($pool, $agent->id);
        $this->assertInstanceOf(
            AgentResult::class,
            $result,
            'A 0-byte result file left the agent settled with nothing to yield for it.',
        );
        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString('before writing a result', (string) $result->error?->getMessage());
    }

    /**
     * A still-running forked agent must NOT be settled by the synchronous
     * sweep just because its result file has appeared.
     *
     * The child writes its result and only then exits, so there is a window in
     * which the file exists and the pid is still unreaped. Without
     * waitForCompletion()'s `isset($this->activePids[$agentId])` guard the sync
     * sweep settles the agent there — and the later reap, finding the file
     * already consumed by extractResult(), synthesizes a SECOND, failed result
     * for an agent that succeeded. With maxConcurrent: 1 and 4 agents that
     * turns 4 results into 7, and under stopOnFirstFailure a fabricated failure
     * cancels the rest of the stage.
     */
    public function testAForkedAgentIsNotSettledTwiceWhenItsResultLandsBeforeItsExit(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork()/posix_kill() are not available in this environment.');
        }

        $agent = $this->makeAgent('writes-then-lingers');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        $storeResult = new \ReflectionMethod(AgentWorkerPool::class, 'storeResult');
        $storeResult->setAccessible(true);
        $storeResult->invoke($pool, $agent->id, new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Completed,
            output: 'the real result, written just before the child exits',
        ));

        // Still alive: the result is on disk but the pid has not been reaped.
        $pid = $this->forkSleepingChild();
        $this->seedInFlight($pool, $agent, $pid);

        $waitForCompletion = new \ReflectionMethod(AgentWorkerPool::class, 'waitForCompletion');
        $waitForCompletion->setAccessible(true);

        try {
            $this->assertNull(
                $waitForCompletion->invoke($pool),
                'A forked agent was settled by its result file while its child was still '
                    . 'running — the reap will then settle it a second time.',
            );
            $this->assertArrayHasKey($agent->id, $this->readPrivate($pool, 'active'));
            $this->assertArrayHasKey($agent->id, $this->readPrivate($pool, 'activePids'));
        } finally {
            @posix_kill($pid, SIGKILL);
        }

        // Once the child is gone the SAME agent settles exactly once, carrying
        // the result the child actually wrote — deferred, not dropped.
        $this->assertSame($agent->id, $this->pollUntilSettled($pool));

        $result = $this->extractResultOf($pool, $agent->id);
        $this->assertInstanceOf(AgentResult::class, $result);
        $this->assertSame(AgentStatus::Completed, $result->status);
        $this->assertNull($this->pollUntilSettled($pool, maxPolls: 2), 'The agent settled twice.');
    }

    /**
     * The production write that makes every one of the tests above possible:
     * startAgent() recording the forked child's pid in $activePids.
     *
     * Every other fork-path test here seeds that map by Reflection, so deleting
     * the line they all depend on leaves the suite green while the original
     * hang returns in full. This one drives a REAL executeAll(), kills the
     * pool's own child mid-flight, and requires a terminal result anyway.
     *
     * Two things make it deterministic rather than a race:
     *  - the worker binary is replaced (via PATH) with a stub that answers the
     *    handshake and then blocks on stdin, so the child is guaranteed to be
     *    alive, and result-less, when the killer fires. It exits by itself the
     *    moment its parent dies and the pipe closes, so nothing is left behind.
     *  - the killer is a SIGALRM handler that reads $activePids out of the pool
     *    while executeAll() is blocked in its own poll loop, so it kills the
     *    real forked worker rather than guessing at pids. If $activePids is
     *    never written, nothing is killed, the stub run ends in the executor's
     *    own heartbeat failure instead, and the assertions below fail.
     */
    public function testARealRunSettlesAWorkerKilledBeforeItWroteAnything(): void
    {
        if (
            !function_exists('pcntl_fork')
            || !function_exists('posix_kill')
            || !function_exists('pcntl_async_signals')
        ) {
            $this->markTestSkipped('pcntl/posix signal handling is not available in this environment.');
        }

        // Answers ProcessExecutor's ready handshake, then holds the pipes open
        // without ever producing a result. `cat` ends on EOF, i.e. as soon as
        // the forked worker that owns the other end of the pipe dies, so
        // nothing outlives the test. Its stdout must stay attached to the pipe
        // (redirecting it away closes the write end, which the executor reads
        // as EOF and settles on within milliseconds); echoing the handshake
        // back is harmless, since the executor ignores message types it does
        // not recognise.
        // /bin/cat by absolute path: the stub dir goes on the FRONT of PATH so
        // the real php stays reachable for everything else, but a stub that
        // resolved `cat` through PATH would still be at the mercy of it.
        if (!is_executable('/bin/cat')) {
            $this->markTestSkipped('/bin/cat is required to stub a worker that blocks without answering.');
        }

        // Created only AFTER the skip check: tearDown() @unlink()s files but
        // never recurses, so a directory made before a skip defeats its
        // @rmdir($this->tempRoot) and leaks /tmp/sc_pool_test_*/bin/ per run.
        $stubBin = $this->tempRoot . 'bin';
        mkdir($stubBin, 0700, true);
        file_put_contents($stubBin . '/php', "#!/bin/sh\nprintf '{\"type\":\"ready\"}\\n'\nexec /bin/cat\n");
        chmod($stubBin . '/php', 0700);

        $agent = $this->makeAgent('killed-mid-flight');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        $activePidsProp = new \ReflectionProperty(AgentWorkerPool::class, 'activePids');
        $activePidsProp->setAccessible(true);

        $killed = [];
        $rearms = 0;
        $previousPath = getenv('PATH');
        $previousAsync = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);

        // One alarm serves as both killer and deadline: the first tick kills
        // the worker, a later tick means executeAll() never settled afterwards
        // — the hang this whole change-set is about.
        //
        // MAX_REARMS bounds the "nothing to kill yet" retry. Without it a
        // regression that both stopped recording pids AND hung the run would
        // re-arm at 1s forever, wedging the whole suite instead of failing —
        // exactly what underDeadline()'s docblock forbids. This test carries
        // its own alarm rather than nesting underDeadline() because the alarm
        // has to fire once as a killer before it becomes a deadline.
        $maxRearms = 5;
        pcntl_signal(SIGALRM, static function () use ($pool, $activePidsProp, &$killed, &$rearms, $maxRearms): void {
            if ($killed === []) {
                foreach ($activePidsProp->getValue($pool) as $workerPid) {
                    // SIGKILL, not SIGTERM: nothing about this worker may get a
                    // chance to write a result.
                    @posix_kill((int) $workerPid, SIGKILL);
                    $killed[] = (int) $workerPid;
                }

                if ($killed === [] && ++$rearms > $maxRearms) {
                    // Reports $rearms, not $maxRearms: the throw lands on the
                    // tick AFTER the budget is used up, so naming the budget
                    // understated the wait by one second. Self-reporting the
                    // real count keeps the message honest if the bound moves.
                    throw new \RuntimeException(sprintf(
                        'No forked worker pid appeared in $activePids after %d one-second retries, '
                        . 'so there was never anything to kill. Failing here rather than re-arming '
                        . 'forever: an unbounded retry turns this into a suite-wide hang.',
                        $rearms,
                    ));
                }

                // Re-arm as the deadline (or retry, if the fork had not landed).
                pcntl_alarm($killed === [] ? 1 : 30);

                return;
            }

            throw new \RuntimeException(
                'executeAll() never settled the agent whose worker was killed — the pool is hung.',
            );
        });

        try {
            putenv('PATH=' . $stubBin . ($previousPath === false ? '' : ':' . $previousPath));
            pcntl_alarm(1);

            $results = iterator_to_array($pool->executeAll([$agent], $this->request));
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsync);
            putenv($previousPath === false ? 'PATH' : 'PATH=' . $previousPath);
            @unlink($stubBin . '/php');
            @rmdir($stubBin);
        }

        $this->assertNotSame([], $killed, 'No forked worker pid was ever recorded, so none was killed.');

        $this->assertCount(
            1,
            $results,
            'executeAll() must yield one result per agent it dispatched, including one whose '
                . 'worker died without writing anything.',
        );
        $this->assertSame(AgentStatus::Failed, $results[0]->status);
        $this->assertStringContainsString(
            'killed by signal ' . SIGKILL,
            (string) $results[0]->error?->getMessage(),
            'The synthesized result must come from the dead child\'s wait status, not from the '
                . 'executor timing out on its own.',
        );
        $this->assertSame(0, $pool->getActiveCount());

        // The startAgent() half of the timing fix, pinned on the only path that
        // can pin it: every other fork test seeds $activePids by Reflection and
        // so never runs the dispatch-time stamp at all. AgentManager::drain()
        // mirrors this pair onto the SubAgent, and without it durationMs() and
        // SubAgent::elapsedSeconds() report a flat 0s in the status strip and
        // the dashboard for a sub-agent that plainly ran.
        $this->assertNotNull(
            $agent->startedAt,
            'startAgent() must stamp the SubAgent at dispatch — nothing else on the fork path '
                . 'does, so a worker that dies has no start time to report.',
        );
        $this->assertNotNull(
            $results[0]->startedAt,
            'The synthesized failure must carry the dispatch time, not just completedAt.',
        );
        $this->assertSame(
            $agent->startedAt->format('U.u'),
            $results[0]->startedAt->format('U.u'),
            'workerDiedResult() must take startedAt from the SubAgent the reap looked up, so the '
                . 'reported duration is the real one.',
        );
    }

    /**
     * A clone must not inherit — and so must never kill or reap — the
     * original's live children.
     *
     * withStopOnFirstFailure() is public fluent API, so nothing stops a caller
     * doing `$gen = $pool->executeAll(...); $gen->current();` (children forked,
     * $activePids populated) and only THEN deriving a configured clone. With
     * the two pid maps shallow-copied, the clone's cancelAll()/__destruct()
     * SIGTERMs and reaps children it never forked; the original's
     * waitForCompletion() then gets -1 for each and settles every agent
     * Failed-status-unknowable, discarding results that were about to land.
     */
    public function testCloneDoesNotInheritTheOriginalsLiveChildren(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl_fork()/pcntl_waitpid() are not available in this environment.');
        }

        $agent = $this->makeAgent('live-agent');
        $original = new AgentWorkerPool(maxConcurrent: 1);

        $pid = $this->forkSleepingChild();
        $this->seedInFlight($original, $agent, $pid);

        $unreapedProp = new \ReflectionProperty(AgentWorkerPool::class, 'unreapedChildren');
        $unreapedProp->setAccessible(true);
        $unreapedProp->setValue($original, [$pid => true]);

        $clone = $original->withStopOnFirstFailure(true);
        $status = 0;

        try {
            $this->assertSame(
                [],
                $this->readPrivate($clone, 'activePids'),
                'The clone inherited the original\'s forked children — it will signal and reap '
                    . 'processes it never started.',
            );
            $this->assertSame(
                [],
                $this->readPrivate($clone, 'unreapedChildren'),
                'The clone inherited the original\'s deferred-reap list — two sweeps racing for '
                    . 'the same pid is exactly the stolen-exit-status hazard the tracked list '
                    . 'avoids.',
            );

            // The decisive half: tearing the clone down must leave the
            // ORIGINAL's child untouched, i.e. still alive and still the
            // original's to reap.
            $clone->cancelAll();
            unset($clone);

            $this->assertSame(
                0,
                pcntl_waitpid($pid, $status, WNOHANG),
                'The clone reaped the original\'s child — the original now settles that agent '
                    . '"Failed, exit status unknowable" and discards the result it was about to '
                    . 'get.',
            );

            // The original still owns it, and still stops it.
            $original->cancelAll();
            unset($original);

            $stopped = false;
            for ($poll = 0; $poll < 400 && !$stopped; $poll++) {
                $stopped = pcntl_waitpid($pid, $status, WNOHANG) !== 0;
                if (!$stopped) {
                    usleep(5_000);
                }
            }

            $this->assertTrue(
                $stopped,
                'The original could no longer stop its own child after the clone was made.',
            );
        } finally {
            // Never leave a 120s sleeper behind, whichever assertion threw.
            @posix_kill($pid, SIGKILL);
            @pcntl_waitpid($pid, $status, WNOHANG);
        }
    }

    /**
     * A pool that is simply dropped must stop and collect its forked workers
     * before it deletes the directory they are writing into.
     *
     * executeAll()'s reset cannot cover this: both in-repo call sites build a
     * single-use pool (WorkflowEngine::executeParallelStage() constructs a
     * fresh one per stage, Chat::executeAgents() one per call), so the reset
     * can physically never fire a second time for them. A caller that takes the
     * \Generator either returns and breaks out of it after the first result
     * leaves live children behind, and __destruct() then rmdir()s $resultDir
     * out from under them — N zombies plus N orphaned `php -r` grandchildren
     * for the life of a TUI.
     *
     * This pins the STOPS half only. Its worker is a live 120s sleeper, so the
     * reap can legitimately run out of its 100ms budget on a loaded box and the
     * loop below has to accept "signalled, still a corpse for us to collect" as
     * a pass — which means deleting reapTerminatedWorkers() from __destruct()
     * would leave this test green. The reap half is pinned separately, on an
     * already-dead worker where the budget cannot be the explanation: see
     * {@see testDestructorReapsAWorkerThatIsAlreadyDeadRatherThanLeavingAZombie()}.
     */
    public function testDestructorStopsAndReapsWorkersLeftByAnAbandonedGenerator(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork()/posix_kill() are not available in this environment.');
        }

        $pool = new AgentWorkerPool(maxConcurrent: 1);
        $pid = $this->forkSleepingChild();
        $this->seedInFlight($pool, $this->makeAgent('abandoned-mid-generator'), $pid);

        unset($pool);

        // Bounded witness, not a timeout: an unsignalled worker sleeps for
        // 120s, so exhausting ~2s of polling IS the failure. -1 means the
        // destructor collected it too (no zombie); $pid means it signalled but
        // its 100ms budget ran out, which still proves the signal landed.
        $status = 0;
        for ($poll = 0; $poll < 400; $poll++) {
            $reaped = pcntl_waitpid($pid, $status, WNOHANG);
            if ($reaped === -1) {
                // Unwaitable: the destructor collected it itself, so there is
                // no zombie left for anyone else to find.
                $this->assertSame(-1, $reaped);
                return;
            }

            if ($reaped === $pid) {
                $this->assertTrue(pcntl_wifsignaled($status));
                $this->assertSame(
                    SIGTERM,
                    pcntl_wtermsig($status),
                    'The worker died, but not of the destructor\'s SIGTERM.',
                );
                return;
            }

            usleep(5_000);
        }

        @posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        $this->fail(
            'Destructing the pool left its forked worker running, with the IPC directory it '
                . 'writes into already deleted and nobody left to reap it.',
        );
    }

    /**
     * The destructor must COLLECT the workers it stops, not merely signal them.
     *
     * Trading an orphan for a zombie is not the fix: Chat::executeAgents() and
     * WorkflowEngine::executeParallelStage() each build a fresh pool per call
     * inside a TUI that runs for hours, so one uncollected corpse per pool is
     * the same unbounded leak by another name.
     *
     * The budget is removed from the question entirely rather than tolerated:
     * the worker is already dead — and observed dead, via EOF rather than a
     * wait, so it is still uncollected — BEFORE the pool is destroyed. The reap
     * loop therefore collects it on its first WNOHANG attempt, before it reaches
     * a usleep(), so a loaded box cannot change the answer; there is nothing
     * left for the 100ms window to be spent waiting on. That is what makes the
     * single unpolled assertion below legitimate: pcntl_waitpid() answering
     * -1/ECHILD means the destructor took it, and answering $pid means the
     * destructor left a zombie for us to find.
     */
    public function testDestructorReapsAWorkerThatIsAlreadyDeadRatherThanLeavingAZombie(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl_fork()/pcntl_waitpid() are not available in this environment.');
        }

        $pool = new AgentWorkerPool(maxConcurrent: 1);
        $pid = $this->forkChildThatHasAlreadyExited();
        $this->seedInFlight($pool, $this->makeAgent('dead-before-teardown'), $pid);

        unset($pool);

        // Unpolled on purpose, and self-cleaning: whichever branch this takes,
        // nothing is left behind. -1 means the destructor already collected it;
        // $pid means it had not, and this very call collects the zombie so the
        // failure below does not also leak one into the rest of the suite.
        $status = 0;
        $reaped = pcntl_waitpid($pid, $status, WNOHANG);

        $this->assertSame(
            -1,
            $reaped,
            'The destructor signalled its worker but never collected it. The child was already '
                . 'dead before __destruct() ran, so the bounded 100ms reap window cannot be the '
                . 'explanation — reapTerminatedWorkers() did not run at all.',
        );
    }

    /**
     * The destructor must stop and collect its workers BEFORE it deletes the
     * directory they write into.
     *
     * That ordering is the entire premise of __destruct()'s docblock, and
     * nothing else pins it: moving the glob/@unlink/@rmdir block above the
     * teardown leaves every other test in this file green. The harm is concrete
     * — a worker that lands a file in $resultDir between the glob() and the
     * @rmdir() makes the rmdir fail, leaking one 0700 temp directory per pool,
     * and both in-repo call sites construct a pool per call.
     *
     * The witness is a marker the destructor's own sweep cannot remove: glob()
     * matches '*.result' only, so while this file exists @rmdir() must fail.
     * The worker deletes it from a SIGTERM handler, so the directory can only be
     * gone at the end if the worker was stopped first. reapTerminatedWorkers()
     * returns as soon as the child is COLLECTED, and the unlink happens-before
     * the child's exit which happens-before that collection — so on the correct
     * ordering the marker is provably gone by the time the rmdir is reached.
     *
     * The handshake before seeding is load-bearing: without it the destructor's
     * SIGTERM could arrive before the child had installed its handler, the
     * default disposition would kill it with the marker still in place, and the
     * test would fail for a reason that has nothing to do with ordering.
     */
    public function testDestructorStopsItsWorkersBeforeDeletingTheDirectoryTheyWriteInto(): void
    {
        if (
            !function_exists('pcntl_fork')
            || !function_exists('posix_kill')
            || !function_exists('pcntl_async_signals')
            || !function_exists('stream_socket_pair')
        ) {
            $this->markTestSkipped('pcntl/posix signal handling and socket pairs are required here.');
        }

        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair() is unavailable in this environment.');
        }

        [$parentEnd, $childEnd] = $pair;

        $pool = new AgentWorkerPool(maxConcurrent: 1);
        $resultDir = (string) $this->readPrivate($pool, 'resultDir');
        // storeResult() creates this lazily and no result is ever written here,
        // so the test stands in for the first worker write.
        mkdir($resultDir, 0700, true);

        // Deliberately NOT named '*.result': the destructor's sweep would then
        // unlink it and the rmdir would succeed regardless of ordering.
        $marker = $resultDir . '/worker-is-still-writing';

        $pid = pcntl_fork();
        if ($pid === 0) {
            fclose($parentEnd);
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use ($marker): void {
                @unlink($marker);
                ForkedChild::exitNow(0);
            });
            file_put_contents($marker, 'held');
            fwrite($childEnd, "armed\n");
            // Interruptible, and bounded anyway so a lost SIGTERM can never
            // leave this process behind for the rest of the suite.
            sleep(120);
            ForkedChild::exitNow(0);
        }

        $this->assertNotSame(-1, $pid, 'pcntl_fork() failed.');
        fclose($childEnd);

        $dirGone = false;

        try {
            $read = [$parentEnd];
            $write = [];
            $except = [];
            // Bounded; exhausting it IS the failure, since the child announces
            // itself as its first act.
            $this->assertSame(
                1,
                stream_select($read, $write, $except, 10),
                'The worker never armed its SIGTERM handler, so this run could not have tested ordering.',
            );
            $this->assertSame("armed\n", (string) fgets($parentEnd));
            fclose($parentEnd);

            clearstatcache();
            $this->assertFileExists($marker, 'The worker did not create the file it is meant to hold.');

            $this->seedInFlight($pool, $this->makeAgent('holds-the-ipc-directory'), $pid);

            unset($pool);

            clearstatcache();
            $dirGone = !is_dir($resultDir);
        } finally {
            // Never leave a 120s sleeper, a zombie, or a 0700 temp directory
            // behind, whichever assertion threw.
            @posix_kill($pid, SIGKILL);
            $status = 0;
            for ($poll = 0; $poll < 400; $poll++) {
                if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                    break;
                }
                usleep(5_000);
            }
            @unlink($marker);
            @rmdir($resultDir);
        }

        $this->assertTrue(
            $dirGone,
            'The destructor deleted its IPC directory before it stopped the worker writing into '
                . 'it: the worker still held a file there when the @rmdir ran, so the rmdir failed '
                . 'and the pool leaked a 0700 temp directory. (A worker that took longer than the '
                . '100ms reap window to act on SIGTERM would look the same — but it sits in sleep() '
                . 'with async signals on, so that window is ~100x the time it needs.)',
        );
    }

    /**
     * releaseForkedWorkers() must survive a numeric-string agent id.
     *
     * PHP stores '42' as int(42), so the foreach hands terminateWorker() an
     * int — and it is typed string under declare(strict_types=1). Round 1 fixed
     * this at waitForCompletion()'s cast site and a test pins that one; this is
     * the OTHER cast site, reached by cancelAll() (and by executeAll()'s reset)
     * whenever an agent with a numeric id has a live fork. Without the cast the
     * whole teardown fatals with a TypeError.
     */
    public function testCancelAllReleasesAForkedWorkerWithANumericStringAgentId(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl_fork()/posix_kill() are not available in this environment.');
        }

        $agent = $this->makeAgent('42');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        // An already-exiting child keeps the reap deterministic; the load-
        // bearing line is the (string) cast on the way to terminateWorker(),
        // which runs whether or not the signal has anything left to stop.
        $this->seedInFlight($pool, $agent, $this->forkChildThatDiesSilently());
        $pid = $this->readPrivate($pool, 'activePids')[42];

        $pool->cancelAll();

        $this->assertSame([], $this->readPrivate($pool, 'activePids'));
        $this->assertSame(
            [],
            $this->readPrivate($pool, 'unreapedChildren'),
            'cancelAll() left a numeric-id worker it had signalled uncollected.',
        );

        $status = 0;
        $this->assertSame(
            -1,
            pcntl_waitpid($pid, $status, WNOHANG),
            'The numeric-id worker is still waitable after cancelAll() — i.e. a zombie.',
        );
    }

    /**
     * The deferred-reap list must be bounded by the "someone else got there
     * first" answer, not only by a successful reap.
     *
     * Under a SIGCHLD disposition of SIG_IGN — which candy-pty's
     * SignalForwarder can install in this very process — the kernel auto-reaps
     * every child, so pcntl_waitpid() answers -1 forever and never $pid. A
     * sweep that only cleared on `=== $pid` would then never remove the entry,
     * and $unreapedChildren would grow without bound across every executeAll()
     * in a long-lived TUI. Driven with a child this test reaps itself, which
     * produces the identical -1 without touching the global disposition.
     */
    public function testSweepDropsAChildSomeOtherWaiterAlreadyReaped(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl_fork()/pcntl_waitpid() are not available in this environment.');
        }

        $pool = new AgentWorkerPool(maxConcurrent: 1);

        $pid = $this->forkChildThatDiesSilently();
        $status = 0;
        $this->assertSame($pid, pcntl_waitpid($pid, $status), 'The test could not reap its own child.');

        $unreapedProp = new \ReflectionProperty(AgentWorkerPool::class, 'unreapedChildren');
        $unreapedProp->setAccessible(true);
        $unreapedProp->setValue($pool, [$pid => true]);

        $sweep = new \ReflectionMethod(AgentWorkerPool::class, 'sweepUnreapedChildren');
        $sweep->setAccessible(true);
        $sweep->invoke($pool);

        $this->assertSame(
            [],
            $unreapedProp->getValue($pool),
            'A child that is gone but unwaitable was left on the deferred-reap list, which then '
                . 'grows without bound for the life of the process.',
        );
    }

    /**
     * The deferred sweep must run ABOVE executeAll()'s early returns.
     *
     * cancelAll() is the main producer of deferred children AND it sets
     * wasCancelledByUser, so the next executeAll() always takes the first early
     * return. A sweep placed after that return could never, by construction,
     * collect what cancelAll() had just run out of budget on — every cancelled
     * run's stragglers would survive an extra generation.
     */
    public function testExecuteAllSweepsStragglersEvenWhenItEarlyReturnsAfterACancel(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl_fork()/pcntl_waitpid() are not available in this environment.');
        }

        $pool = new AgentWorkerPool(maxConcurrent: 1, executor: $this->makeBlockingExecutor());

        // Reaped here so the sweep's single non-blocking pass has a terminal
        // answer waiting for it — the placement is what is under test, not the
        // scheduler.
        $pid = $this->forkChildThatDiesSilently();
        $status = 0;
        $this->assertSame($pid, pcntl_waitpid($pid, $status));

        $pool->cancelAll();

        $unreapedProp = new \ReflectionProperty(AgentWorkerPool::class, 'unreapedChildren');
        $unreapedProp->setAccessible(true);
        $unreapedProp->setValue($pool, [$pid => true]);

        $results = iterator_to_array($pool->executeAll([$this->makeAgent('never-runs')], $this->request));

        $this->assertSame([], $results, 'The cancelled run must still early-return.');
        $this->assertSame(
            [],
            $unreapedProp->getValue($pool),
            'executeAll() early-returned without sweeping, so the cancelled run\'s straggler '
                . 'survived an extra generation.',
        );
    }

    /**
     * A synthesized failure must carry the SubAgent's dispatch time.
     *
     * completedAt alone is not enough: AgentManager::drain() mirrors the pair
     * onto the SubAgent, and with startedAt null both AgentResult::durationMs()
     * and SubAgent::elapsedSeconds() report a flat 0s in the status strip and
     * the dashboard for a sub-agent that plainly ran. This also pins the
     * `$this->active[$agentId] ?? null` lookup in the reap, which exists for no
     * other purpose than to feed it.
     */
    public function testWorkerDiedResultCarriesTheSubAgentsStartTime(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl_fork()/pcntl_waitpid() are not available in this environment.');
        }

        $agent = $this->makeAgent('timed-ghost');
        // A fixed, obviously-not-now value so the assertion cannot pass on a
        // freshly-stamped DateTimeImmutable that came from anywhere else.
        $startedAt = \DateTimeImmutable::createFromFormat('U.u', '1700000000.500000');
        $this->assertInstanceOf(\DateTimeImmutable::class, $startedAt);
        $agent->startedAt = $startedAt;

        $pool = new AgentWorkerPool(maxConcurrent: 1);
        $this->seedInFlight($pool, $agent, $this->forkChildThatDiesSilently());

        $this->assertSame($agent->id, $this->pollUntilSettled($pool));

        $result = $this->extractResultOf($pool, $agent->id);
        $this->assertInstanceOf(AgentResult::class, $result);
        $this->assertNotNull(
            $result->startedAt,
            'The synthesized failure dropped the dispatch time, so the whole run reports 0s.',
        );
        $this->assertSame('1700000000.500000', $result->startedAt->format('U.u'));
        $this->assertNotNull($result->completedAt);
        $this->assertGreaterThan(0, $result->durationMs());
    }

    /**
     * A result file that is valid JSON but not a valid result must not be
     * mistaken for one.
     *
     * The truncated-file test above stops at hasDecodableResult()'s `$data ===
     * ''` guard, so the arrayToResult() half — the part that rejects a payload
     * whose status is missing or unrecognised — is never exercised. Without it
     * the reap declines to synthesize, extractResult() returns null on the same
     * unparseable payload, and executeAll() yields NOTHING for an agent it
     * dispatched.
     */
    public function testForkedReapSynthesizesWhenTheResultFileIsValidJsonButNotAResult(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl_fork()/pcntl_waitpid() are not available in this environment.');
        }

        $agent = $this->makeAgent('unparseable-result');
        $pool = new AgentWorkerPool(maxConcurrent: 1);

        // Store a real result first so the pool's private IPC directory exists,
        // then overwrite it with a payload that decodes to an array yet carries
        // no recognisable status.
        $storeResult = new \ReflectionMethod(AgentWorkerPool::class, 'storeResult');
        $storeResult->setAccessible(true);
        $storeResult->invoke($pool, $agent->id, new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Completed,
            output: 'about to be corrupted',
        ));

        $resultFile = new \ReflectionMethod(AgentWorkerPool::class, 'resultFile');
        $resultFile->setAccessible(true);
        file_put_contents(
            $resultFile->invoke($pool, $agent->id),
            '{"agentId":"unparseable-result","status":"not-a-real-status"}',
        );

        $this->seedInFlight($pool, $agent, $this->forkChildThatDiesSilently());

        $this->assertSame($agent->id, $this->pollUntilSettled($pool));

        $result = $this->extractResultOf($pool, $agent->id);
        $this->assertInstanceOf(
            AgentResult::class,
            $result,
            'An undecodable result file left the agent settled with nothing to yield for it.',
        );
        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString('before writing a result', (string) $result->error?->getMessage());
    }

    /**
     * A forked child that outlives the test unless it is genuinely signalled.
     *
     * SIGTERM is explicitly reset to SIG_DFL because a handler inherited from
     * the PHPUnit process would make "it died" prove nothing about the signal.
     */
    private function forkSleepingChild(): int
    {
        $pid = pcntl_fork();
        if ($pid === 0) {
            pcntl_signal(SIGTERM, SIG_DFL);
            sleep(120);
            ForkedChild::exitNow(0);
        }

        $this->assertNotSame(-1, $pid, 'pcntl_fork() failed.');

        return $pid;
    }
}
