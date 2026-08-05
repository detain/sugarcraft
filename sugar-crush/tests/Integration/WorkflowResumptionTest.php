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
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Integration tests for workflow pause/resume lifecycle.
 *
 * Tests the full end-to-end resumption of workflows with mock agents:
 * - Workflows can be paused mid-execution and resumed without re-running completed stages
 * - Resume correctly picks up from the saved stage index and restores context
 * - Status is correctly reported from the pause file
 */
final class WorkflowResumptionTest extends TestCase
{
    private string $tempDir;
    private ?string $oldHome = null;
    private WorkflowRegistry $registry;
    private ExecutorInterface $mockExecutor;
    private AgentWorkerPool $pool;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a unique temp directory to isolate tests
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-wf-resume-test-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        // Save original HOME before overriding, so we can restore it in tearDown
        $this->oldHome = $_SERVER['HOME'] ?? '/root';

        // Override HOME so pause files don't pollute ~/.sugar-crush/
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
        $_SERVER['HOME'] = $this->oldHome ?? '/root';

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
     * Helper: build a pause file directly for a given workflowId without running the workflow.
     * This simulates an interrupt mid-execution by writing the state that would exist after
     * a certain number of stages have completed.
     */
    private function writePauseFile(
        string $workflowId,
        string $workflowPath,
        int $stagesCompleted,
        array $context,
        array $stageResults,
        int $totalTokens = 0,
        float $totalCost = 0.0,
    ): void {
        $pauseDir = $this->tempDir . '/.sugar-crush/workflows/.running';
        if (!is_dir($pauseDir)) {
            mkdir($pauseDir, 0755, true);
        }

        $data = [
            'workflowId' => $workflowId,
            'workflowPath' => $workflowPath,
            'status' => WorkflowStatus::Paused->value,
            'stagesCompleted' => $stagesCompleted,
            'context' => $context,
            'stageResults' => $stageResults,
            'totalTokens' => $totalTokens,
            'totalCost' => $totalCost,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $pauseFile = $pauseDir . '/' . $workflowId . '.json';
        file_put_contents($pauseFile, json_encode($data, JSON_PRETTY_PRINT) . "\n");
    }

    /**
     * testResumeWorkflowFromPauseFile: a three-stage workflow is interrupted after
     * stage 1. The pause file is written directly (simulating a mid-execution crash).
     * After resuming, stage 1 must not be re-run, only stages 2 and 3 execute.
     * The final status must be Completed and all three stage results present.
     */
    public function testResumeWorkflowFromPauseFile(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('multi-stage-resume')
            ->description('Test resume from pause file')
            ->stage('stage1', Tasks::agent('agent1')->prompt('Step 1: {{input}}'))
            ->stage('stage2', Tasks::agent('agent2')->prompt('Step 2: {{input}}'))
            ->stage('stage3', Tasks::agent('agent3')->prompt('Step 3: {{input}}'))
            ->build();

        $this->registry->register($workflow);

        // Write a pause file representing state after stage 1 completed
        $this->writePauseFile(
            workflowId: 'multi-stage-resume',
            workflowPath: 'multi-stage-resume',
            stagesCompleted: 1,
            context: [
                'input' => 'test-data',
                'stage1.output' => 'result-from-stage-1',
            ],
            stageResults: [
                [
                    'stageName' => 'stage1',
                    'status' => WorkflowStatus::Completed->value,
                    'output' => 'result-from-stage-1',
                    'error' => null,
                    'agents' => [
                        [
                            'agentId' => 'agent-stage1-1',
                            'status' => AgentStatus::Completed->value,
                            'output' => 'result-from-stage-1',
                            'error' => null,
                            'tokensUsed' => 100,
                            'costUsd' => 0.01,
                            'startedAt' => (new \DateTimeImmutable('-10 minutes'))->format(\DateTimeInterface::ATOM),
                            'completedAt' => (new \DateTimeImmutable('-9 minutes'))->format(\DateTimeInterface::ATOM),
                        ],
                    ],
                    'startedAt' => (new \DateTimeImmutable('-10 minutes'))->format(\DateTimeInterface::ATOM),
                    'completedAt' => (new \DateTimeImmutable('-9 minutes'))->format(\DateTimeInterface::ATOM),
                ],
            ],
            totalTokens: 100,
            totalCost: 0.01,
        );

        // Track which stages run during resume
        $executedStages = [];
        $this->mockExecutor
            ->expects($this->exactly(2)) // Only stage 2 and stage 3 should run
            ->method('execute')
            ->willReturnCallback(function ($agent, CompleteRequest $request) use (&$executedStages) {
                // Extract stage name from prompt content
                $content = $request->messages[0]['content'] ?? '';
                if (str_contains($content, 'Step 2')) {
                    $executedStages[] = 'stage2';
                    return $this->successfulAgentResult('result-from-stage-2');
                }
                if (str_contains($content, 'Step 3')) {
                    $executedStages[] = 'stage3';
                    return $this->successfulAgentResult('result-from-stage-3');
                }
                // Fallback - should not reach here for this test
                $executedStages[] = 'unknown';
                return $this->successfulAgentResult('unexpected-result');
            });

        // Resume the workflow
        $result = $this->engine->resume('multi-stage-resume');

        // Verify stages 2 and 3 were executed, stage 1 was NOT re-run
        $this->assertContains('stage2', $executedStages);
        $this->assertContains('stage3', $executedStages);
        $this->assertNotContains('stage1', $executedStages);

        // Final status must be Completed
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertTrue($result->isSuccess());

        // After resuming, stageResults contains only the newly-executed stages (2 and 3).
        // Stage 1 was already completed and stored in the pause file, not re-returned.
        $this->assertCount(2, $result->stageResults);

        // Stages 2 and 3 must have fresh results
        $this->assertSame('stage2', $result->stageResults[0]->stageName);
        $this->assertSame('result-from-stage-2', $result->stageResults[0]->output);
        $this->assertSame('stage3', $result->stageResults[1]->stageName);
        $this->assertSame('result-from-stage-3', $result->stageResults[1]->output);

        // Context must contain all stage outputs (including the pre-pause stage1.output)
        $this->assertSame('result-from-stage-1', $result->context['stage1.output']);
        $this->assertSame('result-from-stage-2', $result->context['stage2.output']);
        $this->assertSame('result-from-stage-3', $result->context['stage3.output']);
    }

    /**
     * testPauseFileContainsCorrectStageProgressAndCanBeLoaded: verify that a pause
     * file written mid-execution contains the correct stage progress and can be loaded
     * via getStatus().
     */
    public function testPauseFileContainsCorrectStageProgressAndCanBeLoaded(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('pause-file-progress')
            ->description('Test pause file contents')
            ->stage('stepA', Tasks::agent('agentA')->prompt('Do step A'))
            ->stage('stepB', Tasks::agent('agentB')->prompt('Do step B'))
            ->build();

        $this->registry->register($workflow);

        $initialContext = ['input' => 'my-input', 'stepA.output' => 'output-from-A'];
        $serializedStageResults = [
            [
                'stageName' => 'stepA',
                'status' => WorkflowStatus::Completed->value,
                'output' => 'output-from-A',
                'error' => null,
                'agents' => [
                    [
                        'agentId' => 'agent-A-1',
                        'status' => AgentStatus::Completed->value,
                        'output' => 'output-from-A',
                        'error' => null,
                        'tokensUsed' => 150,
                        'costUsd' => 0.015,
                        'startedAt' => (new \DateTimeImmutable('-5 minutes'))->format(\DateTimeInterface::ATOM),
                        'completedAt' => (new \DateTimeImmutable('-4 minutes'))->format(\DateTimeInterface::ATOM),
                    ],
                ],
                'startedAt' => (new \DateTimeImmutable('-5 minutes'))->format(\DateTimeInterface::ATOM),
                'completedAt' => (new \DateTimeImmutable('-4 minutes'))->format(\DateTimeInterface::ATOM),
            ],
        ];

        // Write a pause file directly
        $this->writePauseFile(
            workflowId: 'pause-file-progress',
            workflowPath: 'pause-file-progress',
            stagesCompleted: 1,
            context: $initialContext,
            stageResults: $serializedStageResults,
            totalTokens: 150,
            totalCost: 0.015,
        );

        // getStatus() must return the Paused status from the file
        $status = $this->engine->getStatus('pause-file-progress');
        $this->assertSame(WorkflowStatus::Paused, $status);

        // Verify the pause file can be loaded and has correct structure
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/pause-file-progress.json';
        $this->assertFileExists($pauseFile);

        $loaded = json_decode(file_get_contents($pauseFile), true);
        $this->assertSame('pause-file-progress', $loaded['workflowId']);
        $this->assertSame('pause-file-progress', $loaded['workflowPath']);
        $this->assertSame(WorkflowStatus::Paused->value, $loaded['status']);
        $this->assertSame(1, $loaded['stagesCompleted']);
        $this->assertSame($initialContext, $loaded['context']);
        $this->assertCount(1, $loaded['stageResults']);
        $this->assertSame('stepA', $loaded['stageResults'][0]['stageName']);
        $this->assertSame('output-from-A', $loaded['stageResults'][0]['output']);
        $this->assertSame(150, $loaded['totalTokens']);
        $this->assertSame(0.015, $loaded['totalCost']);
        $this->assertArrayHasKey('startedAt', $loaded);
        $this->assertArrayHasKey('pausedAt', $loaded);
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
