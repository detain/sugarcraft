<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Workflows\TaskBuilder;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowTask;

final class TasksTest extends TestCase
{
    public function testAgentReturnsTaskBuilderWithAgentTypeSet(): void
    {
        $builder = Tasks::agent('architect');

        $this->assertInstanceOf(TaskBuilder::class, $builder);

        $task = $builder->prompt('Design the system')->build();

        $this->assertSame('architect', $task->agentType);
        $this->assertSame('Design the system', $task->prompt);
    }

    public function testAgentWithNamePreSetsNameOnBuilder(): void
    {
        $builder = Tasks::agent('coder', 'implement-api');

        $task = $builder->prompt('Implement the API endpoint')->build();

        $this->assertSame('coder', $task->agentType);
        $this->assertSame('implement-api', $task->name);
        $this->assertSame('Implement the API endpoint', $task->prompt);
    }

    public function testAgentWithNullNameDoesNotSetName(): void
    {
        $builder = Tasks::agent('reviewer', null);

        $task = $builder->prompt('Review code')->build();

        $this->assertSame('reviewer', $task->agentType);
        $this->assertNull($task->name);
    }

    public function testAgentReturnsBuilderReadyForChaining(): void
    {
        $task = Tasks::agent('architect')
            ->prompt('Design the system')
            ->tools(['Read', 'Write', 'Bash'])
            ->timeout(120)
            ->retries(3)
            ->name('design-task')
            ->build();

        $this->assertSame('architect', $task->agentType);
        $this->assertSame('Design the system', $task->prompt);
        $this->assertSame(['Read', 'Write', 'Bash'], $task->tools);
        $this->assertSame(120, $task->timeout);
        $this->assertSame(3, $task->retries);
        $this->assertSame('design-task', $task->name);
    }

    public function testPromptReturnsTaskBuilderWithPromptSet(): void
    {
        $builder = Tasks::prompt('Analyze this code');

        $this->assertInstanceOf(TaskBuilder::class, $builder);

        $task = $builder->agent('reviewer')->build();

        $this->assertSame('reviewer', $task->agentType);
        $this->assertSame('Analyze this code', $task->prompt);
    }

    public function testPromptChainsWithAgentType(): void
    {
        $task = Tasks::prompt('Fix bugs')
            ->agent('coder')
            ->build();

        $this->assertSame('coder', $task->agentType);
        $this->assertSame('Fix bugs', $task->prompt);
    }

    public function testDslExampleFromDocumentation(): void
    {
        // Mirrors: Tasks::agent('architect')->prompt('...')->tools([...])
        $task = Tasks::agent('architect')
            ->prompt('Design the system architecture')
            ->tools(['Read', 'Write', 'Bash'])
            ->build();

        $this->assertInstanceOf(WorkflowTask::class, $task);
        $this->assertSame('architect', $task->agentType);
        $this->assertSame('Design the system architecture', $task->prompt);
        $this->assertSame(['Read', 'Write', 'Bash'], $task->tools);
    }
}
