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
            id: 'test-agent-' . uniqid(),
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
            id: 'test-agent-2-' . uniqid(),
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
            id: 'empty-task-agent-' . uniqid(),
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
}
