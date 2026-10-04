<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\BuiltIn;

use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\EngineExecutor;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Crush\Tools\Catalog\BuiltInTool;
use SugarCraft\Crush\Tools\Catalog\ToolBuildContext;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\DelegatesToEngine;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Workflows\StageResult;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowLoadException;
use SugarCraft\Crush\Workflows\WorkflowRegistry;
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;

/**
 * Model-authored workflows (roadmap 4.10-2): the model writes a YAML plan in
 * the `/workflow` schema and this tool runs it through {@see WorkflowEngine},
 * returning every stage's outcome.
 *
 * ONE ENGINE AND ONE POOL PER CALL, bound to the CALLING TURN's engine. The
 * launch's `/workflow` engine binds its pool's executor late and in place
 * ({@see WorkflowEngine::bindEngineBackend()}), so borrowing it would move the
 * user's own runs onto this turn's engine. A per-call engine also keeps the
 * runs apart: two calls never share the one-run-at-a-time gate or a result
 * slot.
 *
 * THE STAGES RUN INLINE, IN THE TURN'S OWN PROCESS. The pool is given the
 * {@see EngineExecutor} as its INJECTED executor, which the pool runs
 * synchronously, rather than as the forked one the TUI's `/workflow` uses. The
 * turn already runs off the TUI process, so there is no frame to keep alive,
 * and staying in this process is what keeps the turn's permission approver
 * working: it answers only in the process that built it, so a stage agent
 * forked below this one could only have every `Ask` refused. The cost is that
 * a `parallel: true` stage's agents take turns. Every stage agent beats the
 * turn's heartbeat, so a long run is never taken for a hung turn.
 *
 * WHAT A STAGE AGENT MAY DO: this session's tool loop — same hooks,
 * permission gate, approver and root — narrowed to the tools its stage
 * declares (an undeclared stage inherits the session's), each call held to the
 * whole declaration by {@see \SugarCraft\Crush\Hooks\BuiltIn\SubAgentGrantHook}
 * (so `Bash(git *)` admits only git). `Task` and `Workflow` themselves are
 * withheld from it ({@see EngineExecutor}), so a plan cannot recurse.
 *
 * Not {@see \SugarCraft\Crush\Tools\ParallelSafe}: a run is stateful,
 * long, and asks permission questions. {@see ExemptFromParallelDeadline} is
 * declared for the reason Task declares it — the tool bounds itself, by each
 * stage's `config.timeout` and each agent's step cap.
 */
#[BuiltInTool(name: self::NAME, permission: ToolPermissionClass::Write, position: 17, gloss: 'runs a model-authored YAML plan of staged sub-agents')]
final readonly class WorkflowTool implements Tool, BuildsFromCatalog, DelegatesToEngine, ExemptFromParallelDeadline
{
    public const NAME = 'Workflow';

    /** Bytes of one stage's output the report carries; the middle of a longer one is elided. */
    public const STAGE_OUTPUT_MAX_BYTES = 8192;

    /** How error messages name the plan, in the loader's "Workflow file <source>" shape. */
    private const PLAN_SOURCE = '(Workflow tool plan)';

    /**
     * @param \Closure(): void|null $heartbeat the turn's liveness sink ({@see DelegatesToEngine})
     */
    public function __construct(
        private ?string $root = null,
        private ?EngineBackend $engine = null,
        private ?\Closure $heartbeat = null,
    ) {
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        return new self($context->root);
    }

    public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null, ?\Closure $subAgentEmitter = null): self
    {
        return new self($this->root, $engine, $heartbeat);
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Run a multi-stage plan of sub-agents, written as a YAML workflow, and get back every stage\'s '
            . 'result. Reach for it when a task splits into ordered phases (survey, change, review) or into '
            . 'independent pieces for a fan-out; for one delegated task use Task, and for anything you can do '
            . 'in a few tool calls do it yourself. `plan` is the YAML: an optional `name` and `description`, '
            . 'a `stages` list and an optional `config` with `timeout` (seconds per stage, default 3600). Each '
            . 'stage has a unique `name` and either one task (`agent` label, `prompt`, `tools`, `retries`), a '
            . '`tasks:` list of such tasks run in order, or `parallel: true` with an `agents:` list (each '
            . '`type`, `name`, `prompt`, `tools`). A prompt may use `{{key}}` from `context`, '
            . '`{{<stage>.output}}` from an earlier stage, `{{prevResult}}` inside a `tasks:` list and '
            . '`{{<name>.results}}` for one agent\'s output. Every stage agent works with this session\'s tools '
            . 'and permissions, limited to its `tools` list when it has one (entries may be argument-scoped, '
            . 'like `Bash(git *)`); stage agents cannot call Task or Workflow, and they do not see this '
            . 'conversation, so each prompt must be self-contained. Stages run one after another and the run '
            . 'stops at the first stage that fails. The result lists each stage\'s status and output; it does '
            . 'not include the agents\' intermediate tool calls.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => [
                    'type' => 'string',
                    'description' => 'The workflow as YAML: `stages:` (required), optional `name`, `description` and `config: {timeout}`.',
                ],
                'context' => [
                    'type' => 'object',
                    'description' => 'Values for `{{key}}` placeholders in the stage prompts, as strings; keys must not start with `@`.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required' => ['plan'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $toolCallId = (string) ($args['id'] ?? '');

        if ($this->engine === null) {
            return new ToolResult($toolCallId, 'Error: the Workflow tool runs its stages on the session engine, and none is bound to this call.', true);
        }

        $plan = $args['plan'] ?? null;
        if (!is_string($plan) || trim($plan) === '') {
            return new ToolResult($toolCallId, 'Error: the Workflow tool needs a non-empty "plan": the workflow as YAML, with a `stages:` list.', true);
        }

        $context = $args['context'] ?? [];
        if (!is_array($context) || ($context !== [] && array_is_list($context))) {
            return new ToolResult($toolCallId, 'Error: "context" must be an object of placeholder values.', true);
        }
        foreach ($context as $key => $value) {
            if (!is_scalar($value)) {
                return new ToolResult($toolCallId, sprintf('Error: "context.%s" must be a string, number or boolean.', $key), true);
            }
        }

        $registry = new WorkflowRegistry();
        try {
            $workflow = $registry->fromYamlString($plan, self::PLAN_SOURCE);
        } catch (WorkflowLoadException $e) {
            return new ToolResult($toolCallId, 'Error: the plan was not run. ' . $e->getMessage(), true);
        }

        $engine = $this->engine;
        $workflows = new WorkflowEngine(
            $registry,
            new AgentWorkerPool(executor: new EngineExecutor($engine, heartbeat: $this->heartbeat)),
            model: $engine->model(),
            provider: $engine->provider()->name(),
            permissionGate: $engine->permissionGate(),
            environmentRoot: $this->root,
            toolRegistry: $engine->tools(),
        );

        try {
            $result = $workflows->runWorkflow($workflow, $context);
        } catch (\Throwable $e) {
            return new ToolResult($toolCallId, 'Error: the workflow could not run. ' . $e->getMessage(), true);
        }

        return new ToolResult($toolCallId, self::report($workflow->name, count($workflow->stages), $result), !$result->isSuccess());
    }

    /**
     * The run as the model reads it: a headline, then each stage that ran,
     * with its output (clipped) or the reason it failed.
     */
    private static function report(string $name, int $stageCount, WorkflowResult $result): string
    {
        $succeeded = count(array_filter($result->stageResults, static fn (StageResult $s): bool => $s->isSuccess()));
        $lines = [sprintf(
            "Workflow '%s' %s: %d of %d stage%s succeeded, %d tokens, $%.4f.",
            $name,
            $result->status->value,
            $succeeded,
            $stageCount,
            $stageCount === 1 ? '' : 's',
            $result->totalTokens,
            $result->totalCost,
        )];

        foreach ($result->stageResults as $i => $stage) {
            $lines[] = '';
            $lines[] = sprintf('### Stage %d: %s (%s)', $i + 1, $stage->stageName, $stage->status->value);
            if ($stage->status !== WorkflowStatus::Completed && $stage->error !== null && $stage->error !== '') {
                $lines[] = 'Error: ' . $stage->error;
            }
            $output = trim((string) $stage->output);
            if ($output !== '') {
                $lines[] = self::clip($output);
            }
        }

        if (count($result->stageResults) < $stageCount && !$result->isSuccess()) {
            $lines[] = '';
            $lines[] = sprintf('%d later stage%s did not run.', $stageCount - count($result->stageResults), $stageCount - count($result->stageResults) === 1 ? '' : 's');
        }

        return implode("\n", $lines);
    }

    /** $text with its middle elided past {@see STAGE_OUTPUT_MAX_BYTES}, cut on character boundaries. */
    private static function clip(string $text): string
    {
        if (strlen($text) <= self::STAGE_OUTPUT_MAX_BYTES) {
            return $text;
        }

        $half = intdiv(self::STAGE_OUTPUT_MAX_BYTES, 2);
        $head = mb_strcut($text, 0, $half, 'UTF-8');
        $tail = mb_strcut($text, strlen($text) - $half, null, 'UTF-8');

        return $head . sprintf("\n[… %d bytes elided …]\n", strlen($text) - strlen($head) - strlen($tail)) . $tail;
    }
}
