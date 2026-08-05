<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * Executes workflows sequentially, stage by stage.
 *
 * Coordinates with AgentWorkerPool to run agent tasks for each stage,
 * collecting results into a WorkflowResult. Handles context interpolation
 * so that `{{variable}}` tokens in prompts are replaced with context values
 * and `{{stageName.output}}` tokens reference prior stage outputs.
 *
 * Mirrors charmbracelet/charmcrush WorkflowEngine implementation.
 */
final class WorkflowEngine
{
    public function __construct(
        private readonly WorkflowRegistry $registry = new WorkflowRegistry(),
        private readonly AgentWorkerPool $pool = new AgentWorkerPool(),
    ) {}

    /**
     * Load a workflow from the registry and execute it with the given context.
     *
     * @param string $workflowPath Workflow name (loaded from registry).
     * @param array  $context      Key-value pairs for {{variable}} interpolation.
     * @return WorkflowResult
     * @throws WorkflowNotFoundException When the workflow does not exist.
     * @throws WorkflowLoadException When the workflow cannot be loaded.
     */
    public function run(string $workflowPath, array $context = []): WorkflowResult
    {
        $workflow = $this->registry->load($workflowPath);
        return $this->runFromWorkflow($workflow, $context);
    }

    /**
     * Execute a workflow directly from a class name or callable that returns a Workflow.
     *
     * @param callable|string $workflowClass Fully-qualified class name or callable returning a Workflow.
     * @param array            $context       Key-value pairs for {{variable}} interpolation.
     * @return WorkflowResult
     * @throws WorkflowLoadException When the input cannot produce a Workflow.
     */
    public function runFromPhp(callable|string $workflowClass, array $context = []): WorkflowResult
    {
        // If it's callable (including anonymous functions), invoke it directly
        if (is_callable($workflowClass)) {
            $workflow = $workflowClass();
        } else {
            // Treat as a class name
            if (!class_exists($workflowClass)) {
                throw new WorkflowLoadException("Workflow class '{$workflowClass}' does not exist");
            }

            $workflow = $workflowClass;
            if (is_callable($workflow)) {
                $workflow = $workflow();
            }
        }

        if (!$workflow instanceof Workflow) {
            throw new WorkflowLoadException(
                "Workflow class must return a Workflow instance, got " . get_debug_type($workflow)
            );
        }

        return $this->runFromWorkflow($workflow, $context);
    }

    /**
     * Execute a Workflow value object with the given context.
     *
     * Stages are executed sequentially in definition order. For each stage:
     *   1. Interpolate the prompt using context and prior stage outputs.
     *   2. Call AgentWorkerPool to run the agent task.
     *   3. Collect the result into stageResults[].
     *   4. On failure: mark workflow Failed and stop processing further stages.
     *
     * Parallel and pipeline stages are not yet implemented (P4.S11-13).
     * They currently throw an UnsupportedStageTypeException.
     *
     * @param Workflow $workflow The workflow definition to execute.
     * @param array    $context  Key-value pairs for {{variable}} interpolation.
     * @return WorkflowResult
     */
    private function runFromWorkflow(Workflow $workflow, array $context): WorkflowResult
    {
        $startedAt = new \DateTimeImmutable();
        $stageResults = [];
        $totalTokens = 0;
        $totalCost = 0.0;

        // Clone context so we don't mutate the caller's array
        $context = [...$context];

        foreach ($workflow->stages as $stage) {
            $stageStartedAt = new \DateTimeImmutable();

            $stageType = $stage['type'] ?? '';

            if ($stageType === 'parallel') {
                try {
                    $stageResult = $this->executeParallelStage($stage, $context, $workflow->maxConcurrent);
                } catch (\Throwable $e) {
                    $stageResult = new StageResult(
                        stageName: $stage['name'] ?? 'unknown',
                        status: WorkflowStatus::Failed,
                        error: $e->getMessage(),
                        startedAt: $stageStartedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                }
            } elseif ($stageType === 'stage') {
                try {
                    $stageResult = $this->executeStage($stage, $context);
                } catch (\Throwable $e) {
                    $stageResult = new StageResult(
                        stageName: $stage['name'] ?? 'unknown',
                        status: WorkflowStatus::Failed,
                        error: $e->getMessage(),
                        startedAt: $stageStartedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                }
            } elseif ($stageType === 'pipeline') {
                try {
                    $stageResult = $this->executePipelineStage($stage, $context, $workflow->maxConcurrent);
                } catch (\Throwable $e) {
                    $stageResult = new StageResult(
                        stageName: $stage['name'] ?? 'unknown',
                        status: WorkflowStatus::Failed,
                        error: $e->getMessage(),
                        startedAt: $stageStartedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                }
            } elseif ($stageType === 'verification') {
                try {
                    $stageResult = $this->executeVerificationStage($stage, $context);
                } catch (\Throwable $e) {
                    $stageResult = new StageResult(
                        stageName: $stage['name'] ?? 'unknown',
                        status: WorkflowStatus::Failed,
                        error: $e->getMessage(),
                        startedAt: $stageStartedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                }
            } else {
                throw new UnsupportedStageTypeException(
                    "Stage type '{$stageType}' is not supported. Only 'stage', 'parallel', 'pipeline', and 'verification' are implemented."
                );
            }

            // Update context with this stage's output for downstream interpolation
            $context[$stageResult->stageName . '.output'] = $stageResult->output ?? '';

            $stageResults[] = $stageResult;
            $totalTokens += $this->sumTokens($stageResult);
            $totalCost += $this->sumCost($stageResult);

            // Fail fast: stop processing on first stage failure
            if ($stageResult->isFailure()) {
                return new WorkflowResult(
                    workflowId: $this->generateWorkflowId($workflow),
                    status: WorkflowStatus::Failed,
                    stageResults: $stageResults,
                    context: $context,
                    totalTokens: $totalTokens,
                    totalCost: $totalCost,
                    startedAt: $startedAt,
                    completedAt: new \DateTimeImmutable(),
                );
            }
        }

        return new WorkflowResult(
            workflowId: $this->generateWorkflowId($workflow),
            status: WorkflowStatus::Completed,
            stageResults: $stageResults,
            context: $context,
            totalTokens: $totalTokens,
            totalCost: $totalCost,
            startedAt: $startedAt,
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Execute a single 'stage' type stage and return its StageResult.
     *
     * Builds a SubAgent from the stage's task and calls AgentWorkerPool::executeOne().
     *
     * @param array $stage   Stage array from Workflow::$stages.
     * @param array $context Current workflow context for interpolation.
     * @return StageResult
     */
    private function executeStage(array $stage, array $context): StageResult
    {
        $stageName = $stage['name'] ?? 'unknown';
        $stageStartedAt = new \DateTimeImmutable();

        $tasks = $stage['tasks'] ?? [];
        if (empty($tasks)) {
            return new StageResult(
                stageName: $stageName,
                status: WorkflowStatus::Failed,
                error: "Stage '{$stageName}' has no tasks",
                startedAt: $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }

        // For now, execute only the first task (sequential within a stage is not yet implemented)
        /** @var WorkflowTask $task */
        $task = $tasks[0];

        // Interpolate prompt with context
        $interpolatedPrompt = $this->interpolateContext($task->prompt, $context);

        // Build SubAgent
        $agent = new Agent(
            name: $task->name ?? $task->agentType,
            description: $interpolatedPrompt,
            prompt: '', // system prompt is set via CompleteRequest
            model: 'claude-sonnet-4-6', // default model; could be configurable
            provider: 'anthropic',
            tools: $task->tools,
            skillNames: [],
            hooks: [],
            isActive: true,
        );

        $subAgent = new SubAgent(
            id: $stageName . '-' . uniqid(),
            agent: $agent,
            task: $interpolatedPrompt,
            timeout: $task->timeout ?? 300,
            maxRetries: $task->retries ?? 0,
            isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
        );

        // Build CompleteRequest
        $request = new CompleteRequest(
            model: $agent->model,
            messages: [
                ['role' => 'user', 'content' => $interpolatedPrompt],
            ],
            tools: $task->tools,
            systemPrompt: $agent->systemPrompt(),
        );

        // Execute via pool
        $agentResult = $this->pool->executeOne($subAgent, $request);

        return $this->buildStageResult($stageName, $agentResult, $stageStartedAt);
    }

    /**
     * Execute a 'pipeline' type stage and return its StageResult.
     *
     * Chains nested stages sequentially, passing each stage's output as
     * `{{prevResult}}` to the next stage. Each nested stage also gets
     * `{{stageName.output}}` available for context interpolation.
     *
     * @param array $stage          Stage array from Workflow::$stages.
     * @param array $context        Current workflow context for interpolation.
     * @param int   $maxConcurrent Maximum agents that may run concurrently.
     * @return StageResult
     */
    private function executePipelineStage(array $stage, array $context, int $maxConcurrent): StageResult
    {
        $stageName = $stage['name'] ?? 'unknown';
        $stageStartedAt = new \DateTimeImmutable();

        $nestedStages = $stage['stages'] ?? [];
        if (empty($nestedStages)) {
            return new StageResult(
                stageName: $stageName,
                status: WorkflowStatus::Failed,
                error: "Pipeline stage '{$stageName}' has no nested stages",
                startedAt: $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }

        $prevResult = '';
        $allOutputs = [];
        $allAgents = [];
        $pipelineContext = $context;
        $anyFailure = false;
        $firstStartedAt = null;
        $lastCompletedAt = null;

        foreach ($nestedStages as $nestedStage) {
            $nestedStageName = $nestedStage['name'] ?? 'unknown';
            $nestedStartedAt = new \DateTimeImmutable();

            // Inject {{prevResult}} from previous stage output into context
            $pipelineContext['prevResult'] = $prevResult;

            // Interpolate the nested stage's prompt(s) with current pipeline context
            $nestedTasks = $nestedStage['tasks'] ?? [];
            if (empty($nestedTasks)) {
                $anyFailure = true;
                break;
            }

            /** @var WorkflowTask $task */
            $task = $nestedTasks[0];
            $interpolatedPrompt = $this->interpolateContext($task->prompt, $pipelineContext);

            // Build SubAgent for this pipeline stage
            $agent = new Agent(
                name: $task->name ?? $task->agentType,
                description: $interpolatedPrompt,
                prompt: '',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: $task->tools,
                skillNames: [],
                hooks: [],
                isActive: true,
            );

            $subAgent = new SubAgent(
                id: $stageName . '-' . $nestedStageName . '-' . uniqid(),
                agent: $agent,
                task: $interpolatedPrompt,
                timeout: $task->timeout ?? 300,
                maxRetries: $task->retries ?? 0,
                isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
            );

            $request = new CompleteRequest(
                model: $agent->model,
                messages: [
                    ['role' => 'user', 'content' => $interpolatedPrompt],
                ],
                tools: $task->tools,
                systemPrompt: $agent->systemPrompt(),
            );

            $agentResult = $this->pool->executeOne($subAgent, $request);

            // Track timing
            if ($firstStartedAt === null) {
                $firstStartedAt = $agentResult->startedAt ?? $nestedStartedAt;
            }
            $lastCompletedAt = $agentResult->completedAt;

            // Update pipeline context with this nested stage's output
            $prevResult = $agentResult->output ?? '';
            $pipelineContext[$nestedStageName . '.output'] = $prevResult;
            $allOutputs[] = $prevResult;
            $allAgents[] = $agentResult;

            // Fail fast: stop on first failure
            if ($agentResult->status === AgentStatus::Failed || $agentResult->status === AgentStatus::TimedOut) {
                $anyFailure = true;
                break;
            }
        }

        $status = $anyFailure ? WorkflowStatus::Failed : WorkflowStatus::Completed;

        return new StageResult(
            stageName: $stageName,
            status: $status,
            output: implode("\n", $allOutputs),
            error: $anyFailure ? ($allAgents[count($allAgents) - 1]?->error?->getMessage() ?? 'Pipeline stage failed') : null,
            agents: $allAgents,
            startedAt: $firstStartedAt ?? $stageStartedAt,
            completedAt: $lastCompletedAt ?? new \DateTimeImmutable(),
        );
    }

    /**
     * Execute a 'verification' type stage and return its StageResult.
     *
     * Runs the task first, then runs the verifier with the task's output
     * available as {{prevResult}}. If the verifier returns failure (or
     * the task itself fails), the entire stage is marked failed.
     *
     * @param array $stage   Stage array from Workflow::$stages.
     * @param array $context Current workflow context for interpolation.
     * @return StageResult
     */
    private function executeVerificationStage(array $stage, array $context): StageResult
    {
        $stageName = $stage['name'] ?? 'unknown';
        $stageStartedAt = new \DateTimeImmutable();

        $task = $stage['task'] ?? null;
        $verifier = $stage['verifier'] ?? null;

        if (!$task instanceof WorkflowTask || !$verifier instanceof WorkflowTask) {
            return new StageResult(
                stageName: $stageName,
                status: WorkflowStatus::Failed,
                error: "Verification stage '{$stageName}' must have both a 'task' and a 'verifier' WorkflowTask",
                startedAt: $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }

        // --- Run the task ---
        $taskPrompt = $this->interpolateContext($task->prompt, $context);

        $taskAgent = new Agent(
            name: $task->name ?? $task->agentType,
            description: $taskPrompt,
            prompt: '',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: $task->tools,
            skillNames: [],
            hooks: [],
            isActive: true,
        );

        $taskSubAgent = new SubAgent(
            id: $stageName . '-task-' . uniqid(),
            agent: $taskAgent,
            task: $taskPrompt,
            timeout: $task->timeout ?? 300,
            maxRetries: $task->retries ?? 0,
            isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
        );

        $taskRequest = new CompleteRequest(
            model: $taskAgent->model,
            messages: [['role' => 'user', 'content' => $taskPrompt]],
            tools: $task->tools,
            systemPrompt: $taskAgent->systemPrompt(),
        );

        $taskResult = $this->pool->executeOne($taskSubAgent, $taskRequest);

        // If task itself fails, the whole stage fails immediately
        if ($taskResult->status === AgentStatus::Failed || $taskResult->status === AgentStatus::TimedOut) {
            return $this->buildStageResult($stageName, $taskResult, $stageStartedAt);
        }

        // --- Run the verifier, injecting task output as {{prevResult}} ---
        $verifierContext = $context;
        $verifierContext['prevResult'] = $taskResult->output ?? '';

        $verifierPrompt = $this->interpolateContext($verifier->prompt, $verifierContext);

        $verifierAgent = new Agent(
            name: $verifier->name ?? $verifier->agentType,
            description: $verifierPrompt,
            prompt: '',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: $verifier->tools,
            skillNames: [],
            hooks: [],
            isActive: true,
        );

        $verifierSubAgent = new SubAgent(
            id: $stageName . '-verifier-' . uniqid(),
            agent: $verifierAgent,
            task: $verifierPrompt,
            timeout: $verifier->timeout ?? 300,
            maxRetries: $verifier->retries ?? 0,
            isolation: $verifier->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
        );

        $verifierRequest = new CompleteRequest(
            model: $verifierAgent->model,
            messages: [['role' => 'user', 'content' => $verifierPrompt]],
            tools: $verifier->tools,
            systemPrompt: $verifierAgent->systemPrompt(),
        );

        $verifierResult = $this->pool->executeOne($verifierSubAgent, $verifierRequest);

        // Verifier failure marks the whole stage as failed
        if ($verifierResult->status === AgentStatus::Failed || $verifierResult->status === AgentStatus::TimedOut) {
            return $this->buildStageResult($stageName, $verifierResult, $stageStartedAt);
        }

        // Both succeeded — return combined output
        $combinedOutput = ($taskResult->output ?? '') . "\n" . ($verifierResult->output ?? '');
        $allAgents = [$taskResult, $verifierResult];

        return new StageResult(
            stageName: $stageName,
            status: WorkflowStatus::Completed,
            output: trim($combinedOutput),
            error: null,
            agents: $allAgents,
            startedAt: $taskResult->startedAt ?? $stageStartedAt,
            completedAt: $verifierResult->completedAt ?? new \DateTimeImmutable(),
        );
    }

    /**
     * Execute a 'parallel' type stage and return its StageResult.
     *
     * Builds SubAgents from all tasks in the stage, then runs them concurrently
     * via AgentWorkerPool::executeAll(). Respects the workflow's maxConcurrent
     * setting to control how many agents run at once.
     *
     * @param array $stage          Stage array from Workflow::$stages.
     * @param array $context        Current workflow context for interpolation.
     * @param int   $maxConcurrent Maximum agents that may run concurrently.
     * @return StageResult
     */
    private function executeParallelStage(array $stage, array $context, int $maxConcurrent): StageResult
    {
        $stageName = $stage['name'] ?? 'unknown';
        $stageStartedAt = new \DateTimeImmutable();

        $tasks = $stage['tasks'] ?? [];
        if (empty($tasks)) {
            return new StageResult(
                stageName: $stageName,
                status: WorkflowStatus::Failed,
                error: "Stage '{$stageName}' has no tasks",
                startedAt: $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }

        // Build a SubAgent for each task, all sharing the same CompleteRequest
        // (the request is task-specific for messages/content, but tools/systemPrompt
        // come from the first task's agent config to match sequential stage behavior).
        /** @var WorkflowTask $firstTask */
        $firstTask = $tasks[0];
        $firstInterpolated = $this->interpolateContext($firstTask->prompt, $context);

        $firstAgent = new Agent(
            name: $firstTask->name ?? $firstTask->agentType,
            description: $firstInterpolated,
            prompt: '',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: $firstTask->tools,
            skillNames: [],
            hooks: [],
            isActive: true,
        );

        $defaultRequest = new CompleteRequest(
            model: $firstAgent->model,
            messages: [
                ['role' => 'user', 'content' => $firstInterpolated],
            ],
            tools: $firstTask->tools,
            systemPrompt: $firstAgent->systemPrompt(),
        );

        $subAgents = [];
        $agentIndex = 0;
        foreach ($tasks as $task) {
            /** @var WorkflowTask $task */
            $agentIndex++;
            $interpolatedPrompt = $this->interpolateContext($task->prompt, $context);

            $agent = new Agent(
                name: $task->name ?? $task->agentType,
                description: $interpolatedPrompt,
                prompt: '',
                model: 'claude-sonnet-4-6',
                provider: 'anthropic',
                tools: $task->tools,
                skillNames: [],
                hooks: [],
                isActive: true,
            );

            $subAgents[] = new SubAgent(
                id: $stageName . '-' . $agentIndex . '-' . uniqid(),
                agent: $agent,
                task: $interpolatedPrompt,
                timeout: $task->timeout ?? 300,
                maxRetries: $task->retries ?? 0,
                isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
            );
        }

        // Use the injected pool for parallel execution; its maxConcurrent (default 5)
        // handles the concurrency limit for the parallel tasks.
        // TODO: Wire workflow->maxConcurrent to pool.maxConcurrent when pool exposes a setter
        $pool = $this->pool;

        // Collect all results from the generator
        $agentResults = [];
        foreach ($pool->executeAll($subAgents, $defaultRequest) as $agentResult) {
            $agentResults[] = $agentResult;
        }

        // Build StageResult from all agent results
        $anyFailure = false;
        $allOutputs = [];
        foreach ($agentResults as $ar) {
            if ($ar->status === AgentStatus::Failed || $ar->status === AgentStatus::TimedOut) {
                $anyFailure = true;
            }
            $allOutputs[] = $ar->output ?? '';
        }

        $status = $anyFailure ? WorkflowStatus::Failed : WorkflowStatus::Completed;
        $firstResult = $agentResults[0] ?? null;

        return new StageResult(
            stageName: $stageName,
            status: $status,
            output: implode("\n", $allOutputs),
            error: $anyFailure ? ($firstResult?->error?->getMessage() ?? 'One or more parallel tasks failed') : null,
            agents: $agentResults,
            startedAt: $firstResult?->startedAt ?? $stageStartedAt,
            completedAt: $firstResult?->completedAt ?? new \DateTimeImmutable(),
        );
    }

    /**
     * Interpolate {{variable}} and {{stageName.output}} tokens in a string.
     *
     * - `{{variable}}` is replaced with $context['variable'] if set, otherwise left as-is.
     * - `{{stageName.output}}` is replaced with the output of a prior stage result.
     *
     * @param string $text    The text containing interpolation tokens.
     * @param array  $context Current workflow context.
     * @return string Interpolated text.
     */
    private function interpolateContext(string $text, array $context): string
    {
        // Replace {{stageName.output}} references first (they contain dots)
        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\.output\}\}/',
            static function (array $matches) use ($context): string {
                $key = $matches[1] . '.output';
                return $context[$key] ?? $matches[0];
            },
            $text
        );

        // Replace simple {{variable}} references
        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/',
            static function (array $matches) use ($context): string {
                return $context[$matches[1]] ?? $matches[0];
            },
            $text
        );

        return $text;
    }

    /**
     * Build a StageResult from an AgentResult.
     */
    private function buildStageResult(string $stageName, AgentResult $agentResult, \DateTimeImmutable $startedAt): StageResult
    {
        $status = match ($agentResult->status) {
            AgentStatus::Completed => WorkflowStatus::Completed,
            AgentStatus::Failed, AgentStatus::Stopped, AgentStatus::TimedOut => WorkflowStatus::Failed,
            default => WorkflowStatus::Running,
        };

        return new StageResult(
            stageName: $stageName,
            status: $status,
            output: $agentResult->output,
            error: $agentResult->error?->getMessage(),
            agents: [$agentResult],
            startedAt: $agentResult->startedAt ?? $startedAt,
            completedAt: $agentResult->completedAt,
        );
    }

    /**
     * Sum tokens used by all agents in a stage.
     */
    private function sumTokens(StageResult $stage): int
    {
        $total = 0;
        foreach ($stage->agents as $agent) {
            $total += $agent->tokensUsed;
        }
        return $total;
    }

    /**
     * Sum cost incurred by all agents in a stage.
     */
    private function sumCost(StageResult $stage): float
    {
        $total = 0.0;
        foreach ($stage->agents as $agent) {
            $total += $agent->costUsd;
        }
        return $total;
    }

    /**
     * Generate a unique workflow ID.
     */
    private function generateWorkflowId(Workflow $workflow): string
    {
        return $workflow->name . '-' . substr(md5((string) mt_rand()), 0, 8);
    }
}
