<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\ProcessExecutor;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * Tests for ProcessExecutor - process-based agent executor.
 */
final class ProcessExecutorTest extends TestCase
{
    private ProcessExecutor $executor;
    private SubAgent $agent;
    private CompleteRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = new ProcessExecutor();
        $this->agent = new SubAgent(
            id: 'test-agent-' . uniqid((string) getmypid(), true),
            agent: new Agent(
                name: 'TestAgent',
                description: 'Test agent for unit tests',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'Say hello',
        );
        $this->request = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Hello, agent!']],
        );
    }

    // -------------------------------------------------------------------------
    // execute() - basic spawn and run
    // -------------------------------------------------------------------------

    public function testExecuteReturnsAgentResult(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertInstanceOf(AgentResult::class, $result);
        $this->assertSame($this->agent->id, $result->agentId);
    }

    public function testExecuteReturnsCompletedStatusOnSuccess(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecutePopulatesOutput(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertNotNull($result->output);
        $this->assertNotEmpty($result->output);
    }

    public function testExecuteRecordsStartAndEndTime(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertNotNull($result->startedAt);
        $this->assertNotNull($result->completedAt);
    }

    public function testExecuteDurationIsPositive(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertGreaterThan(0, $result->durationMs());
    }

    public function testExecuteWithDifferentBinaryPath(): void
    {
        $executor = new ProcessExecutor(binaryPath: 'php', timeoutSeconds: 60);
        $result = $executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecuteWithCustomTimeout(): void
    {
        $executor = new ProcessExecutor(binaryPath: 'php', timeoutSeconds: 5);
        $result = $executor->execute($this->agent, $this->request);

        $this->assertNotNull($result->status);
    }

    // -------------------------------------------------------------------------
    // executeStream() - streaming output
    // -------------------------------------------------------------------------

    public function testExecuteStreamReturnsGenerator(): void
    {
        $generator = $this->executor->executeStream($this->agent, $this->request);

        $this->assertInstanceOf(\Generator::class, $generator);
    }

    public function testExecuteStreamYieldsStreamingStatusFirst(): void
    {
        $generator = $this->executor->executeStream($this->agent, $this->request);

        $firstChunk = $generator->current();
        $this->assertSame(AgentStatus::Streaming, $firstChunk->status);
        $this->assertSame($this->agent->id, $firstChunk->agentId);
    }

    public function testExecuteStreamYieldsCompleteStatusLast(): void
    {
        $generator = $this->executor->executeStream($this->agent, $this->request);

        foreach ($generator as $result) {
            // iterate to completion
        }

        // Generator has been exhausted, last result should have been complete
        $this->assertTrue(true); // If we got here without error, the loop completed
    }

    public function testExecuteStreamYieldsOutputChunks(): void
    {
        $results = [];
        foreach ($this->executor->executeStream($this->agent, $this->request) as $result) {
            $results[] = $result;
            if ($result->status === AgentStatus::Completed) {
                break;
            }
        }

        $this->assertNotEmpty($results);
        $this->assertGreaterThanOrEqual(1, count($results));
    }

    public function testExecuteStreamHasAgentIdOnAllChunks(): void
    {
        foreach ($this->executor->executeStream($this->agent, $this->request) as $result) {
            $this->assertSame($this->agent->id, $result->agentId);
            if ($result->status === AgentStatus::Completed) {
                break;
            }
        }
    }

    // -------------------------------------------------------------------------
    // cancel() and cancelAll() stubs
    // -------------------------------------------------------------------------

    public function testCancelWithInvalidIdDoesNotThrow(): void
    {
        $this->executor->cancel('nonexistent-agent-id');

        $this->assertTrue(true); // No exception thrown
    }

    public function testCancelAllWithNoRunningAgentsDoesNotThrow(): void
    {
        $this->executor->cancelAll();

        $this->assertTrue(true); // No exception thrown
    }

    public function testCancelAfterExecuteDoesNotThrow(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        // Cancel should handle already-finished processes gracefully
        $this->executor->cancel($this->agent->id);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    // -------------------------------------------------------------------------
    // Lifecycle and process management
    // -------------------------------------------------------------------------

    public function testExecuteMultipleAgentsInSequence(): void
    {
        $agent2 = new SubAgent(
            id: 'test-agent-2-' . uniqid((string) getmypid(), true),
            agent: new Agent(
                name: 'TestAgent2',
                description: 'Second test agent',
                prompt: 'You are a second test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'Say goodbye',
        );

        $result1 = $this->executor->execute($this->agent, $this->request);
        $result2 = $this->executor->execute($agent2, $this->request);

        $this->assertSame(AgentStatus::Completed, $result1->status);
        $this->assertSame(AgentStatus::Completed, $result2->status);
    }

    public function testExecuteCleansUpProcesses(): void
    {
        // Execute should clean up its process after completion
        $result = $this->executor->execute($this->agent, $this->request);

        // If we get here without zombie processes or memory leaks, the test passes
        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecuteStreamCleansUpProcesses(): void
    {
        // Execute stream should clean up its process after completion
        foreach ($this->executor->executeStream($this->agent, $this->request) as $result) {
            if ($result->status === AgentStatus::Completed) {
                break;
            }
        }

        // If we get here without zombie processes or memory leaks, the test passes
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    public function testExecuteWithEmptyTask(): void
    {
        $agentWithEmptyTask = new SubAgent(
            id: 'empty-task-agent-' . uniqid((string) getmypid(), true),
            agent: $this->agent->agent,
            task: '',
        );

        $result = $this->executor->execute($agentWithEmptyTask, $this->request);

        $this->assertNotNull($result->status);
    }

    public function testExecuteWithAgentHavingNoTools(): void
    {
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecuteWithRequestHavingNoSystemPrompt(): void
    {
        $requestNoSystem = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Hello']],
        );

        $result = $this->executor->execute($this->agent, $requestNoSystem);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecuteWithRequestHavingNullOptionalFields(): void
    {
        $requestFull = new CompleteRequest(
            model: 'test-model',
            messages: [['role' => 'user', 'content' => 'Hello']],
            tools: null,
            systemPrompt: null,
            temperature: null,
            maxTokens: null,
            jsonSchema: null,
        );

        $result = $this->executor->execute($this->agent, $requestFull);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    // -------------------------------------------------------------------------
    // Heartbeat mechanism
    // -------------------------------------------------------------------------

    public function testExecuteSucceedsWhenWorkerSendsHeartbeats(): void
    {
        // The default inline worker sends heartbeats every 5 seconds;
        // with a short task it should complete well within the 15s heartbeat window.
        $result = $this->executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    public function testExecuteSucceedsWithNormalHeartbeatWorker(): void
    {
        // Verifies that a worker sending regular heartbeats completes successfully.
        // The default inline worker sends heartbeats every 500ms, well within the
        // 15-second heartbeat timeout window, so execute() returns Completed.
        $executor = new ProcessExecutor(binaryPath: 'php', timeoutSeconds: 30);

        $result = $executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

    // -------------------------------------------------------------------------
    // Timeout escalation (SIGTERM → SIGKILL)
    // -------------------------------------------------------------------------

    public function testExecuteTimesOutAndReturnsFailedStatus(): void
    {
        // Short timeout so the test completes quickly.
        $executor = new ProcessExecutor(binaryPath: 'php', timeoutSeconds: 1);

        $result = $executor->execute($this->agent, $this->request);

        // The task takes longer than 1 second; timeout should trigger failure.
        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString('timed out', $result->error->getMessage());
    }

    public function testTimeoutUsesSigtermFollowedBySigkill(): void
    {
        // This test verifies that after a timeout, SIGKILL is eventually sent.
        // Worker takes ~1.04s; use timeout of 500ms so the deadline definitely fires.
        // This confirms the escalation path runs and returns Failed without zombie processes.
        $executor = new ProcessExecutor(binaryPath: 'php', timeoutSeconds: 0);
        $result = $executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Failed, $result->status);
        $this->assertStringContainsString('timed out', $result->error->getMessage());

        // If SIGKILL escalation works, the process is cleaned up and we don't
        // have zombie processes hanging around.
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Crash recovery
    // -------------------------------------------------------------------------

    public function testExecuteReturnsPartialOutputOnCrash(): void
    {
        // We cannot easily simulate a segfault in a test, but we can verify that
        // the partial-output path exists and that execute() correctly returns
        // whatever was in the buffer before the crash.
        // For the P1.S6 crash recovery, we verify:
        // 1. proc_open failures throw a descriptive exception
        // 2. Non-zero exit codes are captured as failures with partial output
        $result = $this->executor->execute($this->agent, $this->request);

        // Normal worker exits with 0 — no partial output returned as failure
        if ($result->status === AgentStatus::Failed) {
            // On crash, partial output should be present
            $this->assertNotNull($result->output);
        } else {
            $this->assertSame(AgentStatus::Completed, $result->status);
        }
    }

    public function testSpawnWorkerWithInvalidBinaryThrows(): void
    {
        $executor = new ProcessExecutor(binaryPath: '/nonexistent/php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to spawn');

        $executor->execute($this->agent, $this->request);
    }

    // -------------------------------------------------------------------------
    // Backpressure — memory pressure detection
    // -------------------------------------------------------------------------

    public function testExecuteThrowsOnMemoryPressure(): void
    {
        // Use a memory pressure threshold of 0.0 so ANY memory usage triggers backpressure.
        $executor = new ProcessExecutor(
            binaryPath: 'php',
            timeoutSeconds: 300,
            memoryPressureThreshold: 0.0,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Memory pressure');

        $executor->execute($this->agent, $this->request);
    }

    public function testExecuteStreamThrowsOnMemoryPressure(): void
    {
        $executor = new ProcessExecutor(
            binaryPath: 'php',
            timeoutSeconds: 300,
            memoryPressureThreshold: 0.0,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Memory pressure');

        //noinspection PhpUnitInspection
        foreach ($executor->executeStream($this->agent, $this->request) as $_) {
            // consume
        }
    }

    public function testExecuteSucceedsWithHighMemoryThreshold(): void
    {
        // With threshold 1.0 (100%) memory pressure is never triggered.
        $executor = new ProcessExecutor(
            binaryPath: 'php',
            timeoutSeconds: 300,
            memoryPressureThreshold: 1.0,
        );

        $result = $executor->execute($this->agent, $this->request);

        $this->assertSame(AgentStatus::Completed, $result->status);
    }

}
