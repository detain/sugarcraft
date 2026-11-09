<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\ExecutorInterface;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Workflows\Tasks;
use SugarCraft\Code\Workflows\WorkflowBuilder;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * C11 include-compose at the engine surface: a `uses:` stage runs the named
 * workflow as its body and settles the WHOLE inner run into exactly one outer
 * StageResult — the outer records the inner as one stage row labeled with the
 * outer stage's name, with the inner's detail riding the row's output text.
 *
 * The refusals ride the stage loop's existing catch, so a chain that cycles or
 * reaches past WorkflowEngine::MAX_USES_DEPTH reads as this stage's failure —
 * raw English, the full attempted chain spelled `a -> b -> c -> d`.
 *
 * The parse side of the arm (one body per stage, `with:` shape) is pinned by
 * {@see WorkflowYamlStageTypesTest}; the static cycle/depth walk by
 * {@see WorkflowLinterTest}. This file is the runtime contract.
 */
final class WorkflowIncludeComposeTest extends TestCase
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
            agentId: 'agent-' . uniqid((string) getmypid(), true),
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
            agentId: 'agent-' . uniqid((string) getmypid(), true),
            status: AgentStatus::Failed,
            output: 'failed',
            error: new \RuntimeException($error),
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    /** A registered one-stage worker workflow named $name with $prompt. */
    private function workerWorkflow(string $name, string $prompt): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name($name)
                ->description('worker ' . $name)
                ->stage('work', Tasks::agent('coder')->prompt($prompt))
                ->build()
        );
    }

    /** A registered include-compose workflow: its single stage uses $inner. */
    private function includeWorkflow(string $name, string $inner, array $with = [], string $stageName = 'body'): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name($name)
                ->description('includer ' . $name)
                ->uses($stageName, $inner, $with)
                ->build()
        );
    }

    public function testTheIncludedRunSettlesAsOneOuterStageRow(): void
    {
        $this->workerWorkflow('inner', 'do it');
        $this->includeWorkflow('outer', 'inner');

        $this->mockExecutor
            ->expects($this->once())
            ->method('execute')
            ->willReturn($this->successfulAgentResult('inner-out', 250, 0.05));

        $result = $this->engine->run('outer', []);

        // One row, outer name, inner status visible in the output text.
        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $result->stageResults);
        $this->assertSame('body', $result->stageResults[0]->stageName);
        $this->assertSame(WorkflowStatus::Completed, $result->stageResults[0]->status);
        $this->assertSame('work: completed', $result->stageResults[0]->output);
        $this->assertNull($result->stageResults[0]->error);

        // The inner run's spend rides the outer row — nothing under-bills.
        $this->assertSame(250, $result->totalTokens);
        $this->assertSame(0.05, $result->totalCost);
    }

    public function testEveryInnerStageStillCostsTheOuterExactlyOneRow(): void
    {
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('busy')
                ->description('two-stage inner')
                ->stage('first', Tasks::agent('coder')->prompt('one'))
                ->stage('second', Tasks::agent('coder')->prompt('two'))
                ->build()
        );
        $this->includeWorkflow('calm', 'busy');

        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnOnConsecutiveCalls(
                $this->successfulAgentResult('a', 100, 0.01),
                $this->successfulAgentResult('b', 100, 0.01),
            );

        $result = $this->engine->run('calm', []);

        $this->assertCount(1, $result->stageResults);
        $this->assertSame("first: completed\nsecond: completed", $result->stageResults[0]->output);
        $this->assertSame(200, $result->totalTokens);
    }

    public function testWithValuesInterpolateInOuterScopeAndBecomeTheInnerStartContext(): void
    {
        $this->workerWorkflow('essay', 'Write about {{topic}}.');
        $this->includeWorkflow('host', 'essay', ['topic' => '{{seed}}']);

        $captured = '';
        $this->mockExecutor
            ->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$captured) {
                $captured = $request->messages[0]['content'];

                return $this->successfulAgentResult('ok');
            });

        $result = $this->engine->run('host', ['seed' => 'terminals']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('Write about terminals.', $captured);
    }

    public function testWithValuesReadTheOuterSettledStageOutput(): void
    {
        $this->workerWorkflow('echo', 'say {{topic}}');
        $this->registry->register(
            (new WorkflowBuilder())
                ->name('reader')
                ->description('settles a stage, then includes')
                ->stage('pre', Tasks::agent('coder')->prompt('fetch'))
                ->uses('body', 'echo', ['topic' => '{{pre.output}}'])
                ->build()
        );

        $captured = '';
        $this->mockExecutor
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (SubAgent $agent, CompleteRequest $request) use (&$captured) {
                $captured = $request->messages[0]['content'];

                // The outer stage settles first; the inner agent's settled
                // output is what the with: binding must have read.
                return $captured === 'fetch'
                    ? $this->successfulAgentResult('OUT1')
                    : $this->successfulAgentResult('ok');
            });

        $result = $this->engine->run('reader', []);

        // Non-vacuous by shape: a `with:` interpolated in the INNER scope
        // would leave the literal {{pre.output}} template in the prompt.
        $this->assertTrue($result->isSuccess());
        $this->assertSame('say OUT1', $captured);
    }

    public function testAFourthIncludeNodeRefusesWithTheFullChainInEnglish(): void
    {
        $this->workerWorkflow('d', 'deepest');
        $this->includeWorkflow('c', 'd');
        $this->includeWorkflow('b', 'c');
        $this->includeWorkflow('a', 'b');

        // Nothing dispatches: the refusal lands before d's agents run.
        $this->mockExecutor->expects($this->never())->method('execute');

        $result = $this->engine->run('a', []);

        $this->assertSame(WorkflowStatus::Failed, $result->status);
        $this->assertCount(1, $result->stageResults);
        $this->assertSame('body', $result->stageResults[0]->stageName);

        $error = (string) $result->stageResults[0]->error;
        // The deepest legal host (c) refuses its own include; the fold nests
        // the raw-English message upward, so the chain reaches the top row.
        $this->assertStringContainsString('Include chain too deep: a -> b -> c -> d (maximum depth is 3).', $error);
        $this->assertStringContainsString('included workflow "c" failed', $error);
    }

    public function testASelfIncludeRefusesAsACycleWithItsChain(): void
    {
        $this->includeWorkflow('ouroboros', 'ouroboros');

        $this->mockExecutor->expects($this->never())->method('execute');

        $result = $this->engine->run('ouroboros', []);

        $this->assertSame(WorkflowStatus::Failed, $result->status);
        $this->assertSame(
            'Workflow "ouroboros" is already in this run\'s uses chain (ouroboros -> ouroboros); includes must not cycle.',
            $result->stageResults[0]->error,
        );
    }

    public function testAnInnerFailureSurfacesAsTheOuterRowCarryingInnerDetail(): void
    {
        $this->workerWorkflow('fragile', 'do it');
        $this->includeWorkflow('carrier', 'fragile');

        $this->mockExecutor
            ->expects($this->once())
            ->method('execute')
            ->willReturn($this->failedAgentResult('boom'));

        $result = $this->engine->run('carrier', []);

        $this->assertSame(WorkflowStatus::Failed, $result->status);
        $this->assertCount(1, $result->stageResults);
        $this->assertSame('work: failed', $result->stageResults[0]->output);
        $this->assertSame('included workflow "fragile" failed: boom', $result->stageResults[0]->error);

        // The failed inner agent still rides the outer row — the shape the
        // totals read (a Completed-case bill-carry is pinned separately).
        $this->assertCount(1, $result->stageResults[0]->agents);
        $this->assertSame(AgentStatus::Failed, $result->stageResults[0]->agents[0]->status);
    }

    public function testTheCacheMaterialForAnIncludeIsItsDeclarationNotItsInterior(): void
    {
        $hash = new \ReflectionMethod(WorkflowEngine::class, 'stageInputHash');
        $hash->setAccessible(true);

        $declaration = ['name' => 'body', 'type' => 'stage', 'uses' => 'inner', 'with' => ['topic' => '{{seed}}']];
        $same = ['name' => 'body', 'type' => 'stage', 'uses' => 'inner', 'with' => ['topic' => '{{seed}}']];
        $movedWith = ['name' => 'body', 'type' => 'stage', 'uses' => 'inner', 'with' => ['topic' => 'other']];
        $movedName = ['name' => 'body', 'type' => 'stage', 'uses' => 'other-workflow'];
        $plain = ['name' => 'body', 'type' => 'stage', 'tasks' => []];

        $of = static fn (array $stage): string => $hash->invoke(null, $stage, (string) $stage['type']);

        // Same declaration -> same identity; either half moving -> different.
        $this->assertSame($of($declaration), $of($same));
        $this->assertNotSame($of($declaration), $of($movedWith));
        $this->assertNotSame($of($declaration), $of($movedName));

        // And an include never hashes identical to the empty ordinary stage:
        // the uses/with material really joined the recipe.
        $this->assertNotSame($of($declaration), $of($plain));
    }
}
