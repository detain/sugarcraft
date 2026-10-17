<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

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
 * Each command method returns $this for chaining, and build() produces
 * the immutable Workflow value object. {@see withVerification()} is the
 * one true wither: house law reserves the `with*` prefix for copy-on-write
 * returns (AGENTS.md "Immutable + fluent"), and it is the only method here
 * so named — callers must use its return value, never the discarded-call
 * side effect the command methods offer.
 */
final class WorkflowBuilder
{
    private string $name = '';
    private string $description = '';
    /** @var array<int, array{name: string, type: string, tasks?: array<int, mixed>}> */
    private array $stages = [];
    private ?int $maxConcurrent = null;
    private int $timeout = 3600;
    private bool $stopOnFirstFailure = false;

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
     * Add a sequential stage: one task, or a list of tasks run one after
     * another (roadmap 4.10-1).
     *
     * In a multi-task stage each task after the first sees the previous one's
     * output as `{{prevResult}}`, the tasks share the stage's timeout, and the
     * first task that fails stops the stage — see
     * {@see WorkflowEngine} `executeStage()`. For tasks that should run at the
     * same time use {@see parallel()} instead.
     *
     * @param TaskBuilder|list<TaskBuilder> $tasks
     *
     * @throws \InvalidArgumentException When $tasks is an empty list or holds
     *         something other than TaskBuilders.
     */
    public function stage(string $name, TaskBuilder|array $tasks): self
    {
        $tasks = $tasks instanceof TaskBuilder ? [$tasks] : array_values($tasks);
        if ($tasks === []) {
            throw new \InvalidArgumentException("Stage '{$name}' needs at least one task");
        }

        $built = [];
        foreach ($tasks as $i => $task) {
            if (!$task instanceof TaskBuilder) {
                throw new \InvalidArgumentException(sprintf(
                    "Stage '%s' task #%d must be a TaskBuilder, got %s",
                    $name,
                    $i,
                    get_debug_type($task),
                ));
            }
            $built[] = $task->build();
        }

        $this->stages[] = [
            'name' => $name,
            'type' => 'stage',
            'tasks' => $built,
        ];

        return $this;
    }

    /**
     * Add a parallel stage containing multiple tasks that run concurrently.
     *
     * Part of the parallel() primitive implementation (P4.S11) which spans:
     *   - WorkflowEngine.php: executeParallelStage() orchestrates concurrent execution
     *   - WorkflowBuilder.php: parallel() registers the stage definition
     *   - Workflow.php: $stopOnFirstFailure property controls fail-fast
     *   - WorkflowRegistry.php: parseStages() recognizes parallel type stages
     *   - AgentWorkerPool.php: executeAll() runs agents concurrently
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
     * @param TaskBuilder[]|array[] $stages Each element is a TaskBuilder instance or a plain array
     *                                     with 'name', 'type', 'tasks' keys.
     */
    public function pipeline(string $name, array $stages): self
    {
        $nestedStageArrays = [];
        foreach ($stages as $index => $stage) {
            if ($stage instanceof TaskBuilder) {
                $workflowTask = $stage->build();
                // Use explicit task name, agentType, or generated index as the sub-stage name
                $subName = $workflowTask->name ?? $workflowTask->agentType ?? "step-{$index}";
                $nestedStageArrays[] = [
                    'name' => $subName,
                    'type' => 'stage',
                    'tasks' => [$workflowTask],
                ];
            } else {
                // Plain array (pre-built stage definition) — pass through as-is
                $nestedStageArrays[] = $stage;
            }
        }

        $this->stages[] = [
            'name' => $name,
            'type' => 'pipeline',
            'stages' => $nestedStageArrays,
        ];

        return $this;
    }

    /**
     * Set the maximum number of stages that may run concurrently. Null, the
     * default, sets no cap: every task of a parallel stage is dispatched at
     * once.
     */
    public function maxConcurrent(?int $n): self
    {
        $this->maxConcurrent = $n;
        return $this;
    }

    /**
     * Return a builder with a verification stage appended: a task runs, then
     * a verifier validates its result.
     *
     * The task executes first; if it succeeds the verifier runs to validate
     * the result. If the verifier returns failure, the entire stage fails.
     *
     * Copy-on-write per the house `with*` law (rv-php-8): the clone carries
     * the stages accumulated so far, the receiver is left untouched, so
     * composing two withers can never double-add. The clone is safe as a
     * shallow copy — every field is a scalar or a copy-on-write array.
     *
     * @param string      $name     Human-readable name for this stage.
     * @param TaskBuilder $task     The task to run and then verify.
     * @param TaskBuilder $verifier The verifier that checks the task output.
     */
    public function withVerification(string $name, TaskBuilder $task, TaskBuilder $verifier): self
    {
        $clone = clone $this;
        $clone->stages[] = [
            'name' => $name,
            'type' => 'verification',
            'task' => $task->build(),
            'verifier' => $verifier->build(),
        ];

        return $clone;
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
     * Configure whether a parallel stage stops immediately on the first agent failure.
     *
     * When true (fail-fast), all queued agents are cancelled as soon as any agent
     * fails. When false (wait-for-all), all agents complete before the stage is
     * marked failed.
     *
     * Implementation chain: WorkflowBuilder stores this in $stopOnFirstFailure,
     * which WorkflowEngine reads to call AgentWorkerPool::withStopOnFirstFailure(),
     * which sets an internal flag checked by executeAll() to cancel remaining agents.
     */
    public function stopOnFirstFailure(bool $stop): self
    {
        $this->stopOnFirstFailure = $stop;
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
            stopOnFirstFailure: $this->stopOnFirstFailure,
        );
    }
}
