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
 * Integration tests for workflow resumption from paused state.
 *
 * Tests the pause/resume cycle:
 * - pause() writes a valid pause file with current state
 * - resume() reloads state and continues from where it left off
 * - Completed stages are not re-run on resume
 * - Context is preserved across pause/resume
 */
final class WorkflowResumptionTest extends TestCase
{
    private string $tempDir;
    private WorkflowRegistry $registry;
    private ExecutorInterface $mockExecutor;
    private AgentWorkerPool $pool;
    private WorkflowEngine $engine;
    private string $originalHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalHome = $_SERVER['HOME'] ?? '/root';
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-resume-test-' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);

        // Override HOME so pause files go to our temp directory
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
        $_SERVER['HOME'] = $this->originalHome;

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
     * testPauseCreatesValidPauseFile: running a workflow and then calling
     * pause() should produce a valid JSON pause file with all state needed
     * to resume.
     */
    public function testPauseCreatesValidPauseFile(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('pause-file-test')
            ->description('Test pause creates valid pause file')
            ->stage('stage-1', Tasks::agent('coder')->prompt('Do first task'))
            ->stage('stage-2', Tasks::agent('coder')->prompt('Do second task'))
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult("output-{$callCount}");
            });

        // Run the workflow to completion
        $result = $this->engine->run('pause-file-test', []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $callCount);

        // Now pause it
        $this->engine->pause('pause-file-test');

        // Verify pause file exists
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/pause-file-test.json';
        $this->assertFileExists($pauseFile);

        // Verify pause file contents
        $data = json_decode(file_get_contents($pauseFile), true);
        $this->assertSame('pause-file-test', $data['workflowId']);
        $this->assertSame('paused', $data['status']);
        $this->assertSame(2, $data['stagesCompleted']);
        $this->assertSame('output-1', $data['context']['stage-1.output']);
        $this->assertSame('output-2', $data['context']['stage-2.output']);
        $this->assertSame(200, $data['totalTokens']);
        $this->assertSame(0.02, $data['totalCost']);

        // Verify stageResults array is present
        $this->assertCount(2, $data['stageResults']);
        $this->assertSame('stage-1', $data['stageResults'][0]['stageName']);
        $this->assertSame('stage-2', $data['stageResults'][1]['stageName']);
    }

    /**
     * testResumeContinuesFromPausedState: simulate a workflow that was
     * interrupted after stage 1, then resume and verify stage 2 completes.
     */
    public function testResumeContinuesFromPausedState(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('resume-continue-test')
            ->description('Test resume continues from paused state')
            ->stage('stage-1', Tasks::agent('coder')->prompt('First step'))
            ->stage('stage-2', Tasks::agent('coder')->prompt('Second step'))
            ->stage('stage-3', Tasks::agent('coder')->prompt('Third step'))
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->any())
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult("output-{$callCount}");
            });

        // Create the .running directory and simulate a pause file for a workflow where only stage 1 completed
        mkdir($this->tempDir . '/.sugar-crush/workflows/.running', 0755, true);
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/resume-continue-test.json';
        $pauseData = [
            'workflowId' => 'resume-continue-test',
            'workflowPath' => 'resume-continue-test',
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => [
                'stage-1.output' => 'output-1',
            ],
            'stageResults' => [
                [
                    'stageName' => 'stage-1',
                    'status' => 'completed',
                    'output' => 'output-1',
                    'error' => null,
                    'agents' => [
                        [
                            'agentId' => 'agent-1',
                            'status' => 'completed',
                            'output' => 'output-1',
                            'error' => null,
                            'tokensUsed' => 100,
                            'costUsd' => 0.01,
                            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                            'completedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                        ],
                    ],
                    'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'completedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
            ],
            'totalTokens' => 100,
            'totalCost' => 0.01,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        file_put_contents($pauseFile, json_encode($pauseData, JSON_PRETTY_PRINT) . "\n");

        // Resume should continue from stage 2 (index 1)
        $resumeResult = $this->engine->resume('resume-continue-test');

        $this->assertTrue($resumeResult->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $resumeResult->status);

        // Context from pause file should be preserved
        $this->assertSame('output-1', $resumeResult->context['stage-1.output']);

        // stage-2.output is from the first resumed call (callCount started at 0 since no run() was called)
        // stage-3.output is from the second resumed call
        $this->assertSame('output-1', $resumeResult->context['stage-2.output'] ?? null);
        $this->assertSame('output-2', $resumeResult->context['stage-3.output'] ?? null);
    }

    /**
     * testCompletedStagesNotReRun: verify that when a workflow is resumed,
     * stages that were already completed are skipped and not re-executed.
     */
    public function testCompletedStagesNotReRun(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('no-rerun-test')
            ->description('Test completed stages not re-run on resume')
            ->stage('alpha', Tasks::agent('worker')->prompt('Alpha task'))
            ->stage('beta', Tasks::agent('worker')->prompt('Beta task'))
            ->stage('gamma', Tasks::agent('worker')->prompt('Gamma task'))
            ->build();

        $this->registry->register($workflow);

        $executionLog = [];
        $callCount = 0;
        $this->mockExecutor
            ->expects($this->any())
            ->method('execute')
            ->willReturnCallback(function ($agent, CompleteRequest $request) use (&$executionLog, &$callCount) {
                $callCount++;
                $content = $request->messages[0]['content'];
                $executionLog[] = "call-{$callCount}: {$content}";
                return $this->successfulAgentResult("result-{$callCount}");
            });

        // Create the .running directory and a pause file indicating stage 1 (alpha) completed
        mkdir($this->tempDir . '/.sugar-crush/workflows/.running', 0755, true);
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/no-rerun-test.json';
        $pauseData = [
            'workflowId' => 'no-rerun-test',
            'workflowPath' => 'no-rerun-test',
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => [
                'alpha.output' => 'result-1',
            ],
            'stageResults' => [
                [
                    'stageName' => 'alpha',
                    'status' => 'completed',
                    'output' => 'result-1',
                    'error' => null,
                    'agents' => [],
                    'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'completedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
            ],
            'totalTokens' => 100,
            'totalCost' => 0.01,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        file_put_contents($pauseFile, json_encode($pauseData, JSON_PRETTY_PRINT) . "\n");

        // Resume should execute only beta and gamma, NOT alpha
        $resumeResult = $this->engine->resume('no-rerun-test');

        $this->assertTrue($resumeResult->isSuccess());

        // Verify only 2 calls were made (beta and gamma), not 3
        $this->assertCount(2, $executionLog);

        // Verify alpha was not re-run
        foreach ($executionLog as $log) {
            $this->assertStringNotContainsString('Alpha task', $log);
        }

        // Verify beta and gamma were run
        $this->assertStringContainsString('Beta task', $executionLog[0]);
        $this->assertStringContainsString('Gamma task', $executionLog[1]);
    }

    /**
     * testResumeWithPartialParallelStage: test resuming when a parallel
     * stage was partially complete (not fully supported in current impl,
     * but verifies graceful handling).
     */
    public function testResumeWithContextPreservation(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('context-preserve-test')
            ->description('Test context is preserved across pause/resume')
            ->stage('init', Tasks::agent('setup')->prompt('Initialize {{project}}'))
            ->stage('process', Tasks::agent('processor')->prompt('Process {{init.output}}'))
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->any())
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult("phase-{$callCount}-result");
            });

        // Create the .running directory and a pause file with initial context
        mkdir($this->tempDir . '/.sugar-crush/workflows/.running', 0755, true);
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/context-preserve-test.json';
        $pauseData = [
            'workflowId' => 'context-preserve-test',
            'workflowPath' => 'context-preserve-test',
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => [
                'project' => 'my-app',
                'init.output' => 'phase-1-result',
            ],
            'stageResults' => [
                [
                    'stageName' => 'init',
                    'status' => 'completed',
                    'output' => 'phase-1-result',
                    'error' => null,
                    'agents' => [],
                    'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'completedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
            ],
            'totalTokens' => 100,
            'totalCost' => 0.01,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        file_put_contents($pauseFile, json_encode($pauseData, JSON_PRETTY_PRINT) . "\n");

        // Resume should preserve the 'project' initial context
        $resumeResult = $this->engine->resume('context-preserve-test');

        $this->assertTrue($resumeResult->isSuccess());

        // Original initial context should still be present
        $this->assertSame('my-app', $resumeResult->context['project']);

        // init.output is from the pause file (no run() was called first, so callCount=0 on resume)
        // process.output is from the first resumed call -> 'phase-1-result'
        $this->assertSame('phase-1-result', $resumeResult->context['init.output']);
        $this->assertSame('phase-1-result', $resumeResult->context['process.output']);
    }

    /**
     * testGetStatusReturnsCorrectPausedStatus: verify getStatus() correctly
     * reads the status from a pause file.
     */
    public function testGetStatusReturnsCorrectPausedStatus(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('status-check-test')
            ->description('Test getStatus returns paused status')
            ->stage('step1', Tasks::agent('worker')->prompt('Do work'))
            ->build();

        $this->registry->register($workflow);

        // Create the .running directory and a pause file directly
        mkdir($this->tempDir . '/.sugar-crush/workflows/.running', 0755, true);
        $pauseFile = $this->tempDir . '/.sugar-crush/workflows/.running/status-check-test.json';
        $pauseData = [
            'workflowId' => 'status-check-test',
            'workflowPath' => 'status-check-test',
            'status' => 'paused',
            'stagesCompleted' => 1,
            'context' => [],
            'stageResults' => [],
            'totalTokens' => 100,
            'totalCost' => 0.01,
            'startedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        file_put_contents($pauseFile, json_encode($pauseData));

        $status = $this->engine->getStatus('status-check-test');

        $this->assertSame(WorkflowStatus::Paused, $status);
    }

    /**
     * testResumeMissingPauseFileThrows: verify resume() throws when no
     * pause file exists.
     */
    public function testResumeMissingPauseFileThrows(): void
    {
        $this->expectException(\SugarCraft\Crush\Workflows\WorkflowNotRunningException::class);
        $this->expectExceptionMessageMatches('/No paused workflow found/i');

        $this->engine->resume('nonexistent-pause-file');
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
