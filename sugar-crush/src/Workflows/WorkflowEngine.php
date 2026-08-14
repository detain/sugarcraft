<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

use SugarCraft\Crush\Support\HomeDirectory;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentManager;
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
 * Real interrupts (R28): when the pcntl extension is available, run()/
 * resume() install SIGINT/SIGTERM handlers for the duration of the
 * stage-execution loop in runFromWorkflow(). A genuine Ctrl-C or
 * `kill -TERM` during that loop calls pause() with whatever stages have
 * actually completed so far, then exits — so a real interrupt captures
 * genuine partial progress instead of losing the whole run silently.
 *
 * Remaining limitation: resume granularity is per-whole-stage only. If
 * the interrupt lands while a 'parallel' stage is mid-flight (see
 * executeParallelStage()), that stage's individual in-progress agent
 * results are NOT captured — the stage as a whole is simply absent from
 * the pause file, and resuming re-runs it from scratch. There is no
 * partial-credit resume for a PARALLEL sub-stage.
 *
 * Fork-safety: pcntl_signal() handlers are inherited across pcntl_fork(),
 * so if the signal arrives while a 'parallel' stage's AgentWorkerPool has
 * live forked children, those children re-enter the handler too. See
 * installInterruptHandlers() for how this is guarded (only the process
 * that installed the handler ever calls pause(); forked children just
 * exit under the signal convention, same as their pre-fix behaviour).
 * installInterruptHandlers()/restoreInterruptHandlers() also restore
 * pcntl_async_signals() to whatever it was before run()/resume() was
 * called, rather than leaking async-dispatch mode into the rest of the
 * calling process once the run finishes.
 *
 * Mirrors charmbracelet/charmcrush WorkflowEngine implementation.
 */
final class WorkflowEngine implements WorkflowEngineInterface
{
    private const PAUSE_DIR = '.running';

    /** @var array<string, WorkflowResult> */
    private array $resultsByName = [];

    public function __construct(
        private readonly WorkflowRegistry $registry = new WorkflowRegistry(),
        private readonly AgentWorkerPool $pool = new AgentWorkerPool(),
        private ?AgentManager $agentManager = null,
    ) {}

    /**
     * Attach the AgentManager that parallel-stage sub-agents register with.
     *
     * Not a `with*()` wither: WorkflowEngine is a mutable service (it caches
     * results in $resultsByName), and the manager is injected late by Chat --
     * which receives both collaborators independently -- rather than at
     * construction. Mirrors {@see AgentManager::setTeamManager()}.
     */
    public function setAgentManager(AgentManager $agentManager): void
    {
        $this->agentManager = $agentManager;
    }

    /**
     * The AgentManager parallel stages route through, or null when none is set.
     */
    public function agentManager(): ?AgentManager
    {
        return $this->agentManager;
    }

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
        $result = $this->runFromWorkflow($workflow, $context, 0, null, $workflowPath);
        $this->resultsByName[$workflowPath] = $result;

        return $result;
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

        return $this->runFromWorkflow($workflow, $context, 0, null);
    }

    /**
     * Persist the current state of a workflow so it can be resumed later.
     *
     * Looks up the workflow result stored by run() (keyed by workflow name/path)
     * and writes a pause file to `~/.sugar-crush/workflows/.running/{$workflowId}.json`
     * containing: stages completed, context, stage results, token/cost totals, and timing.
     *
     * Called two ways: cooperatively (e.g. from the /workflow pause command,
     * after a run has already finished or been externally tracked), and by
     * installInterruptHandlers() (R28) when a real SIGINT/SIGTERM lands
     * mid-run — in that second case $workflowId is whatever completed
     * stages exist at the moment the signal arrived, not a finished run.
     * Either way this only ever persists whole StageResult entries: a
     * 'parallel' stage that was still in-flight when interrupted is not
     * present in $result->stageResults at all, so it is not reflected here
     * and will be re-run from scratch on resume() — there is no
     * partial-credit capture for an in-progress parallel sub-stage.
     *
     * @param string $workflowId The workflow name/path used when calling run().
     * @throws WorkflowNotRunningException When no result is found for this workflowId.
     */
    public function pause(string $workflowId): void
    {
        if (!isset($this->resultsByName[$workflowId])) {
            throw new WorkflowNotRunningException(
                "No result found for workflow '{$workflowId}'. Run the workflow first before pausing."
            );
        }

        $result = $this->resultsByName[$workflowId];
        $pauseFile = $this->getPauseFilePath($workflowId);

        $data = [
            'workflowId' => $workflowId,
            'workflowPath' => $workflowId,
            'status' => WorkflowStatus::Paused->value,
            'stagesCompleted' => count($result->stageResults),
            'context' => $result->context,
            'stageResults' => array_map(
                fn(StageResult $sr) => $this->serializeStageResult($sr),
                $result->stageResults,
            ),
            'totalTokens' => $result->totalTokens,
            'totalCost' => $result->totalCost,
            'startedAt' => $result->startedAt->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $dir = dirname($pauseFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($pauseFile, json_encode($data, JSON_PRETTY_PRINT) . "\n");
    }

    /**
     * Reload a paused workflow from its persisted state and continue execution.
     *
     * @param string $workflowId The unique workflow identifier.
     * @return WorkflowResult The final result after the resumed workflow completes.
     * @throws WorkflowNotRunningException When no pause file exists for this workflow.
     * @throws WorkflowNotFoundException   When the workflow definition can no longer be loaded.
     */
    public function resume(string $workflowId): WorkflowResult
    {
        $pauseFile = $this->getPauseFilePath($workflowId);

        if (!file_exists($pauseFile)) {
            throw new WorkflowNotRunningException(
                "No paused workflow found with ID '{$workflowId}'"
            );
        }

        $data = json_decode(file_get_contents($pauseFile), true);

        $workflowPath = $data['workflowPath'] ?? null;
        if ($workflowPath === null) {
            throw new WorkflowNotRunningException(
                "Pause file for '{$workflowId}' is corrupt: missing 'workflowPath' field"
            );
        }

        $workflow = $this->registry->load($workflowPath);

        return $this->runFromWorkflow(
            $workflow,
            $data['context'] ?? [],
            $data['stagesCompleted'] ?? 0,
            $workflowId,
            $workflowId,
        );
    }

    /**
     * List all available workflows from the registry.
     *
     * @return array<string> List of workflow names.
     */
    public function listWorkflows(): array
    {
        return $this->registry->list();
    }

    /**
     * Return the current status of a workflow from its persisted pause file.
     *
     * @param string $workflowId The unique workflow identifier.
     * @return WorkflowStatus The status stored in the pause file.
     * @throws WorkflowNotRunningException When no pause file exists for this workflow.
     */
    public function getStatus(string $workflowId): WorkflowStatus
    {
        $pauseFile = $this->getPauseFilePath($workflowId);

        if (!file_exists($pauseFile)) {
            throw new WorkflowNotRunningException(
                "No pause file found for workflow '{$workflowId}'"
            );
        }

        $data = json_decode(file_get_contents($pauseFile), true);
        $statusValue = $data['status'] ?? null;

        if ($statusValue === null) {
            throw new WorkflowNotRunningException(
                "Pause file for '{$workflowId}' is corrupt: missing 'status' field"
            );
        }

        try {
            return WorkflowStatus::from($statusValue);
        } catch (\ValueError) {
            throw new WorkflowNotRunningException(
                "Pause file for '{$workflowId}' is corrupt: invalid status value '{$statusValue}'"
            );
        }
    }

    /**
     * Build the filesystem path to a workflow's pause file.
     */
    private function getPauseFilePath(string $workflowId): string
    {
        if (str_contains($workflowId, '..') || str_contains($workflowId, '/')) {
            throw new \InvalidArgumentException('workflowId must not contain path separators or ..');
        }
        $home = HomeDirectory::path();

        return $home . '/.sugar-crush/workflows/' . self::PAUSE_DIR . '/' . $workflowId . '.json';
    }

    /**
     * Serialize a StageResult to a plain array for JSON storage in pause files.
     *
     * @return array<string, mixed>
     */
    private function serializeStageResult(StageResult $sr): array
    {
        return [
            'stageName' => $sr->stageName,
            'status' => $sr->status->value,
            'output' => $sr->output,
            'error' => $sr->error,
            'agents' => array_map(
                fn(AgentResult $ar) => [
                    'agentId' => $ar->agentId,
                    'status' => $ar->status->value,
                    'output' => $ar->output,
                    'error' => $ar->error?->getMessage(),
                    'tokensUsed' => $ar->tokensUsed,
                    'costUsd' => $ar->costUsd,
                    'startedAt' => $ar->startedAt?->format(\DateTimeInterface::ATOM),
                    'completedAt' => $ar->completedAt?->format(\DateTimeInterface::ATOM),
                ],
                $sr->agents,
            ),
            'startedAt' => $sr->startedAt->format(\DateTimeInterface::ATOM),
            'completedAt' => $sr->completedAt?->format(\DateTimeInterface::ATOM),
        ];
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
     * Parallel stages (P4.S11) and pipeline stages (P4.S12) are implemented.
     *
     * R28: for the duration of the loop below, SIGINT/SIGTERM handlers are
     * installed (when pcntl is available) so a real interrupt captures
     * whatever stages have actually finished via pause() before the
     * process exits — see installInterruptHandlers() and the class
     * docblock for the PARALLEL-sub-stage limitation that remains.
     *
     * @param Workflow         $workflow           The workflow definition to execute.
     * @param array            $context            Key-value pairs for {{variable}} interpolation.
     * @param int              $currentStageIndex  Index of the first stage to execute (0 = start fresh).
     * @param string|null      $workflowIdOverride Use this workflowId instead of generating a new one (for resume).
     * @param string|null      $pauseId            Identifier to pause() under if a real interrupt lands mid-run
     *                                              (the workflow name/path for run(), the workflowId for resume()).
     *                                              Defaults to the resolved workflowId when not given.
     * @return WorkflowResult
     */
    private function runFromWorkflow(
        Workflow $workflow,
        array $context,
        int $currentStageIndex,
        ?string $workflowIdOverride,
        ?string $pauseId = null,
    ): WorkflowResult {
        $startedAt = new \DateTimeImmutable();
        $stageResults = [];
        $totalTokens = 0;
        $totalCost = 0.0;

        // Clone context so we don't mutate the caller's array
        $context = [...$context];

        $resolvedWorkflowId = $workflowIdOverride ?? $this->generateWorkflowId($workflow);
        $interruptId = $pauseId ?? $resolvedWorkflowId;

        $previousAsyncSignals = $this->installInterruptHandlers(
            $interruptId,
            $resolvedWorkflowId,
            $startedAt,
            $context,
            $stageResults,
            $totalTokens,
            $totalCost,
        );

        try {
            foreach ($workflow->stages as $stageIndex => $stage) {
                // Skip stages that were already completed (resume support)
                if ($stageIndex < $currentStageIndex) {
                    continue;
                }

                $stageStartedAt = new \DateTimeImmutable();

                $stageType = $stage['type'] ?? '';

                if ($stageType === 'parallel') {
                    try {
                        $stageResult = $this->executeParallelStage($stage, $context, $workflow);
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
                        workflowId: $resolvedWorkflowId,
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
        } finally {
            if ($previousAsyncSignals !== null) {
                $this->restoreInterruptHandlers($previousAsyncSignals);
            }
        }

        return new WorkflowResult(
            workflowId: $resolvedWorkflowId,
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
    private function executeStage(array $stage, array &$context): StageResult
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

        // Store agent result in context for {{agentName.results}} interpolation
        $agentName = $task->name ?? $task->agentType;
        $context[$agentName]['results'] = $agentResult->output ?? '';

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
     * This method is part of the parallel() primitive implementation which spans
     * five files:
     *   - WorkflowEngine.php: executeParallelStage() orchestrates the parallel run
     *   - WorkflowBuilder.php: parallel() registers the stage; stopOnFirstFailure() configures it
     *   - Workflow.php: $stopOnFirstFailure property gates fail-fast behavior
     *   - WorkflowRegistry.php: parseStages() recognizes 'parallel' type stages
     *   - AgentWorkerPool.php: executeAll() runs agents concurrently; withStopOnFirstFailure() enables early termination
     *
     * @param array    $stage   Stage array from Workflow::$stages.
     * @param array    $context Current workflow context for interpolation.
     * @param Workflow $workflow The workflow definition (provides maxConcurrent, stopOnFirstFailure).
     * @return StageResult
     */
    private function executeParallelStage(array $stage, array $context, Workflow $workflow): StageResult
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

        // Build a SubAgent for each task.  A $defaultRequest is created from the first
        // task to supply tools/systemPrompt for the pool, but AgentWorkerPool::executeAll()
        // builds a per-agent CompleteRequest using each agent's own task field, so
        // parallel stages with non-identical prompts work correctly.
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

        // Create a fresh pool scoped to this parallel stage so that workflow-level
        // settings (maxConcurrent, stopOnFirstFailure) do not mutate the shared
        // $this->pool instance. The pool's executor is preserved from $this->pool
        // via getExecutor() so that custom executors (e.g., test mocks) are honoured.
        $pool = new AgentWorkerPool(
            maxConcurrent: $workflow->maxConcurrent,
            executor: $this->pool->getExecutor(),
        );
        if ($workflow->stopOnFirstFailure) {
            $pool = $pool->withStopOnFirstFailure(true);
        }

        // Route the stage pool through the AgentManager when one is attached:
        // the manager registers each SubAgent and mirrors per-result usage back
        // onto it, which is what makes its elapsed/token/cost accessors (and so
        // the live agent status line) report real work for workflow-spawned
        // agents instead of zeros (crush_feat.md section 5 E6). Exactly one of
        // the two paths runs -- the manager accumulates with `+=`, so iterating
        // the pool as well would double-count this stage's usage.
        $results = $this->agentManager !== null
            ? $this->agentManager->executeAll($subAgents, $defaultRequest, $pool)
            : $pool->executeAll($subAgents, $defaultRequest);

        // Collect all results from the generator
        $agentResults = [];
        foreach ($results as $agentResult) {
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
     * Interpolate {{variable}}, {{stageName.output}}, and {{agentName.results}} tokens in a string.
     *
     * - `{{variable}}` is replaced with $context['variable'] if set, otherwise left as-is.
     * - `{{stageName.output}}` is replaced with the output of a prior stage result.
     * - `{{agentName.results}}` is replaced with the results of a named agent from the context.
     *
     * @param string $text    The text containing interpolation tokens.
     * @param array  $context Current workflow context.
     * @return string Interpolated text.
     */
    private function interpolateContext(string $text, array $context): string
    {
        // Replace {{agentName.results}} references (they contain .results)
        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\.results\}\}/',
            static function (array $matches) use ($context): string {
                $agentName = $matches[1];
                return $context[$agentName]['results'] ?? $matches[0];
            },
            $text
        );

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

    /**
     * Install real SIGINT/SIGTERM handlers for the duration of the stage-
     * execution loop in runFromWorkflow() (R28).
     *
     * A genuine Ctrl-C or `kill -TERM` on this process while it is blocked
     * inside a stage (e.g. waiting on AgentWorkerPool::executeOne()) is
     * otherwise fatal to the whole run: the default disposition kills the
     * process and every completed stage's output is lost, even though it
     * was already sitting in memory. Registering a handler here means the
     * handler runs with the *live* $context/$stageResults/$totalTokens/
     * $totalCost references from the calling loop, so it can snapshot
     * exactly what has really finished, hand that snapshot to pause() the
     * same way a cooperative pause would, and only then let the process
     * exit.
     *
     * Limitation (see class docblock): this only observes whole-stage
     * boundaries. A 'parallel' stage's individual agent results, if the
     * interrupt lands mid-stage, are not captured — the stage is simply
     * missing from the pause file and will be re-run in full on resume().
     *
     * No-op (returns null) when the pcntl extension is unavailable, so
     * behaviour on platforms without pcntl (e.g. Windows) is unchanged
     * from before this fix: a real interrupt still terminates the process
     * with no pause file, same as always.
     *
     * Fork-safety: pcntl_signal() dispositions are inherited across
     * pcntl_fork(). If the signal lands while a 'parallel' stage's
     * AgentWorkerPool has live forked children (see
     * AgentWorkerPool::startAgent()), every forked child independently
     * re-enters this same closure too. Only the process that originally
     * installed the handler (captured as $installPid below) owns
     * $stageResults/pause() for this run — a forked child re-running
     * pause() would race an unsynchronized file write against the true
     * parent and short-circuit its own exit(0) reaping path. The handler
     * below checks getmypid() against $installPid and, for any forked
     * child, just exits with the signal-convention code without touching
     * pause() at all — i.e. the child dies the same way it always did
     * before this fix (silently, on the signal), and only the parent
     * persists anything.
     *
     * @param string              $interruptId         Identifier used to correlate the pause file with this run.
     * @param string              $resolvedWorkflowId  The workflow ID the in-flight run is executing under.
     * @param \DateTimeImmutable  $startedAt            When this run originally started.
     * @param array               $context      Reference to the live workflow context.
     * @param StageResult[]       $stageResults Reference to the live list of completed stage results.
     * @param int                 $totalTokens  Reference to the live running token total.
     * @param float               $totalCost    Reference to the live running cost total.
     * @return bool|null The previous pcntl_async_signals() setting to restore in
     *                    restoreInterruptHandlers() once this run finishes, or null
     *                    when handlers were not installed (pcntl unavailable).
     */
    private function installInterruptHandlers(
        string $interruptId,
        string $resolvedWorkflowId,
        \DateTimeImmutable $startedAt,
        array &$context,
        array &$stageResults,
        int &$totalTokens,
        float &$totalCost,
    ): ?bool {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return null;
        }

        // Dispatch signal handlers without requiring an explicit
        // pcntl_signal_dispatch() tick, so a blocking call inside a stage
        // (e.g. a long HTTP request or sleep()) is interrupted promptly.
        // pcntl_async_signals() returns the PREVIOUS setting, which
        // restoreInterruptHandlers() uses to put the process back exactly
        // how it found it rather than unconditionally leaving async
        // dispatch on for the rest of the process's life.
        $previousAsyncSignals = pcntl_async_signals(true);

        $installPid = getmypid();

        $handler = function (int $signo) use (
            $interruptId,
            $resolvedWorkflowId,
            $startedAt,
            $installPid,
            &$context,
            &$stageResults,
            &$totalTokens,
            &$totalCost,
        ): void {
            // See the fork-safety note on installInterruptHandlers(): a
            // forked 'parallel'-stage child inherits this same handler. It
            // must not call pause() — that's the parent's job for this
            // run — so it just exits under the signal convention.
            if (getmypid() !== $installPid) {
                exit($signo === \SIGINT ? 130 : 143);
            }

            $partialResult = new WorkflowResult(
                workflowId: $resolvedWorkflowId,
                status: WorkflowStatus::Running,
                stageResults: $stageResults,
                context: $context,
                totalTokens: $totalTokens,
                totalCost: $totalCost,
                startedAt: $startedAt,
                completedAt: new \DateTimeImmutable(),
            );

            // Reuse the exact same pause() path a cooperative pause would
            // take, so the persisted file format never drifts between the
            // two code paths.
            $this->resultsByName[$interruptId] = $partialResult;

            try {
                $this->pause($interruptId);
            } catch (\Throwable) {
                // Best-effort: still exit below even if pause() itself
                // couldn't write (e.g. unwritable pause dir) — resuming
                // execution as though the signal never arrived would be
                // worse than exiting with nothing captured.
            }

            exit($signo === \SIGINT ? 130 : 143);
        };

        pcntl_signal(\SIGINT, $handler);
        pcntl_signal(\SIGTERM, $handler);

        return $previousAsyncSignals;
    }

    /**
     * Restore default SIGINT/SIGTERM disposition, and the pcntl_async_signals()
     * setting that was in effect before installInterruptHandlers() ran, after a
     * stage-execution loop finishes — so neither the signal handlers nor the
     * async-dispatch mode leak past this run() into the rest of the calling
     * process (e.g. the PHPUnit process running this very test suite).
     *
     * @param bool $previousAsyncSignals The pcntl_async_signals() setting to
     *                                   restore, as returned by installInterruptHandlers().
     */
    private function restoreInterruptHandlers(bool $previousAsyncSignals): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(\SIGINT, \SIG_DFL);
        pcntl_signal(\SIGTERM, \SIG_DFL);
        pcntl_async_signals($previousAsyncSignals);
    }
}
