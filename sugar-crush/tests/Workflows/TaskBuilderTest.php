<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Isolation;
use SugarCraft\Crush\Workflows\TaskBuilder;
use SugarCraft\Crush\Workflows\WorkflowTask;

final class TaskBuilderTest extends TestCase
{
    public function testBuildReturnsWorkflowTaskWithAllFields(): void
    {
        $task = (new TaskBuilder())
            ->agent('architect')
            ->prompt('Design the system')
            ->tools(['Read', 'Write', 'Bash'])
            ->timeout(120)
            ->retries(3)
            ->isolation(Isolation::Worktree)
            ->name('design-task')
            ->build();

        $this->assertInstanceOf(WorkflowTask::class, $task);
        $this->assertSame('architect', $task->agentType);
        $this->assertSame('Design the system', $task->prompt);
        $this->assertSame(['Read', 'Write', 'Bash'], $task->tools);
        $this->assertSame(120, $task->timeout);
        $this->assertSame(3, $task->retries);
        $this->assertSame(Isolation::Worktree, $task->isolation);
        $this->assertSame('design-task', $task->name);
    }

    public function testFluentChainReturnsSameBuilderInstance(): void
    {
        $builder = new TaskBuilder();

        $this->assertSame($builder, $builder->agent('coder'));
        $this->assertSame($builder, $builder->prompt('Write code'));
        $this->assertSame($builder, $builder->tools(['Bash']));
        $this->assertSame($builder, $builder->timeout(60));
        $this->assertSame($builder, $builder->retries(2));
        $this->assertSame($builder, $builder->isolation(Isolation::None));
        $this->assertSame($builder, $builder->name('code-task'));
    }

    public function testOptionalFieldsAreNullByDefault(): void
    {
        $task = (new TaskBuilder())
            ->agent('explorer')
            ->prompt('Explore codebase')
            ->build();

        $this->assertSame('explorer', $task->agentType);
        $this->assertSame('Explore codebase', $task->prompt);
        $this->assertSame([], $task->tools);
        $this->assertNull($task->timeout);
        $this->assertNull($task->retries);
        $this->assertNull($task->isolation);
        $this->assertNull($task->name);
    }

    public function testPartialFluentChain(): void
    {
        $task = (new TaskBuilder())
            ->agent('reviewer')
            ->prompt('Review security')
            ->tools(['Read'])
            ->timeout(90)
            ->build();

        $this->assertSame('reviewer', $task->agentType);
        $this->assertSame('Review security', $task->prompt);
        $this->assertSame(['Read'], $task->tools);
        $this->assertSame(90, $task->timeout);
        $this->assertNull($task->retries);
        $this->assertNull($task->isolation);
        $this->assertNull($task->name);
    }

    public function testIsolationNoneValue(): void
    {
        $task = (new TaskBuilder())
            ->agent('builder')
            ->prompt('Build it')
            ->isolation(Isolation::None)
            ->build();

        $this->assertSame(Isolation::None, $task->isolation);
    }

    public function testEmptyToolsArray(): void
    {
        $task = (new TaskBuilder())
            ->agent('tester')
            ->prompt('Run tests')
            ->tools([])
            ->build();

        $this->assertSame([], $task->tools);
    }
}
