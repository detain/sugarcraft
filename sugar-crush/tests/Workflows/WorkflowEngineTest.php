<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\ExecutorInterface;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\Workflow;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowStatus;

final class WorkflowEngineTest extends TestCase
{
    private WorkflowRegistry $registry;
    private AgentWorkerPool $pool;
    private WorkflowEngine $engine;
    private ExecutorInterface $mockExecutor;

    protected function setUp(): void
    {
        $this->registry = new WorkflowRegistry();

        // AgentWorkerPool is final, so we cannot mock it directly.
        // Instead we inject a mock ExecutorInterface which executeOne delegates to.
        $this->mockExecutor = $this->getMockBuilder(ExecutorInterface::class)
            ->onlyMethods(['execute', 'executeStream', 'cancel', 'cancelAll'])
            ->getMock();

        $this->pool = new AgentWorkerPool(5, $this->mockExecutor);
        $this->engine = new WorkflowEngine($this->registry, $this->pool);
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

    private function failedAgentResult(string $error): AgentResult
    {
        return new AgentResult(
            agentId: 'agent-' . uniqid(),
            status: AgentStatus::Failed,
            output: 'failed',
            error: new \RuntimeException($error),
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    public function testRunExecutesSequentialStagesAndReturnsWorkflowResult(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('test-workflow')
            ->description('Test sequential execution')
            ->stage('stage-1', Tasks::agent('coder')->prompt('Do task 1'))
            ->stage('stage-2', Tasks::agent('coder')->prompt('Do task 2'))
            ->build();

        $this->registry->register($workflow);

        // Capture each execute() call to return sequential results
        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult("output-{$callCount}", 100 * $callCount, 0.01 * $callCount);
            });

        $result = $this->engine->run('test-workflow', []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(2, $result->stageResults);
        $this->assertSame('stage-1', $result->stageResults[0]->stageName);
        $this->assertSame('stage-2', $result->stageResults[1]->stageName);
        $this->assertSame('output-1', $result->stageResults[0]->output);
        $this->assertSame('output-2', $result->stageResults[1]->output);
        $this->assertSame(300, $result->totalTokens);
        $this->assertSame(0.03, $result->totalCost);
    }

    public function testContextInterpolationReplacesSimpleVariables(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('interp-test')
            ->description('Test context interpolation')
            ->stage('greet', Tasks::agent('coder')->prompt('Hello {{name}}!'))
            ->build();

        $this->registry->register($workflow);

        $capturedContent = '';
        $this->mockExecutor
            ->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$capturedContent) {
                $capturedContent = $request->messages[0]['content'];
                return $this->successfulAgentResult('done');
            });

        $this->engine->run('interp-test', ['name' => 'World']);

        $this->assertStringContainsString('Hello World!', $capturedContent);
    }

    public function testContextInterpolationReplacesStageOutputReferences(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('output-ref-test')
            ->description('Test stage output reference')
            ->stage('first', Tasks::agent('coder')->prompt('First stage'))
            ->stage('second', Tasks::agent('coder')->prompt('Previous output: {{first.output}}'))
            ->build();

        $this->registry->register($workflow);

        $capturedPrompts = [];
        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$capturedPrompts, &$callCount) {
                $capturedPrompts[] = $request->messages[0]['content'];
                $callCount++;
                return $this->successfulAgentResult("result-{$callCount}");
            });

        $this->engine->run('output-ref-test', []);

        // Second stage prompt should reference first stage output
        $this->assertCount(2, $capturedPrompts);
        $this->assertStringContainsString('Previous output: result-1', $capturedPrompts[1]);
    }

    public function testContextVariablesWithoutReplacementAreLeftUnchanged(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('missing-var-test')
            ->description('Test missing context variables')
            ->stage('stage', Tasks::agent('coder')->prompt('Hello {{unknown}}!'))
            ->build();

        $this->registry->register($workflow);

        $capturedContent = '';
        $this->mockExecutor
            ->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$capturedContent) {
                $capturedContent = $request->messages[0]['content'];
                return $this->successfulAgentResult('done');
            });

        $this->engine->run('missing-var-test', []);

        $this->assertStringContainsString('Hello {{unknown}}!', $capturedContent);
    }

    public function testStageFailureCausesWorkflowToFail(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('fail-test')
            ->description('Test workflow failure on stage failure')
            ->stage('success-stage', Tasks::agent('coder')->prompt('Succeed'))
            ->stage('failing-stage', Tasks::agent('coder')->prompt('Fail'))
            ->stage('never-run', Tasks::agent('coder')->prompt('Should not run'))
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return $this->successfulAgentResult('first ok');
                }
                return $this->failedAgentResult('Intentional failure');
            });

        $result = $this->engine->run('fail-test', []);

        $this->assertTrue($result->isFailure());
        $this->assertSame(WorkflowStatus::Failed, $result->status);
        $this->assertCount(2, $result->stageResults);
        // Third stage was never executed
        $this->assertNull($result->context['never-run.output'] ?? null);
    }

    public function testRunFromPhpLoadsWorkflowFromCallable(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('php-class-test')
            ->description('Test runFromPhp')
            ->stage('step', Tasks::agent('coder')->prompt('Run from PHP class'))
            ->build();

        $this->mockExecutor
            ->expects($this->once())
            ->method('execute')
            ->willReturn($this->successfulAgentResult('done'));

        $result = $this->engine->runFromPhp(static fn() => $workflow, []);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $result->stageResults);
    }

    public function testParallelStageExecutesTasksConcurrently(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('parallel-test')
            ->description('Test parallel stage executes tasks')
            ->maxConcurrent(5)
            ->parallel('parallel-stage', [
                Tasks::agent('coder')->prompt('Task 1'),
                Tasks::agent('coder')->prompt('Task 2'),
                Tasks::agent('coder')->prompt('Task 3'),
            ])
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult("output-{$callCount}");
            });

        $result = $this->engine->run('parallel-test', []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(1, $result->stageResults);
        $this->assertSame('parallel-stage', $result->stageResults[0]->stageName);
        $this->assertCount(3, $result->stageResults[0]->agents);
    }

    public function testParallelStageCollectsAllResults(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('parallel-collect-test')
            ->description('Test parallel stage collects all results')
            ->maxConcurrent(5)
            ->parallel('fan-out', [
                Tasks::agent('explorer')->prompt('Research auth'),
                Tasks::agent('explorer')->prompt('Research API'),
                Tasks::agent('explorer')->prompt('Research DB'),
            ])
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult("result-{$callCount}", 100 * $callCount, 0.01 * $callCount);
            });

        $result = $this->engine->run('parallel-collect-test', []);

        $this->assertTrue($result->isSuccess());
        $stageResult = $result->stageResults[0];
        $this->assertCount(3, $stageResult->agents);
        // All three outputs should be concatenated in the stage output
        $this->assertStringContainsString('result-1', $stageResult->output);
        $this->assertStringContainsString('result-2', $stageResult->output);
        $this->assertStringContainsString('result-3', $stageResult->output);
        // Token and cost sums
        $this->assertSame(600, $result->totalTokens);
        $this->assertSame(0.06, $result->totalCost);
    }

    public function testParallelStageFailFastWhenStopOnFirstFailure(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('parallel-failfast-test')
            ->description('Test parallel fail-fast behavior')
            ->maxConcurrent(5)
            ->parallel('fan-out', [
                Tasks::agent('coder')->prompt('Task 1'),
                Tasks::agent('coder')->prompt('Task 2'),
                Tasks::agent('coder')->prompt('Task 3'),
            ])
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    return $this->failedAgentResult('Intentional failure');
                }
                return $this->successfulAgentResult("ok-{$callCount}");
            });

        $result = $this->engine->run('parallel-failfast-test', []);

        $this->assertTrue($result->isFailure());
        $this->assertSame(WorkflowStatus::Failed, $result->status);
        $stageResult = $result->stageResults[0];
        $this->assertCount(3, $stageResult->agents);
        // Stage output should mention failure
        $this->assertNotNull($stageResult->error);
    }

    public function testWorkflowResultContainsCorrectFinalContext(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('context-test')
            ->description('Test final context')
            ->stage('first', Tasks::agent('coder')->prompt('First'))
            ->stage('second', Tasks::agent('coder')->prompt('Second'))
            ->build();

        $this->registry->register($workflow);

        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return $this->successfulAgentResult('output-value');
            });

        $result = $this->engine->run('context-test', ['initial' => 'value']);

        $this->assertSame('value', $result->context['initial']);
        $this->assertSame('output-value', $result->context['first.output']);
        $this->assertSame('output-value', $result->context['second.output']);
    }

    public function testEmptyWorkflowReturnsCompletedResult(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('empty-workflow')
            ->description('Workflow with no stages')
            ->build();

        $this->registry->register($workflow);

        $this->mockExecutor->expects($this->never())->method('execute');

        $result = $this->engine->run('empty-workflow', []);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(0, $result->stageResults);
    }

    public function testWorkflowWithInitialContextPassedThrough(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('init-context-test')
            ->description('Test initial context')
            ->stage('build', Tasks::agent('coder')->prompt('Build {{project_name}}'))
            ->build();

        $this->registry->register($workflow);

        $capturedContent = '';
        $this->mockExecutor
            ->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$capturedContent) {
                $capturedContent = $request->messages[0]['content'];
                return $this->successfulAgentResult('built');
            });

        $result = $this->engine->run('init-context-test', ['project_name' => 'my-app']);

        $this->assertStringContainsString('Build my-app', $capturedContent);
        $this->assertSame('my-app', $result->context['project_name']);
    }

    public function testPipelineStageChainsOutputs(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('pipeline-chains-test')
            ->description('Test pipeline chains outputs via prevResult')
            ->pipeline('process', [
                Tasks::agent('fetch')->prompt('Fetch data from {{input}}'),
                Tasks::agent('transform')->prompt('Transform: {{prevResult}}'),
            ])
            ->build();

        $this->registry->register($workflow);

        $capturedPrompts = [];
        $callCount = 0;
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$capturedPrompts, &$callCount) {
                $capturedPrompts[] = $request->messages[0]['content'];
                $callCount++;
                return $this->successfulAgentResult("output-{$callCount}");
            });

        $result = $this->engine->run('pipeline-chains-test', ['input' => 'dataset']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(1, $result->stageResults);
        $this->assertSame('process', $result->stageResults[0]->stageName);
        // First nested stage gets initial context
        $this->assertStringContainsString('Fetch data from dataset', $capturedPrompts[0]);
        // Second nested stage gets first output as prevResult
        $this->assertStringContainsString('Transform: output-1', $capturedPrompts[1]);
    }

    public function testPipelineWithMultipleStages(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('pipeline-multi-test')
            ->description('Test pipeline with 3 chained stages')
            ->pipeline('process', [
                Tasks::agent('fetch')->prompt('Fetch'),
                Tasks::agent('clean')->prompt('Clean: {{prevResult}}'),
                Tasks::agent('validate')->prompt('Validate: {{prevResult}}'),
                Tasks::agent('store')->prompt('Store: {{prevResult}}'),
            ])
            ->build();

        $this->registry->register($workflow);

        $capturedPrompts = [];
        $this->mockExecutor
            ->expects($this->exactly(4))
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$capturedPrompts) {
                $capturedPrompts[] = $request->messages[0]['content'];
                $resultNum = count($capturedPrompts);
                return $this->successfulAgentResult("result-{$resultNum}");
            });

        $result = $this->engine->run('pipeline-multi-test', []);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $result->stageResults);
        // Stage output should be all outputs concatenated
        $this->assertStringContainsString('result-1', $result->stageResults[0]->output);
        $this->assertStringContainsString('result-2', $result->stageResults[0]->output);
        $this->assertStringContainsString('result-3', $result->stageResults[0]->output);
        $this->assertStringContainsString('result-4', $result->stageResults[0]->output);
        // Verify chaining
        $this->assertStringContainsString('Clean: result-1', $capturedPrompts[1]);
        $this->assertStringContainsString('Validate: result-2', $capturedPrompts[2]);
        $this->assertStringContainsString('Store: result-3', $capturedPrompts[3]);
    }

    public function testContextInterpolationWithStageOutputs(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('stage-output-interp-test')
            ->description('Test stageName.output interpolation inside pipeline')
            ->pipeline('process', [
                Tasks::agent('fetch')->prompt('Fetch {{source}}'),
                Tasks::agent('report')->prompt('From fetch: {{fetch.output}}, From input: {{input}}'),
            ])
            ->build();

        $this->registry->register($workflow);

        $capturedPrompts = [];
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$capturedPrompts) {
                $capturedPrompts[] = $request->messages[0]['content'];
                return $this->successfulAgentResult('fetched-data');
            });

        $result = $this->engine->run('stage-output-interp-test', ['input' => 'user-query', 'source' => 'api']);

        $this->assertTrue($result->isSuccess());
        // First stage interpolates from initial context
        $this->assertStringContainsString('Fetch api', $capturedPrompts[0]);
        // Second stage interpolates {{fetch.output}} AND {{input}}
        $this->assertStringContainsString('From fetch: fetched-data', $capturedPrompts[1]);
        $this->assertStringContainsString('From input: user-query', $capturedPrompts[1]);
    }

    public function testVerificationStageRunsTaskThenVerifier(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('verification-order-test')
            ->description('Test verification stage runs task first, then verifier')
            ->withVerification(
                'verify-build',
                Tasks::agent('coder')->prompt('Build the feature'),
                Tasks::agent('reviewer')->prompt('Review: {{prevResult}}'),
            )
            ->build();

        $this->registry->register($workflow);

        $capturedPrompts = [];
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$capturedPrompts) {
                $capturedPrompts[] = $request->messages[0]['content'];
                return $this->successfulAgentResult('task-output');
            });

        $result = $this->engine->run('verification-order-test', []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(1, $result->stageResults);
        $this->assertSame('verify-build', $result->stageResults[0]->stageName);
        $this->assertCount(2, $result->stageResults[0]->agents);
        // First call should be the task prompt
        $this->assertStringContainsString('Build the feature', $capturedPrompts[0]);
        // Second call should be the verifier prompt with prevResult
        $this->assertStringContainsString('Review: task-output', $capturedPrompts[1]);
    }

    public function testVerificationStageFailsWhenVerifierReturnsFailure(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('verification-fail-test')
            ->description('Test verification stage fails when verifier returns failure')
            ->withVerification(
                'check-quality',
                Tasks::agent('coder')->prompt('Implement feature'),
                Tasks::agent('reviewer')->prompt('Find bugs'),
            )
            ->build();

        $this->registry->register($workflow);

        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function () {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    return $this->successfulAgentResult('implementation complete');
                }
                return $this->failedAgentResult('Verifier found critical bugs');
            });

        $result = $this->engine->run('verification-fail-test', []);

        $this->assertTrue($result->isFailure());
        $this->assertSame(WorkflowStatus::Failed, $result->status);
        $this->assertCount(1, $result->stageResults);
        $this->assertSame('check-quality', $result->stageResults[0]->stageName);
        $this->assertNotNull($result->stageResults[0]->error);
        $this->assertStringContainsString('Verifier found critical bugs', $result->stageResults[0]->error);
    }

    public function testVerificationStagePassesWhenVerifierPasses(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('verification-pass-test')
            ->description('Test verification stage passes when both task and verifier succeed')
            ->withVerification(
                'validate-output',
                Tasks::agent('coder')->prompt('Generate report'),
                Tasks::agent('reviewer')->prompt('Approve: {{prevResult}}'),
            )
            ->build();

        $this->registry->register($workflow);

        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function () {
                static $call = 0;
                $call++;
                return $this->successfulAgentResult("step-{$call}-output");
            });

        $result = $this->engine->run('verification-pass-test', []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(WorkflowStatus::Completed, $result->status);
        $this->assertCount(1, $result->stageResults);
        $this->assertNull($result->stageResults[0]->error);
        $this->assertStringContainsString('step-1-output', $result->stageResults[0]->output);
        $this->assertStringContainsString('step-2-output', $result->stageResults[0]->output);
    }
}
