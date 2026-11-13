<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Support\Log;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\ExemptFromParallelDeadline;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Workflows\StageResult;
use SugarCraft\Code\Workflows\Workflow;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowGrammarGlossary;
use SugarCraft\Code\Workflows\WorkflowLinter;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowNarration;
use SugarCraft\Code\Workflows\WorkflowNotRunningException;
use SugarCraft\Code\Workflows\WorkflowRegistry;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;
use SugarCraft\Code\Workflows\WorkflowTask;

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
 * THE STAGES RUN INLINE, IN THE TURN'S OWN PROCESS — UNLESS THEY CANNOT ASK.
 * The pool is given the {@see EngineExecutor} as its INJECTED executor, which
 * the pool runs synchronously, rather than as the forked one the TUI's
 * `/workflow` uses. The turn already runs off the TUI process, so there is no
 * frame to keep alive, and staying in this process is what keeps the turn's
 * permission approver working: it answers only in the process that built it,
 * so a stage agent forked below this one could only have every `Ask` refused.
 *
 * P2-1: that reason does not apply to an agent whose effective tools cannot
 * put a question ({@see \SugarCraft\Code\Agents\AskFreeGrant}: no judging
 * gate could answer `Ask` for any of them under its mode, rules and grants —
 * {@see \SugarCraft\Code\Permissions\PermissionGate::wouldAsk()} — so a
 * read-only agent, a `Bash` the session allows outright, or any agent under
 * `bypass-permissions` qualifies), so the executor clears exactly those
 * agents of a `parallel: true` stage for the pool's forking path
 * ({@see \SugarCraft\Code\Agents\ForksAskFreeAgents}) and they run
 * concurrently; any agent that might ask still runs inline, one at a time.
 * Every parallel stage's JSON row says which happened (`dispatch`). Inline
 * agents beat the turn's heartbeat themselves; while forked ones run, the
 * pool beats it from its idle poll, so a long run is never taken for a hung
 * turn. A cancelled turn's tree kill takes the forked agents with it, and
 * each one also stops at its next step once its parent is gone
 * ({@see \SugarCraft\Code\Support\ParentProcessGuard}).
 *
 * WHAT A STAGE AGENT MAY DO: this session's tool loop — same hooks,
 * permission gate, approver and root — narrowed to the tools its stage
 * declares (an undeclared stage inherits the session's), each call held to the
 * whole declaration by {@see \SugarCraft\Code\Hooks\BuiltIn\SubAgentGrantHook}
 * (so `Bash(git *)` admits only git). `Task` and `Workflow` themselves are
 * withheld from it ({@see EngineExecutor}), so a plan cannot recurse.
 *
 * RESUMABLE RUNS (P1-8): every run is a {@see WorkflowEngine::runPlan()} run
 * with the ID `plan-<sha8 of the plan>-<n>`, a crash-safe record under the
 * user workflows directory's `.running/` and the plan stored beside it; the
 * record is kept at every terminal (newest {@see WorkflowEngine::PLAN_RUN_RETENTION}).
 * `resumeFrom: <runId>` resumes it with the (possibly edited) plan: the
 * longest unchanged prefix of stages is carried at no cost, the first changed
 * stage and everything after it run live ({@see WorkflowEngine::resumePlan()}).
 * `/workflow status|resume <runId>` reach the same record.
 *
 * EVERY STAGE IS CAPTURED (P0-4): after the run, each stage's full output is
 * written to its capture file (`<runId>.stages/<n>-<stage>.txt`, the engine's
 * own path rule) and its JSON row names it, so an elided byte is never lost.
 * This is a REPORT-side capture: unlike an explicit `capture: true`, it does
 * not change what later stages read — `{{stage.output}}` and `when:` still see
 * the full text — because a default that silently turned `equals: PASS` into
 * a comparison against a capture envelope would break plans.
 *
 * NARRATION (P2-2): the run's announcements (`announce:` lines, truncations,
 * `minSuccess` casualties, budget stops, the agent ceiling) stream as they are
 * made onto the live line under this call's row (a {@see SubAgentActivity}
 * on the delegation emitter) and come back in the JSON as `announcements`.
 *
 * Not {@see \SugarCraft\Code\Tools\ParallelSafe}: a run is stateful,
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
     * @param \Closure(SubAgentActivity): void|null $subAgentEmitter the turn's delegation channel; narration rides it
     * @param ?string $workflowsPath the user workflows directory: `uses:` loads from it and run records live
     *        under its `.running/` — the directory `/workflow` reads, so status/resume find tool runs
     * @param \Closure(): iterable<\SugarCraft\Code\Agents\Agent>|null $agentRoster the session's agent roster,
     *        resolved only when a plan names a `preset:`; null builds the launch's own ({@see Bootstrap::agentRoster()})
     */
    public function __construct(
        private ?string $root = null,
        private ?EngineBackend $engine = null,
        private ?\Closure $heartbeat = null,
        private ?\Closure $subAgentEmitter = null,
        private ?string $workflowsPath = null,
        private ?\Closure $agentRoster = null,
    ) {
    }

    public static function fromCatalog(ToolBuildContext $context): self
    {
        // The same user tier Bootstrap::workflowEngine() hands /workflow, so
        // a tool run's record is the one `/workflow status <runId>` reads. A
        // launch whose home cannot be established keeps the registry default.
        try {
            $workflowsPath = Bootstrap::trustedConfigDirPath() . '/workflows';
        } catch (\Throwable) {
            $workflowsPath = null;
        }

        return new self($context->root, workflowsPath: $workflowsPath);
    }

    public function withEngine(EngineBackend $engine, ?\Closure $heartbeat = null, ?\Closure $subAgentEmitter = null): self
    {
        return new self($this->root, $engine, $heartbeat, $subAgentEmitter, $this->workflowsPath, $this->agentRoster);
    }

    /** This tool keeping its run records (and loading `uses:`) under $workflowsPath. */
    public function withWorkflowsPath(string $workflowsPath): self
    {
        return new self($this->root, $this->engine, $this->heartbeat, $this->subAgentEmitter, $workflowsPath, $this->agentRoster);
    }

    /**
     * This tool resolving `preset:` against $roster's agents.
     *
     * @param \Closure(): iterable<\SugarCraft\Code\Agents\Agent> $roster
     */
    public function withAgentRoster(\Closure $roster): self
    {
        return new self($this->root, $this->engine, $this->heartbeat, $this->subAgentEmitter, $this->workflowsPath, $roster);
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
            . "in a few tool calls do it yourself.\n\n"
            . WorkflowGrammarGlossary::grammar()
            . "\n\n"
            . 'Every stage agent works with this session\'s tools and permissions, limited to its `tools` list '
            . 'when it has one; stage agents cannot call Task or Workflow and do not see this conversation. '
            . 'Stages run one after another and the run stops at the first stage that fails. The plan is linted '
            . 'before anything runs: lint errors refuse the run (nothing is dispatched) and come back in the '
            . 'result, lint warnings ride the result\'s header. `dryRun: true` lints and returns the stage rows '
            . 'without dispatching. The result is a one-line headline plus a ```json block '
            . '`{status, runId, carried, budget, announcements, stages:[{name, status, error, attempts, tokens, cost, carried?, data?, output?, capture?, dispatch?}]}`: '
            . '`data` is a schema\'d stage\'s decoded JSON, never clipped; `output` is prose, its middle elided '
            . 'past ' . self::STAGE_OUTPUT_MAX_BYTES . ' bytes, and the elision marker names the `capture` file that holds every stage\'s full output. '
            . '`budget: N` caps this call\'s token spend (the tighter of it and `config.maxTokens` wins; a dispatch past it pauses the run). '
            . 'Every run returns a `runId`; to iterate, edit the plan and call again with `resumeFrom: <runId>`: the longest unchanged '
            . 'prefix of stages is carried from the record at no cost, the first changed stage and everything after it run live '
            . '(a changed `context` carries nothing; omit `context` to reuse the run\'s). '
            . 'A parallel stage\'s agents run concurrently only when none of their tools could ask permission under '
            . 'this session\'s mode and rules; an agent that might ask runs inline, one at a time, and `dispatch` says which happened. '
            . 'Load the `workflow-authoring` skill before authoring a non-trivial plan.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => [
                    'type' => 'string',
                    'description' => WorkflowGrammarGlossary::planDescription(),
                ],
                'context' => [
                    'type' => 'object',
                    'description' => 'The run context: values for `{{key}}` placeholders in the stage prompts (an array or object '
                        . 'renders as compact JSON) and the lists a `from: {context: key}` fan-out walks. Any JSON value; '
                        . 'keys must not start with `@`.',
                    'additionalProperties' => new \stdClass(),
                ],
                'dryRun' => [
                    'type' => 'boolean',
                    'description' => 'true: lint the plan and return its stage rows and findings without dispatching any agent.',
                ],
                'budget' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'A token budget for this call (e.g. 500000 for a user\'s "+500k"): a dispatch at or past it does not '
                        . 'start and the run pauses, resumable with a larger budget. The tighter of it and the plan\'s `config.maxTokens` wins.',
                ],
                'resumeFrom' => [
                    'type' => 'string',
                    'description' => 'A `runId` an earlier Workflow call returned: resume that run with this (possibly edited) plan. '
                        . 'Unchanged leading stages are carried at no cost; the first changed stage and every later one run live.',
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

        $dryRun = $args['dryRun'] ?? false;
        if (!is_bool($dryRun)) {
            return new ToolResult($toolCallId, 'Error: "dryRun" must be true or false.', true);
        }

        $budget = $args['budget'] ?? null;
        if ($budget !== null && (!is_int($budget) || $budget < 1)) {
            return new ToolResult($toolCallId, 'Error: "budget" must be a whole number of tokens, at least 1.', true);
        }

        $resumeFrom = $args['resumeFrom'] ?? null;
        if ($resumeFrom !== null && (!is_string($resumeFrom) || trim($resumeFrom) === '')) {
            return new ToolResult($toolCallId, 'Error: "resumeFrom" must be a runId string an earlier Workflow call returned.', true);
        }
        $resumeFrom = $resumeFrom === null ? null : trim($resumeFrom);

        // A resume with no `context` reuses the one the run was started with.
        $contextGiven = array_key_exists('context', $args) && $args['context'] !== null;
        $context = $args['context'] ?? [];
        if (!is_array($context) || ($context !== [] && array_is_list($context))) {
            return new ToolResult($toolCallId, 'Error: "context" must be an object of placeholder values.', true);
        }
        // Arrays and objects pass through untouched: the engine renders them
        // as compact JSON in a `{{key}}` and walks a list for
        // `from: {context: key}`. Only `@` keys (the engine's own run-state
        // namespace) and a value JSON cannot carry are refused here.
        foreach ($context as $key => $value) {
            if (str_starts_with((string) $key, '@')) {
                return new ToolResult($toolCallId, sprintf('Error: "context.%s" is not allowed: keys starting with "@" are reserved for the workflow engine.', $key), true);
            }
            if (json_encode($value, JSON_PRESERVE_ZERO_FRACTION) === false) {
                return new ToolResult($toolCallId, sprintf('Error: "context.%s" must be a JSON value (string, number, boolean, null, array or object).', $key), true);
            }
        }

        // No resolved directory keeps the registry's own default user tier.
        $registry = $this->workflowsPath === null ? new WorkflowRegistry() : new WorkflowRegistry($this->workflowsPath);
        try {
            $workflow = $registry->fromYamlString($plan, self::PLAN_SOURCE);
        } catch (WorkflowLoadException $e) {
            return new ToolResult($toolCallId, 'Error: the plan was not run. ' . $e->getMessage(), true);
        }

        $engine = $this->engine;
        $executor = new EngineExecutor($engine, heartbeat: $this->heartbeat, forkAskFree: true);
        $workflows = new WorkflowEngine(
            $registry,
            new AgentWorkerPool(executor: $executor),
            model: $engine->model(),
            provider: $engine->provider()->name(),
            permissionGate: $engine->permissionGate(),
            environmentRoot: $this->root,
            toolRegistry: $engine->tools(),
            // C6: the session's hook dispatcher rides the per-call engine so
            // /workflow stages announce lifecycle events exactly like the
            // turn path's tool calls do. Null when the backend has no
            // HookManager — an unarmed engine is byte-identical to a pre-C6 one.
            hookDispatcher: $engine->hookDispatcher(),
        );
        // P1-6: `preset:` resolves against the session's roster — built only
        // when the plan names one, since it reads preset files from disk.
        $root = $this->root;
        $workflows->setAgentRosterSource($this->agentRoster ?? static fn (): array => Bootstrap::agentRoster(
            $root ?? (getcwd() ?: '.'),
            $engine->provider()->name(),
            $engine->model(),
        ));

        // P0-4: lint BEFORE the first dispatch. The engine's own pre-flights
        // catch tools and reject placement only; an unresolved `{{token}}`
        // would otherwise reach the agent verbatim and be billed for.
        $presetNames = WorkflowEngine::presetsNamedBy($workflow) === [] ? null : $workflows->presetNames();
        $lint = WorkflowLinter::lint($workflow, $context, self::toolNamesOf($engine), self::includeResolver($registry), $presetNames);

        if ($dryRun) {
            return new ToolResult($toolCallId, self::dryRunReport($workflow, $lint), $lint->hasErrors());
        }

        if ($lint->hasErrors()) {
            return new ToolResult(
                $toolCallId,
                sprintf("Error: the plan was not run: lint found %s (nothing was dispatched).\n", self::lintCounts($lint)) . $lint->renderFindings(),
                true,
            );
        }

        $narration = new WorkflowNarration($this->subAgentEmitter, $toolCallId, $workflow->name);
        $workflows->setAnnouncementSink($narration->sink());
        // Announced when the run is claimed, because a killed process never reaches
        // the result that carries this ID. The TUI sees it on the Workflow row's
        // live line (the narration channel, the only transcript-side surface this
        // tool has); -p sees it on stderr via Log::raw.
        $workflows->setRunStartedSink(static function (string $runId) use ($narration): void {
            $line = sprintf('workflow run %s started (resume: candy-code workflow resume %s)', $runId, $runId);
            $narration->say($line);
            Log::raw($line);
        });
        $narration->started();

        try {
            $result = $resumeFrom === null
                ? $workflows->runPlan($workflow, $plan, $context, null, $budget)
                : $workflows->resumePlan($resumeFrom, $workflow, $plan, $contextGiven ? $context : null, null, $budget);
        } catch (WorkflowNotRunningException $e) {
            $narration->finished(SubAgentActivity::OUTCOME_FAILED, $e->getMessage());

            return new ToolResult($toolCallId, 'Error: the plan was not resumed: ' . $e->getMessage(), true);
        } catch (\Throwable $e) {
            $narration->finished(SubAgentActivity::OUTCOME_FAILED, $e->getMessage());

            return new ToolResult($toolCallId, 'Error: the workflow could not run. ' . $e->getMessage(), true);
        }
        $narration->finished(match ($result->status) {
            WorkflowStatus::Completed => SubAgentActivity::OUTCOME_COMPLETE,
            WorkflowStatus::Cancelled => SubAgentActivity::OUTCOME_CANCELLED,
            WorkflowStatus::Paused => SubAgentActivity::OUTCOME_EMPTY,
            default => SubAgentActivity::OUTCOME_FAILED,
        }, $result->firstFailure()?->error);

        $captures = self::captureEveryStage($workflows->runRecordDirectory(), $workflow, $result);

        return new ToolResult(
            $toolCallId,
            self::report($workflow, $result, $lint, $executor->dispatchLog(), $captures, $budget, $workflow->maxTokens),
            !$result->isSuccess(),
        );
    }

    /**
     * P0-4: every stage's full output in its capture file, so every byte the
     * report elides is recoverable — written by the tool after the run, at
     * the engine's own path rule ({@see WorkflowEngine::stageCapturePath()}),
     * WITHOUT touching the run's context (see the class doc). A stage that
     * declared `capture: true` already has its file (the engine's, read back
     * from the context); one with no output gets none. A failed write leaves
     * the stage's row without a `capture` key — the marker then says so.
     *
     * @return array<string, string> stage name => capture path
     */
    private static function captureEveryStage(string $recordDirectory, Workflow $workflow, WorkflowResult $result): array
    {
        $captures = [];
        foreach (array_values($result->stageResults) as $index => $stage) {
            $declared = self::capturePathOf($result->context, $stage->stageName);
            if ($declared !== null) {
                $captures[$stage->stageName] = $declared;
                continue;
            }
            $output = (string) $stage->output;
            if (trim($output) === '') {
                continue;
            }
            $file = WorkflowEngine::stageCapturePath($recordDirectory, $result->workflowId, $index, $stage->stageName);
            try {
                \SugarCraft\Code\Support\PrivateDir::ensure(\dirname($file), 'workflow stage captures');
                $partial = $file . '.partial';
                if (@file_put_contents($partial, $output) === false) {
                    continue;
                }
                @chmod($partial, 0600);
                if (!@rename($partial, $file)) {
                    @unlink($partial);
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $captures[$stage->stageName] = $file;
        }

        return $captures;
    }

    /**
     * How a parallel stage's agents were dispatched (P2-1), from the
     * executor's log of the pool's per-agent decisions — null for a stage
     * none of whose agents went through that decision (a sequential stage,
     * which is always inline).
     *
     * @param array<string, array{agent: string, forked: bool, why: ?string}> $log
     */
    public static function dispatchSummary(StageResult $stage, array $log): ?string
    {
        $forked = 0;
        $inline = [];
        foreach ($stage->agents as $agent) {
            $entry = $log[$agent->agentId] ?? null;
            if ($entry === null) {
                continue;
            }
            if ($entry['forked']) {
                $forked++;
            } else {
                $inline[] = $entry;
            }
        }

        if ($forked === 0 && $inline === []) {
            return null;
        }
        if ($inline === []) {
            return sprintf('parallel: forked (%d ask-free agent%s)', $forked, $forked === 1 ? '' : 's');
        }

        $first = $inline[0];
        $reason = $first['why'] ?? 'ran inline';
        $why = sprintf(str_starts_with($reason, 'may ask') ? 'agent "%s" %s' : 'agent "%s": %s', $first['agent'], $reason);
        if (count($inline) > 1) {
            $why .= sprintf('; %d more inline', count($inline) - 1);
        }

        return $forked === 0
            ? sprintf('parallel: inline (%s)', $why)
            : sprintf('parallel: %d forked, %d inline (%s)', $forked, count($inline), $why);
    }

    /**
     * The session's tool universe for {@see WorkflowLinter::lint()}'s
     * declared-vs-known leg — the same registry the run's pre-flight checks.
     * Shared with `/workflow run` and `/workflow lint`.
     *
     * @return list<string>
     */
    public static function toolNamesOf(EngineBackend $engine): array
    {
        return array_values(array_map(static fn (Tool $tool): string => $tool->name(), $engine->tools()));
    }

    /**
     * The "N lint warning(s): …" block that rides under a run's headline, or
     * '' for a clean lint. Called only once errors have refused the run, so every
     * finding left is a warning. Shared with `/workflow run`.
     */
    public static function lintWarningHeader(WorkflowLinter $lint): string
    {
        $count = $lint->warningCount();
        if ($count === 0) {
            return '';
        }

        return sprintf("%d lint warning%s:\n%s\n\n", $count, $count === 1 ? '' : 's', $lint->renderFindings());
    }

    /**
     * `uses:` edges resolve through the registry the run itself loads from; a
     * name it cannot load is "no verdict from here", never "no edge" (the
     * run-start refusal owns that).
     *
     * @return callable(string): ?Workflow
     */
    private static function includeResolver(WorkflowRegistry $registry): callable
    {
        return static function (string $name) use ($registry): ?Workflow {
            try {
                return $registry->load($name);
            } catch (\Throwable) {
                return null;
            }
        };
    }

    private static function lintCounts(WorkflowLinter $lint): string
    {
        return sprintf(
            '%d error%s, %d warning%s',
            $lint->errorCount(),
            $lint->errorCount() === 1 ? '' : 's',
            $lint->warningCount(),
            $lint->warningCount() === 1 ? '' : 's',
        );
    }

    private static function dryRunReport(Workflow $workflow, WorkflowLinter $lint): string
    {
        $lines = [
            sprintf("Dry run of workflow '%s' (no agents dispatched): %d stage%s.", $workflow->name, count($workflow->stages), count($workflow->stages) === 1 ? '' : 's'),
            '',
            $lint->renderPlanTable(),
        ];
        if ($lint->hasFindings()) {
            $lines[] = '';
            $lines[] = $lint->renderFindings();
        }
        $lines[] = '';
        $lines[] = 'Lint: ' . $lint->verdictLine() . ($lint->hasErrors() ? ' — a real run would be refused.' : '');

        return implode("\n", $lines);
    }

    /**
     * The run as the model reads it: a one-line headline a human can read,
     * then a fenced JSON document a model can branch on next turn without
     * re-parsing prose.
     *
     * The headline keeps its `Workflow '<name>' <status>:` lead (the
     * protocol's run listing parses it) and adds the spend against the
     * effective token budget, how many stages a resume carried, and the
     * runId to resume with.
     *
     * @param array<string, array{agent: string, forked: bool, why: ?string}> $dispatchLog
     * @param array<string, string> $captures stage name => capture path
     */
    private static function report(Workflow $workflow, WorkflowResult $result, WorkflowLinter $lint, array $dispatchLog = [], array $captures = [], ?int $budget = null, ?int $maxTokens = null): string
    {
        $stageCount = count($workflow->stages);
        $succeeded = count(array_filter($result->stageResults, static fn (StageResult $s): bool => $s->isSuccess()));
        $ceiling = $budget === null ? $maxTokens : ($maxTokens === null ? $budget : min($budget, $maxTokens));
        $headline = sprintf(
            "Workflow '%s' %s: %d of %d stage%s succeeded, %d tokens%s, $%.4f.",
            $workflow->name,
            $result->status->value,
            $succeeded,
            $stageCount,
            $stageCount === 1 ? '' : 's',
            $result->totalTokens,
            $ceiling === null ? '' : sprintf(' of a %d-token budget', $ceiling),
            $result->totalCost,
        );
        if ($result->stagesCarried > 0) {
            $headline .= sprintf(' %d stage%s carried from the record at no cost.', $result->stagesCarried, $result->stagesCarried === 1 ? '' : 's');
        }
        if ($result->workflowId !== '') {
            $headline .= sprintf(' Run ID %s (resumeFrom).', $result->workflowId);
        }
        $lines = [$headline];
        if ($result->status === WorkflowStatus::Paused) {
            $lines[] = sprintf('The run is paused; call again with resumeFrom: %s (and a larger budget when a budget stopped it).', $result->workflowId);
        }

        $warnings = self::lintWarningHeader($lint);
        if ($warnings !== '') {
            $lines[] = rtrim($warnings);
        }

        // Failures stay in prose too: the reason is what a reader scans for,
        // and inside the JSON it arrives escaped.
        foreach ($result->stageResults as $stage) {
            if ($stage->isFailure() && $stage->error !== null && $stage->error !== '') {
                $lines[] = sprintf("Stage '%s' %s: %s", $stage->stageName, $stage->status->value, $stage->error);
            }
        }

        $notRun = $stageCount - count($result->stageResults);
        if ($notRun > 0 && !$result->isSuccess()) {
            $lines[] = sprintf('%d later stage%s did not run.', $notRun, $notRun === 1 ? '' : 's');
        }

        $declared = [];
        foreach ($workflow->stages as $stage) {
            if (is_array($stage) && is_string($stage['name'] ?? null)) {
                $declared[$stage['name']] = $stage;
            }
        }

        $stages = [];
        foreach (array_values($result->stageResults) as $index => $stage) {
            $row = self::stageRow($stage, $declared[$stage->stageName] ?? null, $captures[$stage->stageName] ?? null);
            if ($index < $result->stagesCarried) {
                $row['carried'] = true;
            }
            $dispatch = self::dispatchSummary($stage, $dispatchLog);
            if ($dispatch !== null) {
                $row['dispatch'] = $dispatch;
            }
            $stages[] = $row;
        }

        $document = [
            'status' => $result->status->value,
            // P1-8: the record's ID — pass it back as `resumeFrom`.
            'runId' => $result->workflowId,
            'carried' => $result->stagesCarried,
            'budget' => $ceiling === null ? null : ['tokens' => $ceiling, 'spent' => $result->totalTokens],
            'announcements' => $result->announcements,
            'stages' => $stages,
        ];

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

        return implode("\n", $lines) . "\n\n```json\n" . ($json === false ? '{}' : $json) . "\n```";
    }

    /**
     * One stage's JSON row. `data` appears when every task the stage declares
     * carries an `outputSchema` and the output decodes — then it replaces
     * `output` and is NEVER clipped (a clipped JSON document is a corrupt
     * one). Everything else is prose under head/tail elision whose marker
     * names how to get the elided bytes back.
     *
     * @param ?array<string, mixed> $declared the stage as the Workflow DTO holds it
     * @param ?string              $capture  the file holding the stage's full output, when one was written
     * @return array<string, mixed>
     */
    private static function stageRow(StageResult $stage, ?array $declared, ?string $capture): array
    {
        $attempts = 0;
        $tokens = 0;
        $cost = 0.0;
        foreach ($stage->agents as $agent) {
            // The most tries any one agent took — the same reading `/workflow
            // run` reports; a sum would read a 3-wide fan-out as 3 retries.
            $attempts = max($attempts, $agent->attempts);
            $tokens += $agent->tokensUsed;
            $cost += $agent->costUsd;
        }

        $row = [
            'name' => $stage->stageName,
            'status' => $stage->status->value,
            'error' => $stage->error !== null && $stage->error !== '' ? $stage->error : null,
            'attempts' => $attempts,
            'tokens' => $tokens,
            'cost' => round($cost, 6),
        ];

        if ($capture !== null) {
            $row['capture'] = $capture;
        }

        $output = trim((string) $stage->output);
        if ($output === '') {
            return $row;
        }

        if ($declared !== null && self::everyTaskIsSchemad($declared)) {
            $data = self::decodeJson($output);
            if ($data !== null) {
                $row['data'] = $data;

                return $row;
            }
        }

        $row['output'] = self::clip($output, $capture);

        return $row;
    }

    /** @param array<string, mixed> $declared */
    private static function everyTaskIsSchemad(array $declared): bool
    {
        $tasks = $declared['tasks'] ?? null;
        if (!is_array($tasks) || $tasks === []) {
            return false;
        }
        foreach ($tasks as $task) {
            if (!$task instanceof WorkflowTask || $task->outputSchema === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The decoded document, or null when the output is not JSON. A reply
     * fenced as one ```json block decodes too; a JSON scalar does not count
     * (a bare `"ok"` is prose, not a structured row).
     */
    private static function decodeJson(string $output): mixed
    {
        if (preg_match('/\A```(?:json)?\s*\n(.*)\n```\z/s', $output, $m) === 1) {
            $output = trim($m[1]);
        }

        try {
            $decoded = json_decode($output, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) || is_object($decoded) ? $decoded : null;
    }

    /**
     * The capture file the engine wrote for a `capture: true` stage, read
     * back from the `[output captured to <path>]` line it put in the run
     * context — the engine's own record, not a second copy of its path rule.
     *
     * @param array<string, mixed> $context
     */
    private static function capturePathOf(array $context, string $stageName): ?string
    {
        $value = $context[$stageName . '.output'] ?? null;
        if (!is_string($value) || preg_match('/\A\[output captured to (.+)\]$/m', $value, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * $text with its middle elided past {@see STAGE_OUTPUT_MAX_BYTES}, cut on
     * character boundaries. The marker says where the elided bytes live: the
     * capture file when the stage was captured, otherwise how to get one.
     */
    private static function clip(string $text, ?string $capture = null): string
    {
        if (strlen($text) <= self::STAGE_OUTPUT_MAX_BYTES) {
            return $text;
        }

        $half = intdiv(self::STAGE_OUTPUT_MAX_BYTES, 2);
        $head = mb_strcut($text, 0, $half, 'UTF-8');
        $tail = mb_strcut($text, strlen($text) - $half, null, 'UTF-8');
        $recover = $capture !== null
            ? 'the full output is in ' . $capture
            : 'its capture file could not be written; re-run with capture: true on this stage to keep the full output';

        return $head . sprintf("\n[… %d bytes elided; %s …]\n", strlen($text) - strlen($head) - strlen($tail), $recover) . $tail;
    }
}
