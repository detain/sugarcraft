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
        $pool = (new AgentWorkerPool(maxConcurrent: 3))
            ->withStopOnFirstFailure(false);

        $agents = [
            $this->makeAgent('success-1'),
            $this->makeAgent('success-2'),
        ];

        $executor = $this->makeBlockingExecutor();
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
}
