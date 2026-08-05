<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Fluent builder for assembling a Workflow value object.
 *
 * Mirrors the DSL usage seen in workflow definitions:
 *   $b->name('refactor-service')
 *      ->description('Refactor a microservice with tests and docs')
 *      ->stage('analyze', Tasks::agent('architect')->prompt('...'))
 *      ->parallel('implement', [Tasks::agent('coder'), Tasks::agent('tester')])
 *      ->maxConcurrent(5)
 *      ->timeout(3600)
 *      ->build();
 *
 * Each method returns $this for chaining, and build() produces
 * the immutable Workflow value object.
 */
final class WorkflowBuilder
{
    private string $name = '';
    private string $description = '';
    /** @var array<int, array{name: string, type: string, tasks?: array<int, mixed>}> */
    private array $stages = [];
    private int $maxConcurrent = 5;
    private int $timeout = 3600;

    /**
     * Set the human-readable name of the workflow.
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set the brief description of what the workflow does.
     */
    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Add a sequential stage with a single task.
     */
    public function stage(string $name, TaskBuilder $task): self
    {
        $this->stages[] = [
            'name' => $name,
            'type' => 'stage',
            'tasks' => [$task->build()],
        ];

        return $this;
    }

    /**
     * Add a parallel stage containing multiple tasks that run concurrently.
     *
     * @param TaskBuilder[] $tasks
     */
    public function parallel(string $name, array $tasks): self
    {
        $builtTasks = array_map(
            static fn(TaskBuilder $t) => $t->build(),
            $tasks,
        );

        $this->stages[] = [
            'name' => $name,
            'type' => 'parallel',
            'tasks' => $builtTasks,
        ];

        return $this;
    }

    /**
     * Add a pipeline stage containing nested stages.
     *
     * @param array<int, array{name: string, type: string, tasks?: array<int, mixed>}> $stages
     */
    public function pipeline(string $name, array $stages): self
    {
        $this->stages[] = [
            'name' => $name,
            'type' => 'pipeline',
            'stages' => $stages,
        ];

        return $this;
    }

    /**
     * Set the maximum number of stages that may run concurrently.
     */
    public function maxConcurrent(int $n): self
    {
        $this->maxConcurrent = $n;
        return $this;
    }

    /**
     * Set the per-stage timeout in seconds.
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    /**
     * Assemble and return the immutable Workflow value object.
     */
    public function build(): Workflow
    {
        return new Workflow(
            name: $this->name,
            description: $this->description,
            stages: $this->stages,
            maxConcurrent: $this->maxConcurrent,
            timeout: $this->timeout,
        );
    }
}
