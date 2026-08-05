<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\Workflow;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Integration tests for workflow execution lifecycle.
 *
 * Tests the full end-to-end execution of workflows with mock agents:
 * - Sequential stages execute one after another
 * - Parallel stages execute tasks concurrently
 * - Pipeline stages chain outputs sequentially to next stage
 * - Context is correctly passed between stages (both {{var}} and {{stage.output}})
 */
final class WorkflowExecutionTest extends TestCase
{
    private string $tempDir;
    private string $oldHome;
    private WorkflowRegistry $registry;
    private ExecutorInterface $mockExecutor;
    private AgentWorkerPool $pool;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a unique temp directory to isolate tests
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-wf-exec-test-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        // Override HOME so pause files don't pollute ~/.sugar-crush/
        $this->oldHome = $_SERVER['HOME'] ?? '/root';
        $_SERVER['HOME'] = $this->tempDir;

        $this->registry = new WorkflowRegistry();

        // Mock the executor so no real LLM calls are made
        $this->mockExecutor = $this->getMockBuilder(ExecutorInterface::class)
            ->onlyMethods(['execute', 'executeStream', 'cancel', 'cancelAll'])
            ->getMock();

        $this->pool = new AgentWorkerPool(5, $this->mockExecutor);
        $this->engine = new WorkflowEngine($this->registry, $this->pool);
    }

    protected function tearDown(): void
    {
        // Restore original HOME
        $_SERVER['HOME'] = $this->oldHome;

        // Clean up temp directory
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    private function successfulAgentResult(string $output, int $tokens = 100, float $cost = 0.01): AgentResult
    {
        return new AgentResult(
            agentId: 'agent-' . uniqid(),
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: $tokens,
            costUsd: $cost,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * testSequentialStagesRunInOrder: three stages should execute sequentially,
     * each receiving context from the previous stage via {{stage.output}}.
     */
    public function testSequentialStagesRunInOrder(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('seq-test')
            ->description('Test sequential stage execution')
            ->stage('fetch', Tasks::agent('fetch')->prompt('Fetch data from {{source}}'))
            ->stage('process', Tasks::agent('processor')->prompt('Process: {{fetch.output}}'))
            ->stage('store', Tasks::agent('saver')->prompt('Store: {{process.output}}'))
            ->build();

        $this->registry->register($workflow);

        $capturedPrompts = [];
        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(function ($agent, CompleteRequest $request) use (&$capturedPrompts, &$callCount) {
                $capturedPrompts[] = $request->messages[0]['content'];
                $callCount++;
                return $this->successfulAgentResult("data-{$callCount}");
            });

        $result = $this->engine->run('seq-test', ['source' => 'api']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(3, $result->stageResults);

        // First stage should interpolate {{source}}
        $this->assertStringContainsString('Fetch data from api', $capturedPrompts[0]);

        // Second stage should see first stage output
        $this->assertStringContainsString('Process: data-1', $capturedPrompts[1]);

        // Third stage should see second stage output
        $this->assertStringContainsString('Store: data-2', $capturedPrompts[2]);

        // Context should contain all stage outputs
        $this->assertSame('data-1', $result->context['fetch.output']);
        $this->assertSame('data-2', $result->context['process.output']);
        $this->assertSame('data-3', $result->context['store.output']);
    }

    /**
     * testParallelStagesRunConcurrently: a parallel stage should execute all
     * tasks and collect their results. Verifies maxConcurrent is respected and
     * all agent outputs are concatenated.
     *
     * Note: True temporal concurrency timing (e.g., that tasks actually overlap
     * in wall-clock time) cannot be verified in a mock-based unit test. This
     * test only verifies that all tasks are executed and their outputs are
     * correctly collected. Timing verification would require integration tests
     * with real (non-mocked) agent execution.
     */
    public function testParallelStagesRunConcurrently(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('parallel-exec-test')
            ->description('Test parallel stage runs tasks concurrently')
            ->maxConcurrent(3)
            ->parallel('fan-out', [
                Tasks::agent('explorer')->prompt('Research auth'),
                Tasks::agent('explorer')->prompt('Research API'),
                Tasks::agent('explorer')->prompt('Research DB'),
                Tasks::agent('explorer')->prompt('Research UI'),
            ])
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(4))
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult("research-{$callCount}");
            });

        $result = $this->engine->run('parallel-exec-test', []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(1, $result->stageResults);

        $stageResult = $result->stageResults[0];
        $this->assertSame('fan-out', $stageResult->stageName);
        $this->assertCount(4, $stageResult->agents);

        // All outputs should appear in stage output (concatenated)
        $this->assertStringContainsString('research-1', $stageResult->output);
        $this->assertStringContainsString('research-2', $stageResult->output);
        $this->assertStringContainsString('research-3', $stageResult->output);
        $this->assertStringContainsString('research-4', $stageResult->output);
    }

    /**
     * testPipelineStageChainsSequentially: a pipeline stage should execute
     * nested stages one after another, passing each output as {{prevResult}}
     * to the next nested stage.
     */
    public function testPipelineStageChainsSequentially(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('pipeline-chain-test')
            ->description('Test pipeline stage chains nested stages')
            ->pipeline('process', [
                Tasks::agent('fetch')->prompt('Fetch {{input}}'),
                Tasks::agent('clean')->prompt('Clean: {{prevResult}}'),
                Tasks::agent('validate')->prompt('Validate: {{prevResult}}'),
            ])
            ->build();

        $this->registry->register($workflow);

        $capturedPrompts = [];
        $this->mockExecutor
            ->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(function ($agent, CompleteRequest $request) use (&$capturedPrompts) {
                $capturedPrompts[] = $request->messages[0]['content'];
                return $this->successfulAgentResult('output-' . count($capturedPrompts));
            });

        $result = $this->engine->run('pipeline-chain-test', ['input' => 'raw-data']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(1, $result->stageResults);

        // First nested stage gets initial context
        $this->assertStringContainsString('Fetch raw-data', $capturedPrompts[0]);

        // Second nested stage gets first output as {{prevResult}}
        $this->assertStringContainsString('Clean: output-1', $capturedPrompts[1]);

        // Third nested stage gets second output as {{prevResult}}
        $this->assertStringContainsString('Validate: output-2', $capturedPrompts[2]);
    }

    /**
     * testContextPassedBetweenStages: initial context variables should be
     * available in all stages, and stage outputs should accumulate in context.
     */
    public function testContextPassedBetweenStages(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('context-pass-test')
            ->description('Test context propagation across stages')
            ->stage('step1', Tasks::agent('coder')->prompt('Build {{project}} with {{language}}'))
            ->stage('step2', Tasks::agent('tester')->prompt('Test {{project}} result: {{step1.output}}'))
            ->build();

        $this->registry->register($workflow);

        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function () {
                return $this->successfulAgentResult('step-result');
            });

        $result = $this->engine->run('context-pass-test', [
            'project' => 'my-app',
            'language' => 'PHP',
        ]);

        $this->assertTrue($result->isSuccess());

        // Initial context preserved
        $this->assertSame('my-app', $result->context['project']);
        $this->assertSame('PHP', $result->context['language']);

        // Stage outputs added to context
        $this->assertSame('step-result', $result->context['step1.output']);
        $this->assertSame('step-result', $result->context['step2.output']);
    }

    /**
     * testVerificationStageRunsTaskThenVerifier: verification stage should
     * run the task first, then the verifier with {{prevResult}} set.
     */
    public function testVerificationStageRunsTaskThenVerifier(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('verify-flow-test')
            ->description('Test verification stage order')
            ->withVerification(
                'check-quality',
                Tasks::agent('builder')->prompt('Build feature X'),
                Tasks::agent('reviewer')->prompt('Review: {{prevResult}}'),
            )
            ->build();

        $this->registry->register($workflow);

        $capturedPrompts = [];
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function ($agent, CompleteRequest $request) use (&$capturedPrompts) {
                $capturedPrompts[] = $request->messages[0]['content'];
                return $this->successfulAgentResult('verification-output');
            });

        $result = $this->engine->run('verify-flow-test', []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $result->status);

        // First: task prompt (no prevResult yet)
        $this->assertStringContainsString('Build feature X', $capturedPrompts[0]);

        // Second: verifier with prevResult
        $this->assertStringContainsString('Review: verification-output', $capturedPrompts[1]);
    }

    /**
     * testYamlWorkflowLoadsAndExecutes: verifies that a workflow defined in a
     * YAML file on disk can be loaded via WorkflowRegistry::loadYaml() and
     * executed end-to-end with mock agents.
     *
     * This covers the "Load YAML workflow" spec item from the integration test plan.
     */
    public function testYamlWorkflowLoadsAndExecutes(): void
    {
        // Create a YAML workflow file in the temp directory
        $yamlContent = <<<YAML
name: yaml-exec-test
description: Test YAML workflow loading and execution
stages:
  - name: fetch
    agent: fetcher
    prompt: "Fetch data from {{source}}"
  - name: process
    agent: processor
    prompt: "Process: {{fetch.output}}"
YAML;

        $yamlPath = $this->tempDir . '/yaml-exec-test.yaml';
        file_put_contents($yamlPath, $yamlContent);

        // Create a registry pointing at our temp directory
        $yamlRegistry = new WorkflowRegistry($this->tempDir . '/');

        // Load the workflow from YAML
        $workflow = $yamlRegistry->load('yaml-exec-test');
        $this->assertSame('yaml-exec-test', $workflow->name);
        $this->assertSame('Test YAML workflow loading and execution', $workflow->description);

        // Register with engine's registry and set up mock
        $this->registry->register($workflow);

        $capturedPrompts = [];
        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function ($agent, CompleteRequest $request) use (&$capturedPrompts, &$callCount) {
                $capturedPrompts[] = $request->messages[0]['content'];
                $callCount++;
                return $this->successfulAgentResult("data-{$callCount}");
            });

        $result = $this->engine->run('yaml-exec-test', ['source' => 'api']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(2, $result->stageResults);

        // First stage should interpolate {{source}}
        $this->assertStringContainsString('Fetch data from api', $capturedPrompts[0]);

        // Second stage should see first stage output
        $this->assertStringContainsString('Process: data-1', $capturedPrompts[1]);

        // Context should contain stage outputs
        $this->assertSame('data-1', $result->context['fetch.output']);
        $this->assertSame('data-2', $result->context['process.output']);
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
