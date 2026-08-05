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
     * Add a pipeline stage containing nested stages that chain output to input.
     *
     * Each nested stage receives `{{prevResult}}` interpolated with the previous
     * stage's output string, enabling sequential transformation pipelines.
     *
     * @param TaskBuilder[] $stages
     */
    public function pipeline(string $name, array $stages): self
    {
        $nestedStageArrays = [];
        foreach ($stages as $index => $taskBuilder) {
            /** @var TaskBuilder $taskBuilder */
            $workflowTask = $taskBuilder->build();
            // Use explicit task name, agentType, or generated index as the sub-stage name
            $subName = $workflowTask->name ?? $workflowTask->agentType ?? "step-{$index}";
            $nestedStageArrays[] = [
                'name' => $subName,
                'type' => 'stage',
                'tasks' => [$workflowTask],
            ];
        }

        $this->stages[] = [
            'name' => $name,
            'type' => 'pipeline',
            'stages' => $nestedStageArrays,
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
     * Add a verification stage that runs a task then a verifier.
     *
     * The task executes first; if it succeeds the verifier runs to validate
     * the result. If the verifier returns failure, the entire stage fails.
     *
     * @param string      $name     Human-readable name for this stage.
     * @param TaskBuilder $task     The task to run and then verify.
     * @param TaskBuilder $verifier The verifier that checks the task output.
     */
    public function withVerification(string $name, TaskBuilder $task, TaskBuilder $verifier): self
    {
        $this->stages[] = [
            'name' => $name,
            'type' => 'verification',
            'task' => $task->build(),
            'verifier' => $verifier->build(),
        ];

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
