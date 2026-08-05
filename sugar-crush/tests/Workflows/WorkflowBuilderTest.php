<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Workflows\TaskBuilder;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\Workflow;
use SugarCraft\Crush\Workflows\WorkflowBuilder;

final class WorkflowBuilderTest extends TestCase
{
    public function testBuildReturnsWorkflowWithNameAndDescription(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('refactor-service')
            ->description('Refactor a microservice with tests and docs')
            ->build();

        $this->assertInstanceOf(Workflow::class, $workflow);
        $this->assertSame('refactor-service', $workflow->name);
        $this->assertSame('Refactor a microservice with tests and docs', $workflow->description);
    }

    public function testBuildReturnsWorkflowWithDefaultConcurrencyAndTimeout(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('test-workflow')
            ->build();

        $this->assertSame(5, $workflow->maxConcurrent);
        $this->assertSame(3600, $workflow->timeout);
    }

    public function testFluentInterfaceReturnsSameBuilder(): void
    {
        $builder = new WorkflowBuilder();

        $this->assertSame($builder, $builder->name('test'));
        $this->assertSame($builder, $builder->description('desc'));
        $this->assertSame($builder, $builder->maxConcurrent(3));
        $this->assertSame($builder, $builder->timeout(1800));
    }

    public function testStageAddsSequentialStageToWorkflow(): void
    {
        $task = Tasks::agent('architect')
            ->prompt('Analyze the codebase');

        $workflow = (new WorkflowBuilder())
            ->name('analyze-workflow')
            ->stage('analyze', $task)
            ->build();

        $this->assertCount(1, $workflow->stages);
        $this->assertSame('analyze', $workflow->stages[0]['name']);
        $this->assertSame('stage', $workflow->stages[0]['type']);
        $this->assertCount(1, $workflow->stages[0]['tasks']);
        $this->assertSame('architect', $workflow->stages[0]['tasks'][0]->agentType);
    }

    public function testMultipleStagesArePreservedInOrder(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('multi-stage')
            ->stage('analyze', Tasks::agent('architect')->prompt('Analyze'))
            ->stage('implement', Tasks::agent('coder')->prompt('Implement'))
            ->stage('verify', Tasks::agent('reviewer')->prompt('Verify'))
            ->build();

        $this->assertCount(3, $workflow->stages);
        $this->assertSame('analyze', $workflow->stages[0]['name']);
        $this->assertSame('implement', $workflow->stages[1]['name']);
        $this->assertSame('verify', $workflow->stages[2]['name']);
    }

    public function testParallelAddsParallelStageWithMultipleTasks(): void
    {
        $taskA = Tasks::agent('coder')->prompt('Implement API');
        $taskB = Tasks::agent('tester')->prompt('Write tests');

        $workflow = (new WorkflowBuilder())
            ->name('parallel-workflow')
            ->parallel('implement', [$taskA, $taskB])
            ->build();

        $this->assertCount(1, $workflow->stages);
        $this->assertSame('implement', $workflow->stages[0]['name']);
        $this->assertSame('parallel', $workflow->stages[0]['type']);
        $this->assertCount(2, $workflow->stages[0]['tasks']);
        $this->assertSame('coder', $workflow->stages[0]['tasks'][0]->agentType);
        $this->assertSame('tester', $workflow->stages[0]['tasks'][1]->agentType);
    }

    public function testParallelWithSingleTask(): void
    {
        $task = Tasks::agent('coder')->prompt('Implement feature');

        $workflow = (new WorkflowBuilder())
            ->name('single-parallel')
            ->parallel('feature', [$task])
            ->build();

        $this->assertCount(1, $workflow->stages);
        $this->assertSame('parallel', $workflow->stages[0]['type']);
        $this->assertCount(1, $workflow->stages[0]['tasks']);
    }

    public function testPipelineAddsNestedStages(): void
    {
        $innerStages = [
            ['name' => 'step-a', 'type' => 'stage', 'tasks' => []],
            ['name' => 'step-b', 'type' => 'stage', 'tasks' => []],
        ];

        $workflow = (new WorkflowBuilder())
            ->name('pipeline-workflow')
            ->pipeline('sub-pipeline', $innerStages)
            ->build();

        $this->assertCount(1, $workflow->stages);
        $this->assertSame('sub-pipeline', $workflow->stages[0]['name']);
        $this->assertSame('pipeline', $workflow->stages[0]['type']);
        $this->assertCount(2, $workflow->stages[0]['stages']);
        $this->assertSame('step-a', $workflow->stages[0]['stages'][0]['name']);
    }

    public function testMaxConcurrentOverridesDefault(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('concurrent-test')
            ->maxConcurrent(10)
            ->build();

        $this->assertSame(10, $workflow->maxConcurrent);
    }

    public function testTimeoutOverridesDefault(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('timeout-test')
            ->timeout(7200)
            ->build();

        $this->assertSame(7200, $workflow->timeout);
    }

    public function testMixedStagesAndParallel(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('mixed-workflow')
            ->stage('analyze', Tasks::agent('architect')->prompt('Analyze'))
            ->parallel('implement', [
                Tasks::agent('api-coder')->prompt('Implement API'),
                Tasks::agent('test-coder')->prompt('Write tests'),
            ])
            ->stage('review', Tasks::agent('reviewer')->prompt('Review'))
            ->build();

        $this->assertCount(3, $workflow->stages);
        $this->assertSame('stage', $workflow->stages[0]['type']);
        $this->assertSame('parallel', $workflow->stages[1]['type']);
        $this->assertSame('stage', $workflow->stages[2]['type']);
    }

    public function testBuildProducesWorkflowWithEmptyStagesByDefault(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('empty-workflow')
            ->build();

        $this->assertSame([], $workflow->stages);
    }

    public function testStageAndParallelTasksAreBuilt(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('built-task-test')
            ->stage('analyze', Tasks::agent('architect')
                ->prompt('Analyze the system')
                ->tools(['Read', 'Glob'])
                ->timeout(300))
            ->parallel('implement', [
                Tasks::agent('backend')->prompt('Backend'),
                Tasks::agent('frontend')->prompt('Frontend'),
            ])
            ->build();

        // Stage task should be a WorkflowTask instance (built from TaskBuilder)
        $this->assertSame('Analyze the system', $workflow->stages[0]['tasks'][0]->prompt);
        $this->assertSame(['Read', 'Glob'], $workflow->stages[0]['tasks'][0]->tools);
        $this->assertSame(300, $workflow->stages[0]['tasks'][0]->timeout);

        // Parallel tasks should also be WorkflowTask instances
        $this->assertSame('Backend', $workflow->stages[1]['tasks'][0]->prompt);
        $this->assertSame('Frontend', $workflow->stages[1]['tasks'][1]->prompt);
    }

    public function testChainedMethodsApplyInOrder(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('chained')
            ->description('Chained workflow')
            ->stage('step1', Tasks::agent('a')->prompt('p1'))
            ->maxConcurrent(3)
            ->stage('step2', Tasks::agent('b')->prompt('p2'))
            ->timeout(900)
            ->parallel('para', [Tasks::agent('c')->prompt('p3')])
            ->build();

        $this->assertSame('chained', $workflow->name);
        $this->assertSame('Chained workflow', $workflow->description);
        $this->assertSame(3, $workflow->maxConcurrent);
        $this->assertSame(900, $workflow->timeout);
        $this->assertCount(3, $workflow->stages);
    }
}
