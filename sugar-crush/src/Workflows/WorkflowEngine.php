<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\HomeDirectory;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\ToolDeclaration;
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
 * pcntl_async_signals(), AND the SIGINT/SIGTERM dispositions that were in
 * effect before this run, to whatever they were before run()/resume() was
 * called — rather than leaking async-dispatch mode, or resetting a handler
 * the calling process installed, into the rest of that process once the run
 * finishes. That second half matters now that {@see \SugarCraft\Crush\Chat}
 * dispatches run() inside a live TUI: candy-core's `Program` installs its own
 * SIGINT closure for graceful shutdown, and resetting to `SIG_DFL` here left
 * an external `kill -INT` killing the process outright, so PHP's shutdown
 * sequence — and with it PosixBackend's destructor, which is what puts termios
 * back — never ran, leaving the terminal in raw mode inside the alt screen.
 *
 * Mirrors charmbracelet/charmcrush WorkflowEngine implementation.
 */
final class WorkflowEngine implements WorkflowEngineInterface
{
    private const PAUSE_DIR = '.running';

    /**
     * Finished (or interrupted) runs this engine can still pause, keyed by the
     * NAME {@see run()} was called with — which is also the name
     * {@see WorkflowRegistry::load()} resolves.
     *
     * @var array<string, WorkflowResult>
     */
    private array $resultsByName = [];

    /**
     * The generated run ID ({@see generateWorkflowId()}, `<name>-<8 hex>`) of
     * each remembered run => its key in {@see $resultsByName}.
     *
     * THIS IS THE IDENTIFIER THE USER HAS. `/workflow run safe` prints
     * ``ID: `safe-252630d0` `` and the help text reads
     * `/workflow pause <workflowId>`, but this map's absence meant `pause`,
     * `resume` and `status` all keyed off the NAME — so measured on a real
     * launch, three of the five verbs rejected the only identifier the UI hands
     * out (`No result found for workflow 'safe-252630d0'`) and accepted one it
     * never prints. Both spellings resolve now; see {@see runKeyFor()} and
     * {@see pauseFileFor()} for the two directions.
     *
     * @var array<string, string>
     */
    private array $runKeysById = [];

    /**
     * Key in {@see $resultsByName} => the registry name that key's workflow can
     * be loaded from, for the pause file's `workflowPath` field.
     *
     * Separate from the key itself because they stop agreeing the moment a run is
     * RESUMED: a resumed run is remembered under its pause file's name, while the
     * string {@see WorkflowRegistry::load()} needs is the one the original
     * `/workflow run` used.
     *
     * @var array<string, string>
     */
    private array $loadPathsByKey = [];

    /**
     * SIGINT/SIGTERM dispositions as they were before each run installed its
     * own — one frame per live {@see runFromWorkflow()}, pushed by
     * {@see installInterruptHandlers()} and popped by
     * {@see restoreInterruptHandlers()}.
     *
     * A property rather than a return value for the same reason
     * {@see \SugarCraft\Core\Program::$prevSignalHandlers} is one: install and
     * restore are a matched pair around one stage-execution loop, and the value
     * has no meaning to anybody else.
     *
     * A STACK rather than one frame, because runs NEST: a STAGE can re-enter
     * `run()` on this same engine — the executor a stage dispatches to is
     * caller-supplied code, and it may. A single frame meant the inner restore
     * installed the OUTER run's handler (correct so far) and then cleared the
     * array, so the outer restore found nothing captured and fell back to
     * `SIG_DFL` — reinstating, one level in, the exact defect
     * {@see restoreInterruptHandlers()} exists to fix. Push/pop keeps each
     * run's frame with that run. Exercised by
     * `WorkflowEngineTest::testNestedRunsEachRestoreTheirOwnCallersSignalHandler()`.
     *
     * (This paragraph used to name `runFromPhp(callable)` as the nesting case.
     * It is not one: that callable is invoked to PRODUCE the Workflow, before
     * {@see runFromWorkflow()} is entered, so a `run()` it makes has already
     * finished by the time the outer run captures anything. Sequential, not
     * nested.)
     *
     * ⚠️ A STACK IS ONLY CORRECT FOR NESTING, and nesting stopped being the
     * only way two runs can be live. A nested run shares its parent's call
     * stack, so it necessarily finishes first and LIFO is exactly right.
     * INTERLEAVED runs do not: since `Chat::workflowRun()` drives a run from a
     * `\Fiber`, two runs can be live in two fibers, suspend at
     * {@see \SugarCraft\Crush\Agents\AgentWorkerPool::idle()}, and finish in
     * an order with NO relationship to the order they started in. Each pop
     * then reinstates whichever frame happens to be on top — measured as the
     * second-most-recently-installed one, which with three overlapping runs is
     * not even the other run's — so the handler live during the overlap
     * belongs to a run that has already finished.
     *
     * That is not cosmetic, and it is not a leak either. Measured both ways:
     * the ORIGINAL disposition IS correctly restored once the last run exits
     * (push and pop are balanced 1:1, and the true pre-run frame sits at the
     * bottom), including when a suspended fiber is abandoned and collected —
     * PHP unwinds it, so the `finally` still runs. There is no
     * process-lifetime leak. But INSIDE the window, a delivered SIGINT was
     * observed to write a pause file for the run that had already ENDED —
     * `alpha.json`, with alpha's stages — and to discard the live run's
     * progress entirely before `exit(130)`. Wrong data persisted, not just a
     * stale closure. (Mitigating: raw mode clears `ISIG`, so an interactive
     * Ctrl-C is a byte rather than a signal — this needs an external
     * `kill -INT`/`-TERM`, or a caller not in raw mode.)
     *
     * {@see $liveRunOwners} refuses that case outright rather than trying to
     * make the stack interleave-safe; see there for why refusing is the right
     * answer and not merely the cheap one.
     *
     * @var list<array<int, callable|int>>
     */
    private array $previousSignalHandlers = [];

    /**
     * The owner of every run currently live on this engine, innermost last —
     * the `\Fiber` it is executing in, or `null` for the main call stack.
     *
     * This is the state that tells NESTING (fine, and supported: see
     * {@see $previousSignalHandlers}) apart from INTERLEAVING (refused). A
     * nested run is entered from inside its parent, so it sees its own owner
     * already on this list and is allowed through. An interleaved run is
     * entered from a different fiber, sees a DIFFERENT owner, and is refused.
     *
     * WHY REFUSE RATHER THAN MAKE THE ENGINE RE-ENTRANT. The signal-handler
     * stack is the sharpest symptom but not the worst one. {@see run()} keys
     * {@see $resultsByName} by workflow NAME and {@see rememberResult()}
     * overwrites that slot unconditionally, so two live runs of ONE name
     * collapse into a single entry, last writer wins, while
     * {@see $runKeysById} maps BOTH distinct run IDs onto it. Measured
     * consequence: `/workflow pause <run-A-id>` — the exact id the transcript
     * printed for run A — writes a pause file recording run B's workflowId
     * and B's stage results, `getStatus()` answers identically for both ids,
     * and run A becomes unreachable by any identifier at all. The interrupt
     * handler calls `rememberResult()` with the same key and collides the same
     * way.
     *
     * Making all of that genuinely concurrent is a design change with a
     * user-visible surface — which run does `/workflow pause <name>` mean? —
     * and it is not the change this item is. Refusing costs one error line and
     * leaves every existing single-run behaviour exactly as it was.
     *
     * The refusal is reachable from the TUI today, and only there. Measured:
     * WITHOUT the double-Escape a second `/workflow run` is queued behind the
     * `inFlight` latch and no second run starts. Double-Escape clears
     * `inFlight` without stopping the workflow (documented on
     * {@see \SugarCraft\Crush\Chat::driveWorkflowFiber()}), and THEN the
     * second submit dispatches against a live first one. They now get told,
     * instead of quietly getting two runs that corrupt each other's
     * bookkeeping.
     *
     * Note also what has to be true for the first run to still be live:
     * {@see \SugarCraft\Crush\Agents\AgentWorkerPool::idle()} is the ONLY
     * `Fiber::suspend()` in `src/`, and it is reached only from
     * `executeParallelStage()` on the FORKING executor. A workflow of
     * sequential/pipeline/verification stages suspends zero times and runs to
     * completion inside one `start()`, so it cannot be interleaved with
     * anything. Only the first run needs a parallel stage, though — the second
     * may be of any shape.
     *
     * @var list<?\Fiber>
     */
    private array $liveRunOwners = [];

    /**
     * @param string $model    The model every stage's agent runs on. A workflow
     *        stage names an agent TYPE ('reviewer', 'coder'), never a model, so
     *        the model has to come from the run — and until this parameter
     *        existed it was the literal `claude-sonnet-4-6` written into all six
     *        `new Agent(...)` sites below. That was invisible while nothing
     *        constructed this class; the moment {@see
     *        \SugarCraft\Crush\Cli\Bootstrap::chat()} does, a session on any
     *        other deployment would have had `/workflow run` dispatch its
     *        sub-agents at a model that session never selected. The old literals
     *        remain the defaults so a caller that does not care keeps today's
     *        behaviour.
     * @param string $provider The provider label those agents carry, same
     *        reasoning. {@see \SugarCraft\Crush\Agents\ProcessExecutor} sends the
     *        MODEL to the worker and not this, so this one is what the agent
     *        reports about itself rather than what dispatch keys off.
     * @param PermissionGate|null $permissionGate The launch's safety gate, or
     *        null for an ungated engine. What it does here, EXACTLY, because a
     *        workflow can be authored by a cloned repository and an
     *        over-claimed gate is worse than an absent one:
     *
     *        1. Every {@see SubAgent} this engine builds carries it, so the
     *           field holds this launch's policy rather than the `null` every
     *           workflow-spawned sub-agent used to be built with. Read as a
     *           correct DORMANT value, not as enforcement: the only code that
     *           reads `SubAgent::$permissionGate` is inside
     *           {@see AgentManager::executeSubAgent()}'s streaming-provider
     *           path (AgentManager.php:396, :413, :466-471), and this engine
     *           never enters it — every dispatch here goes through
     *           `AgentWorkerPool::executeOne()` or `executeAll()`, and the
     *           manager's `executeAll()` drains the pool without touching the
     *           field. An earlier version of this note claimed the threading
     *           un-short-circuits `evaluateToolCalls()`; that short-circuit is
     *           unreachable from here whatever the field holds, which is
     *           precisely why item 2 and not item 1 is the enforcement.
     *        2. Before any stage is dispatched — and, since a stage 5 refusal
     *           must not cost four stages of real work, before the FIRST stage
     *           of the run at all ({@see firstDeclarationRefusal()}) — every
     *           tool name the definition DECLARES is put to
     *           {@see PermissionGate::refuses()} and a refusal fails the stage.
     *           That is the one enforcement this layer performs on its own, and
     *           it is the one that answers the repository-authored case: a
     *           checked-in YAML naming a tool this session's mode refuses
     *           cannot dispatch it. {@see refuseDeniedTools()} states, per
     *           mode, which refusals are actually available — the set is
     *           narrower than "denied tools are refused", and under `auto` it
     *           is empty but for explicit Deny rules.
     *
     *        What it does NOT do, today: gate an individual tool call at the
     *        moment a model asks for one. Not because the gate is missing but
     *        because no such call exists on this path —
     *        {@see \SugarCraft\Crush\Agents\ProcessExecutor}'s worker is still
     *        the P1.S5 simulation, so a pool-executed stage makes no provider
     *        request and issues no tool call at all. `Ask` is likewise not a
     *        refusal here: settling one needs the blocking prompt UI, which
     *        this engine has no channel to, so an Ask passes the declaration
     *        check and is left to whichever layer eventually owns the call.
     *
     *        And one corollary a reader of the two paragraphs above would
     *        otherwise assume the other way: a stage's DECLARED tool list is
     *        advisory, not a capability boundary.
     *        {@see \SugarCraft\Crush\Agents\AgentWorkerPool::executeAll()}
     *        (AgentWorkerPool.php:333-342) builds every per-agent request with
     *        `tools: $request->tools` — the FIRST task's list — so a parallel
     *        agent that declared `[Read]` is handed the first agent's tools
     *        instead. That is not a bypass of the check above, because the
     *        check examines every task's declaration and the set actually
     *        handed out is therefore a subset of the checked union; but it does
     *        mean the list is a request for capability, and nothing downstream
     *        enforces that an agent receives only what it declared.
     *
     * @param ?string $environmentRoot The session's resolved project root -
     *        `--root`'s value as {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}
     *        resolved it, or null for an engine whose launch has none. It is
     *        carried to every per-stage `new Agent(...)` below as
     *        {@see \SugarCraft\Crush\Agents\Agent::$environmentRoot}, which is
     *        what makes that agent's last-resort environment capture name the
     *        directory the stage's tools are jailed to rather than the process
     *        directory the launch happened to start in. THE ROOT, NOT A BLOCK:
     *        the stage agents deliberately carry no `EnvironmentBlock` - the
     *        P3.S6 paragraph of {@see \SugarCraft\Crush\Agents\Agent::systemPrompt()}
     *        measures what an attached block would change - so the per-stage
     *        re-render and its five-git-subprocess cost stay as pinned, and
     *        only the anchor of the capture moves. This closes a seam a phase
     *        close-review found between the two assemblers: the Runtime one
     *        captures at the session root and states that invariant in its
     *        own doc-block; these six sites read the process directory until
     *        this parameter carried the root to them. Null keeps the old
     *        behaviour exactly - a caller that does not care changes nothing.
     */
    public function __construct(
        private readonly WorkflowRegistry $registry = new WorkflowRegistry(),
        private readonly AgentWorkerPool $pool = new AgentWorkerPool(),
        private ?AgentManager $agentManager = null,
        private readonly string $model = 'claude-sonnet-4-6',
        private readonly string $provider = 'anthropic',
        private readonly ?PermissionGate $permissionGate = null,
        private readonly ?string $environmentRoot = null,
    ) {}

    /**
     * The model every stage's agent is dispatched on.
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * The provider label every stage's agent carries.
     */
    public function provider(): string
    {
        return $this->provider;
    }

    /**
     * The gate this engine's sub-agents carry, or null when none was given.
     */
    public function permissionGate(): ?PermissionGate
    {
        return $this->permissionGate;
    }

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
        $result = $this->runFromWorkflow($workflow, $context, 0, null, $workflowPath, $workflowPath);
        $this->rememberResult($workflowPath, $result, $workflowPath);

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
     * EITHER IDENTIFIER WORKS: the workflow name/path {@see run()} was called
     * with, or the `<name>-<hash>` run ID the transcript printed for it. The
     * pause FILE is named by the former, because that is the string
     * {@see resume()} has to hand back to `load()`, and {@see pauseFileFor()}
     * is what makes the latter find it again.
     *
     * @param string $workflowId The workflow name/path used when calling run(),
     *        or the run ID printed for that run.
     * @throws WorkflowNotRunningException When no result is found for this workflowId.
     */
    public function pause(string $workflowId): void
    {
        $key = $this->runKeyFor($workflowId);
        if ($key === null) {
            throw new WorkflowNotRunningException(
                "No result found for workflow '{$workflowId}'. Run the workflow first before pausing."
            );
        }

        $result = $this->resultsByName[$key];
        $pauseFile = $this->getPauseFilePath($key);

        $data = [
            // The RUN's own ID, not the key this was stored under: it is what the
            // transcript showed the user, and recording it is what lets a later
            // process resolve that spelling back to this file. `workflowPath` is
            // the loadable name, and the two are deliberately different fields —
            // writing the same string into both is what made a pause file taken
            // after a resume unloadable, since resume()'s identifier is an ID.
            'workflowId' => $result->workflowId,
            'workflowPath' => $this->loadPathsByKey[$key] ?? $key,
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
     * Accepts either identifier, for the reason {@see pause()} does — the run ID
     * the transcript printed, or the workflow name.
     *
     * WHAT THIS DELIBERATELY DOES NOT CHANGE: the resumed result is not written
     * back into {@see $resultsByName}, so a cooperative {@see pause()} after a
     * resume persists the state of the run that was resumed FROM. Remembering it
     * instead would be worse, not better, until stage accounting across a resume
     * is fixed: a resumed {@see WorkflowResult} carries only the stages this call
     * ran, so its `count($result->stageResults)` is a RESUME-relative number, and
     * writing that into `stagesCompleted` would tell the next resume to restart
     * partway through. The two halves of that (an honest total across resumes, and
     * the "Stages completed" line the UI prints from it) belong to the same
     * separate fix and are not attempted here.
     *
     * @param string $workflowId The run ID or the workflow name.
     * @return WorkflowResult The final result after the resumed workflow completes.
     * @throws WorkflowNotRunningException When no pause file exists for this workflow.
     * @throws WorkflowNotFoundException   When the workflow definition can no longer be loaded.
     */
    public function resume(string $workflowId): WorkflowResult
    {
        $pauseFile = $this->pauseFileFor($workflowId);

        if ($pauseFile === null) {
            throw new WorkflowNotRunningException(
                "No paused workflow found with ID '{$workflowId}'"
            );
        }

        $data = json_decode((string) file_get_contents($pauseFile), true);
        $data = is_array($data) ? $data : [];

        $workflowPath = $data['workflowPath'] ?? null;
        if (!is_string($workflowPath) || $workflowPath === '') {
            throw new WorkflowNotRunningException(
                "Pause file for '{$workflowId}' is corrupt: missing 'workflowPath' field"
            );
        }

        $workflow = $this->registry->load($workflowPath);

        // The pause file's OWN name is the pause identity, not the string the
        // user typed: a second interrupt during this resumed run must land on the
        // same file rather than forking a second one under the other spelling.
        // And $loadPath is threaded separately so that file stays loadable — it
        // used to be written as whatever identifier resume() was called with,
        // which for a run ID is not a name load() can resolve.
        $pauseKey = basename($pauseFile, '.json');

        return $this->runFromWorkflow(
            $workflow,
            $data['context'] ?? [],
            $data['stagesCompleted'] ?? 0,
            is_string($data['workflowId'] ?? null) && $data['workflowId'] !== '' ? $data['workflowId'] : $workflowId,
            $pauseKey,
            $workflowPath,
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
     * Accepts either identifier, for the reason {@see pause()} does.
     *
     * @param string $workflowId The run ID or the workflow name.
     * @return WorkflowStatus The status stored in the pause file.
     * @throws WorkflowNotRunningException When no pause file exists for this workflow.
     */
    public function getStatus(string $workflowId): WorkflowStatus
    {
        $pauseFile = $this->pauseFileFor($workflowId);

        if ($pauseFile === null) {
            throw new WorkflowNotRunningException(
                "No pause file found for workflow '{$workflowId}'"
            );
        }

        $data = json_decode((string) file_get_contents($pauseFile), true);
        $data = is_array($data) ? $data : [];
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
     *
     * Anchored to the REGISTRY's own user-tier directory rather than to
     * {@see HomeDirectory::path()}. The two agree for the default registry — it
     * expands `~/.sugar-crush/workflows/` through the same resolver — but they
     * stop agreeing the moment a caller points a registry somewhere else, and
     * this directory is not a cache: {@see resume()} reads `workflowPath` and
     * `context` back out of it and hands them to `load()`. A registry pointed at
     * a trusted directory that still paused into `~` under the stand-in home
     * (which is `sys_get_temp_dir()`, i.e. world-writable) would be resumable
     * from a file any local user could have written — the exact hazard
     * {@see \SugarCraft\Crush\Cli\Bootstrap::workflowEngine()}'s docblock claims
     * this subsystem is held to. Deriving it from the registry is what makes
     * that claim structural instead of incidental.
     */
    private function getPauseFilePath(string $workflowId): string
    {
        if (str_contains($workflowId, '..') || str_contains($workflowId, '/')) {
            throw new \InvalidArgumentException('workflowId must not contain path separators or ..');
        }

        return $this->registry->workflowsPath() . '/' . self::PAUSE_DIR . '/' . $workflowId . '.json';
    }

    /**
     * Remember a run so {@see pause()} can find it under EITHER identifier.
     *
     * One method rather than three assignments at each of the two call sites,
     * because the two sites disagreeing about the keying is precisely the defect
     * this closes: {@see run()} keyed by NAME and the SIGINT handler keyed by the
     * composite run ID, so which spelling `/workflow pause` accepted depended on
     * how the run had ended.
     *
     * @param string      $key      the identifier this result is stored under —
     *                              the name for a fresh run, the pause file's own
     *                              name for a resumed one
     * @param string|null $loadPath the registry name {@see WorkflowRegistry::load()}
     *                              resolves, when there is one (a `runFromPhp()`
     *                              workflow has none)
     */
    private function rememberResult(string $key, WorkflowResult $result, ?string $loadPath): void
    {
        $this->resultsByName[$key] = $result;
        $this->runKeysById[$result->workflowId] = $key;

        if ($loadPath !== null) {
            $this->loadPathsByKey[$key] = $loadPath;
        }
    }

    /**
     * The {@see $resultsByName} key an identifier names, or null for one this
     * engine has no run for.
     *
     * Exact key first, so a workflow literally named `safe-252630d0` still means
     * itself.
     */
    private function runKeyFor(string $identifier): ?string
    {
        if (isset($this->resultsByName[$identifier])) {
            return $identifier;
        }

        return $this->runKeysById[$identifier] ?? null;
    }

    /**
     * The pause file an identifier names, or null when there is none.
     *
     * THREE LOOKUPS, in cost order, because the identifier a user types comes
     * from a transcript that may belong to a previous process:
     *
     *  1. `<identifier>.json` — the name it was paused under.
     *  2. this process's own {@see $runKeysById}, for the run ID of a run it
     *     performed itself.
     *  3. the recorded `workflowId` INSIDE each pause file, which is the only
     *     thing that can map a printed run ID back to a file after the process
     *     that printed it has exited. Bounded by the number of paused workflows,
     *     and each candidate is decoded rather than pattern-matched — deriving
     *     the name by stripping a trailing `-[0-9a-f]{8}` would guess at
     *     {@see generateWorkflowId()}'s shape and get a workflow named
     *     `deploy-1a2b3c4d` wrong.
     *
     * {@see getPauseFilePath()} is still what builds every candidate, so its
     * refusal of `/` and `..` in an identifier applies here unchanged.
     */
    private function pauseFileFor(string $identifier): ?string
    {
        $exact = $this->getPauseFilePath($identifier);
        if (file_exists($exact)) {
            return $exact;
        }

        $key = $this->runKeysById[$identifier] ?? null;
        if ($key !== null) {
            $byKey = $this->getPauseFilePath($key);
            if (file_exists($byKey)) {
                return $byKey;
            }
        }

        $candidates = glob($this->registry->workflowsPath() . '/' . self::PAUSE_DIR . '/*.json') ?: [];
        // Sorted, because two pause files recording the same run ID must resolve
        // the same way on every machine — readdir order is not a contract.
        sort($candidates);

        foreach ($candidates as $candidate) {
            $data = json_decode((string) file_get_contents($candidate), true);
            if (is_array($data) && ($data['workflowId'] ?? null) === $identifier) {
                return $candidate;
            }
        }

        return null;
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
     *                                              (the workflow name/path for run(), the pause file's own name
     *                                              for resume()). Defaults to the resolved workflowId.
     * @param string|null      $loadPath           The registry name this workflow can be re-loaded from, recorded
     *                                              in the pause file so resume() has something load() resolves.
     *                                              Null for a runFromPhp() workflow, which has no registry name.
     * @return WorkflowResult
     */
    private function runFromWorkflow(
        Workflow $workflow,
        array $context,
        int $currentStageIndex,
        ?string $workflowIdOverride,
        ?string $pauseId = null,
        ?string $loadPath = null,
    ): WorkflowResult {
        // The concurrency gate sits HERE rather than inside
        // installInterruptHandlers(), even though the signal-handler stack is
        // the sharpest symptom: that method returns early on a build without
        // pcntl, and $resultsByName/$runKeysById are corrupted by interleaved
        // runs whether or not signals are available. This is also the single
        // funnel every entry point goes through — run(), runFromPhp() and
        // resume() all land here — so there is one place to keep correct.
        $this->enterRun();

        try {
            return $this->runGuardedFromWorkflow(
                $workflow,
                $context,
                $currentStageIndex,
                $workflowIdOverride,
                $pauseId,
                $loadPath,
            );
        } finally {
            // In a finally so a throwing run cannot strand its slot and wedge
            // the engine against every later run.
            array_pop($this->liveRunOwners);
        }
    }

    /**
     * Refuse a run that would INTERLEAVE with one already live on this engine.
     *
     * See {@see $liveRunOwners} for the state and {@see $previousSignalHandlers}
     * for what interleaving breaks. Nested runs — same fiber, or both on the
     * main call stack — are allowed through untouched.
     *
     * @throws \RuntimeException When a run owned by a different fiber is live.
     */
    private function enterRun(): void
    {
        $current = \Fiber::getCurrent();

        foreach ($this->liveRunOwners as $owner) {
            if ($owner !== $current) {
                throw new \RuntimeException(
                    'A workflow is already running on this engine. '
                    . 'Wait for it to finish before starting another — this engine keeps one '
                    . 'result slot per workflow name and one signal-handler frame per run, so '
                    . 'two runs at once would overwrite each other\'s bookkeeping. '
                    . '(Pressing Escape releases the prompt but does not stop the run.)'
                );
            }
        }

        $this->liveRunOwners[] = $current;
    }

    /**
     * {@see runFromWorkflow()}'s body, entered only once the concurrency gate
     * above has admitted this run.
     *
     * @param array<string, mixed> $context
     */
    private function runGuardedFromWorkflow(
        Workflow $workflow,
        array $context,
        int $currentStageIndex,
        ?string $workflowIdOverride,
        ?string $pauseId = null,
        ?string $loadPath = null,
    ): WorkflowResult {
        $startedAt = new \DateTimeImmutable();
        $stageResults = [];
        $totalTokens = 0;
        $totalCost = 0.0;

        // Clone context so we don't mutate the caller's array
        $context = [...$context];

        $resolvedWorkflowId = $workflowIdOverride ?? $this->generateWorkflowId($workflow);
        $interruptId = $pauseId ?? $resolvedWorkflowId;

        // Whole-workflow pre-flight: a stage 5 that declares a refused tool
        // must not cost four stages of real agent work first. See
        // firstDeclarationRefusal() for why the per-stage checks stay as well.
        // Reported as that stage's failure rather than thrown, so the shape a
        // caller sees is the same one a refusal discovered inside the stage
        // produces -- a Failed WorkflowResult carrying the message on the
        // stage it belongs to. Nothing ran, so there are no agents on it and
        // no tokens or cost.
        $refusal = $this->firstDeclarationRefusal($workflow);
        if ($refusal !== null) {
            [$refusedStageName, $refusalMessage] = $refusal;

            return new WorkflowResult(
                workflowId: $resolvedWorkflowId,
                status: WorkflowStatus::Failed,
                stageResults: [new StageResult(
                    stageName: $refusedStageName,
                    status: WorkflowStatus::Failed,
                    error: $refusalMessage,
                    startedAt: $startedAt,
                    completedAt: new \DateTimeImmutable(),
                )],
                context: $context,
                totalTokens: 0,
                totalCost: 0.0,
                startedAt: $startedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }

        $previousAsyncSignals = $this->installInterruptHandlers(
            $interruptId,
            $resolvedWorkflowId,
            $loadPath,
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

        $this->refuseDeniedTools($task, "Stage '{$stageName}'");

        // Interpolate prompt with context
        $interpolatedPrompt = $this->interpolateContext($task->prompt, $context);

        // Build SubAgent
        $agent = new Agent(
            name: $task->name ?? $task->agentType,
            description: $interpolatedPrompt,
            prompt: '', // system prompt is set via CompleteRequest
            model: $this->model,
            provider: $this->provider,
            tools: $task->tools,
            skillNames: [],
            hooks: [],
            isActive: true,
            environmentRoot: $this->environmentRoot,
        );

        $subAgent = new SubAgent(
            id: $stageName . '-' . uniqid(),
            agent: $agent,
            task: $interpolatedPrompt,
            timeout: $task->timeout ?? 300,
            maxRetries: $task->retries ?? 0,
            isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
            permissionGate: $this->permissionGate,
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

        // Every step's declaration is checked BEFORE the first one is
        // dispatched: a refusal is knowable from the definition alone, so
        // discovering it at step 3 would mean two steps' worth of real agent
        // work done on the way to a stage that was always going to be refused.
        foreach ($nestedStages as $nestedStage) {
            $nestedTask = ($nestedStage['tasks'] ?? [])[0] ?? null;
            if ($nestedTask instanceof WorkflowTask) {
                $this->refuseDeniedTools(
                    $nestedTask,
                    "Pipeline stage '{$stageName}' step '" . ($nestedStage['name'] ?? 'unknown') . "'",
                );
            }
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
                model: $this->model,
                provider: $this->provider,
                tools: $task->tools,
                skillNames: [],
                hooks: [],
                isActive: true,
                environmentRoot: $this->environmentRoot,
            );

            $subAgent = new SubAgent(
                id: $stageName . '-' . $nestedStageName . '-' . uniqid(),
                agent: $agent,
                task: $interpolatedPrompt,
                timeout: $task->timeout ?? 300,
                maxRetries: $task->retries ?? 0,
                isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
                permissionGate: $this->permissionGate,
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

        // Both halves up front: a verifier whose declaration is refused would
        // otherwise be discovered only after the task it verifies had run.
        $this->refuseDeniedTools($task, "Verification stage '{$stageName}' task");
        $this->refuseDeniedTools($verifier, "Verification stage '{$stageName}' verifier");

        // --- Run the task ---
        $taskPrompt = $this->interpolateContext($task->prompt, $context);

        $taskAgent = new Agent(
            name: $task->name ?? $task->agentType,
            description: $taskPrompt,
            prompt: '',
            model: $this->model,
            provider: $this->provider,
            tools: $task->tools,
            skillNames: [],
            hooks: [],
            isActive: true,
            environmentRoot: $this->environmentRoot,
        );

        $taskSubAgent = new SubAgent(
            id: $stageName . '-task-' . uniqid(),
            agent: $taskAgent,
            task: $taskPrompt,
            timeout: $task->timeout ?? 300,
            maxRetries: $task->retries ?? 0,
            isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
            permissionGate: $this->permissionGate,
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
            model: $this->model,
            provider: $this->provider,
            tools: $verifier->tools,
            skillNames: [],
            hooks: [],
            isActive: true,
            environmentRoot: $this->environmentRoot,
        );

        $verifierSubAgent = new SubAgent(
            id: $stageName . '-verifier-' . uniqid(),
            agent: $verifierAgent,
            task: $verifierPrompt,
            timeout: $verifier->timeout ?? 300,
            maxRetries: $verifier->retries ?? 0,
            isolation: $verifier->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
            permissionGate: $this->permissionGate,
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

        // Every task's declaration is checked before this method builds
        // anything. The reason is NOT that a refusal mid-loop could fork the
        // first two agents and then refuse the third: the build loop below
        // creates SubAgents only, and not one of them is dispatched until
        // executeAll() is reached, so a check inside it would fork nothing
        // either. The reason is symmetry with the other three stage types and
        // with firstDeclarationRefusal() -- one place per executor where the
        // whole stage's declaration is settled, ahead of any of its work, so
        // "refused stages dispatch nothing" holds for a caller that reaches
        // this method directly rather than through runFromWorkflow().
        foreach ($tasks as $task) {
            if ($task instanceof WorkflowTask) {
                $this->refuseDeniedTools($task, "Parallel stage '{$stageName}'");
            }
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
            model: $this->model,
            provider: $this->provider,
            tools: $firstTask->tools,
            skillNames: [],
            hooks: [],
            isActive: true,
            environmentRoot: $this->environmentRoot,
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
                model: $this->model,
                provider: $this->provider,
                tools: $task->tools,
                skillNames: [],
                hooks: [],
                isActive: true,
                environmentRoot: $this->environmentRoot,
            );

            $subAgents[] = new SubAgent(
                id: $stageName . '-' . $agentIndex . '-' . uniqid(),
                agent: $agent,
                task: $interpolatedPrompt,
                timeout: $task->timeout ?? 300,
                maxRetries: $task->retries ?? 0,
                isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
                permissionGate: $this->permissionGate,
            );
        }

        // Create a fresh pool scoped to this parallel stage so that workflow-level
        // settings (maxConcurrent, stopOnFirstFailure) do not mutate the shared
        // $this->pool instance. The pool's executor is preserved from $this->pool
        // via getExecutor() so that custom executors (e.g., test mocks) are honoured.
        //
        // workerProvider() is carried across for the same reason and was NOT,
        // until this line existed. getExecutor() is null for a pool that builds
        // its own executor, so a stage pool reconstructed from one silently
        // reverted to a default executor with no provider — and a default
        // executor without a provider REFUSES rather than answering
        // (ProcessExecutor::createLiveWorkerScript()). The failure shape was a
        // workflow whose sequential stages consulted a model and whose parallel
        // stages did not, with nothing logged to say so.
        // forkedExecutor() is carried for exactly the reason workerProvider()
        // is: it is pool state getExecutor() cannot answer for (that one reports
        // the SYNCHRONOUS custom executor), so a stage pool rebuilt without it
        // drops back to a self-built worker and the caller's choice of worker
        // silently applies to sequential stages only.
        $pool = new AgentWorkerPool(
            maxConcurrent: $workflow->maxConcurrent,
            executor: $this->pool->getExecutor(),
            workerProvider: $this->pool->workerProvider(),
            forkedExecutor: $this->pool->forkedExecutor(),
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
     * Refuse a task whose DECLARED tool list contains a tool this engine's
     * {@see PermissionGate} denies.
     *
     * The check a workflow definition can actually be held to. A stage names
     * its tools in the definition — `tools: [Bash, Write]` — and since a
     * workflow may be checked into a cloned repository, that list is untrusted
     * input describing capability the session's own policy may refuse. Denying
     * it here, before {@see AgentWorkerPool} is handed anything, is the one
     * evaluation this layer can make on its own: it needs no UI (unlike an
     * `Ask`) and no in-flight tool call (unlike per-call gating, which has
     * nothing to gate while ProcessExecutor's worker is a simulation — see the
     * constructor).
     *
     * Asked through {@see PermissionGate::refuses()}, which takes a
     * {@see ToolDeclaration} and is the gate's READ-ONLY entry point. Not
     * `evaluate()`, and the difference is not stylistic: `evaluate()` records
     * its Auto-mode outcome, and a name-only call classifies as safe, so the
     * first version of this method reset the session gate's consecutive-block
     * counter once per declared tool per stage — disarming the three-strike
     * escalation to `Ask` that is Auto mode's only route to a human decision.
     * See {@see ToolDeclaration} for the measured sequence.
     *
     * WHICH MODES CAN ACTUALLY REFUSE, measured per mode rather than asserted
     * (the table lives on {@see PermissionGate::refuses()}), because "the gate
     * refuses denied declarations" reads as more than it is:
     *
     * - `dont-ask` refuses every non-read-only declaration. The example the
     *   README cites, and the one the tests drive.
     * - `plan` refuses `Edit`, `Write` and `mcp__*`, but NOT `Bash`: Plan's
     *   write test for Bash reads the command's redirection out of the
     *   arguments, and a declaration has none.
     * - `auto` refuses nothing through its mode evaluator — the
     *   {@see \SugarCraft\Crush\Permissions\SafetyClassifier} judges arguments,
     *   and a bare tool name is never dangerous to it. An explicit `Deny` RULE
     *   still refuses under `auto`, and that is the whole of it. Auto's real
     *   enforcement is per-call, at whichever layer runs the call.
     * - `default`, `accept-edits` and `bypass-permissions` refuse nothing; the
     *   first two `Ask`, which is deliberately not a refusal (below).
     *
     * Only `Deny` refuses. `Ask` is not a refusal because settling one requires
     * the blocking permission prompt, and an engine that treated "would have
     * asked" as "no" would make every write-capable stage unrunnable in
     * {@see \SugarCraft\Crush\Permissions\PermissionMode::Default} — a policy
     * change dressed up as a safety check.
     *
     * The declaration carries the NAME only, so an argument-sensitive rule
     * (`Bash(rm *)`) cannot match here and is left to the call site that has
     * the arguments. A name-pattern rule (`Bash`, `Bash*`) does match.
     *
     * @throws \RuntimeException When any declared tool is denied, and when the
     *         declared list is not a list of non-empty strings at all. Both are
     *         thrown rather than returned: {@see runFromWorkflow()} already
     *         wraps every stage executor in a catch that turns a \Throwable
     *         into a failed StageResult carrying its message, so this reaches
     *         the user as the stage failure it is, on all four stage types,
     *         with no new plumbing.
     */
    private function refuseDeniedTools(WorkflowTask $task, string $where): void
    {
        if ($this->permissionGate === null) {
            return;
        }

        foreach ($task->tools as $tool) {
            // Refused, not skipped. The YAML loader cannot produce a non-string
            // tool name any more ({@see WorkflowRegistry::requireToolList()}),
            // but the PHP DSL's `->tools([42])` can, and silently dropping an
            // entry INSIDE a safety check is the failure mode with no upper
            // bound on how wrong it can be: the caller believes the list was
            // examined. CONTRIBUTING.md's "no silent failures" applies with
            // extra force here.
            if (!is_string($tool) || $tool === '') {
                throw new \RuntimeException(sprintf(
                    '%s declares a tool that is not a non-empty tool name (%s), so its permissions cannot be checked.',
                    $where,
                    get_debug_type($tool),
                ));
            }

            if ($this->permissionGate->refuses(new ToolDeclaration($tool))) {
                throw new \RuntimeException(sprintf(
                    '%s declares tool "%s", which this session\'s permission mode (%s) denies.',
                    $where,
                    $tool,
                    $this->permissionGate->mode()->value,
                ));
            }
        }
    }

    /**
     * The FIRST declaration refusal anywhere in a whole workflow, or null when
     * nothing in it is refused.
     *
     * The per-stage checks below ({@see executeStage()},
     * {@see executePipelineStage()}, {@see executeVerificationStage()},
     * {@see executeParallelStage()}) each fire as their own stage starts, which
     * is one level too late for the argument they were introduced with: a
     * 5-stage workflow whose stage 5 declares a refused tool ran four stages'
     * worth of real agent work first. The reason a pipeline checks all of its
     * steps up front is the same reason a workflow must check all of its
     * stages up front — the refusal is knowable from the definition alone,
     * before anything is dispatched.
     *
     * The per-stage checks stay, and are not redundant: every stage executor is
     * a private method with its own build-then-dispatch sequence, and they are
     * what guarantees the property for a future caller that runs one stage
     * without coming through {@see runFromWorkflow()}. This is the earlier of
     * two nets, not a replacement for the tighter one.
     *
     * @return array{0: string, 1: string}|null The offending stage's name and
     *         the refusal message, so the caller can report it as that stage's
     *         failure rather than as a nameless workflow error.
     */
    private function firstDeclarationRefusal(Workflow $workflow): ?array
    {
        if ($this->permissionGate === null) {
            return null;
        }

        foreach ($workflow->stages as $stage) {
            $stageName = $stage['name'] ?? 'unknown';
            $stageType = $stage['type'] ?? '';

            // Every WorkflowTask a stage of this type will dispatch, paired
            // with the label refuseDeniedTools() would have used for it, so
            // the up-front message is the same text the per-stage check emits.
            $checks = [];
            if ($stageType === 'stage') {
                $first = ($stage['tasks'] ?? [])[0] ?? null;
                $checks[] = [$first, "Stage '{$stageName}'"];
            } elseif ($stageType === 'parallel') {
                foreach ($stage['tasks'] ?? [] as $task) {
                    $checks[] = [$task, "Parallel stage '{$stageName}'"];
                }
            } elseif ($stageType === 'pipeline') {
                foreach ($stage['stages'] ?? [] as $nested) {
                    $checks[] = [
                        ($nested['tasks'] ?? [])[0] ?? null,
                        "Pipeline stage '{$stageName}' step '" . ($nested['name'] ?? 'unknown') . "'",
                    ];
                }
            } elseif ($stageType === 'verification') {
                $checks[] = [$stage['task'] ?? null, "Verification stage '{$stageName}' task"];
                $checks[] = [$stage['verifier'] ?? null, "Verification stage '{$stageName}' verifier"];
            }

            foreach ($checks as [$task, $where]) {
                if (!$task instanceof WorkflowTask) {
                    // A malformed stage is the stage executor's error to
                    // report, with the message it already has for it. Not
                    // this method's — pre-flight only answers the permission
                    // question.
                    continue;
                }

                try {
                    $this->refuseDeniedTools($task, $where);
                } catch (\RuntimeException $e) {
                    return [$stageName, $e->getMessage()];
                }
            }
        }

        return null;
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
     * child, leaves without touching pause() at all; only the parent
     * persists anything.
     *
     * THE TWO EXITS IN THAT HANDLER ARE DELIBERATELY DIFFERENT SHAPES, and
     * the difference is the point rather than an oversight:
     *
     *  - THE FORKED CHILD leaves through {@see ForkedChild::exitNow()}, which
     *    SIGKILLs itself and so runs no destructor and no shutdown function
     *    over the copy of this process's object graph it is holding. Nothing
     *    is lost by that: an AgentWorkerPool worker's entire IPC surface is
     *    `file_put_contents()` ({@see AgentWorkerPool::storeResult()} and
     *    {@see AgentWorkerPool::publishProgress()}), which is already in the
     *    kernel by the time it returns, so PHP's shutdown sequence has
     *    nothing of the child's left to flush. What it would run instead is
     *    somebody else's cleanup: every destructor and every
     *    `register_shutdown_function` callback in the inherited graph, N extra
     *    times — including {@see AgentWorkerPool::__destruct()}, whose
     *    `$resultDirOwnerPid` check is the only thing standing between a
     *    forked worker's shutdown and the deletion of the result directory
     *    the parent is still polling. (It is NOT PHPUnit's after-test hooks:
     *    an exiting child never returns into the runner, so those fire in the
     *    parent only. Measured — see
     *    {@see \SugarCraft\Crush\Tests\Support\ForkedChildExitConventionTest}.)
     *    The cost is that the child now dies by signal, so a parent reading
     *    its wait status sees `wifsignaled()`/SIGKILL rather than exit code
     *    130/143; {@see AgentWorkerPool::workerDiedResult()} already reports
     *    that shape ("was killed by signal 9") and no in-repo caller branches
     *    on the code.
     *
     *  - THE INSTALLING PROCESS keeps a plain `exit()`, and MUST. It is not a
     *    fork: {@see \SugarCraft\Crush\Chat::driveWorkflowFiber()} resumes
     *    `run()` inside a \Fiber on the live TUI's own ReactPHP loop, so the
     *    process that installs this handler is the process holding the
     *    raw-mode terminal. candy-core's `PosixBackend::restore()` is
     *    PID-aware and THIS pid is the owner, so the plain exit's destructor
     *    chain is what puts the user's terminal back into cooked mode after
     *    Ctrl-C; a SIGKILL here would leave them typing blind. It is also
     *    what runs {@see \SugarCraft\Crush\Cli\Bootstrap}'s
     *    `register_shutdown_function` hook, without which every MCP server
     *    this launch started is orphaned on every interrupt.
     *
     * @param string              $interruptId         Identifier used to correlate the pause file with this run.
     * @param string              $resolvedWorkflowId  The workflow ID the in-flight run is executing under.
     * @param string|null         $loadPath            The registry name the run can be re-loaded from, or null.
     * @param \DateTimeImmutable  $startedAt            When this run originally started.
     * @param array               $context      Reference to the live workflow context.
     * @param StageResult[]       $stageResults Reference to the live list of completed stage results.
     * @param int                 $totalTokens  Reference to the live running token total.
     * @param float               $totalCost    Reference to the live running cost total.
     * The SIGINT/SIGTERM dispositions in effect on the way in are captured into
     * {@see $previousSignalHandlers} for {@see restoreInterruptHandlers()} to put
     * back; see that method for why putting them back is not the same thing as
     * resetting them to the default.
     *
     * @return bool|null The previous pcntl_async_signals() setting to restore in
     *                    restoreInterruptHandlers() once this run finishes, or null
     *                    when handlers were not installed (pcntl unavailable).
     */
    private function installInterruptHandlers(
        string $interruptId,
        string $resolvedWorkflowId,
        ?string $loadPath,
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

        // Captured BEFORE the handlers below overwrite them, so
        // restoreInterruptHandlers() can put back what the calling process had
        // rather than guessing that it had nothing. pcntl_signal_get_handler()
        // answers with the callable, or SIG_DFL/SIG_IGN as an int, and
        // pcntl_signal() accepts either form back — the same round trip
        // {@see \SugarCraft\Core\Program} does around its own handlers.
        $frame = [];
        if (function_exists('pcntl_signal_get_handler')) {
            foreach ([\SIGINT, \SIGTERM] as $signo) {
                $frame[$signo] = pcntl_signal_get_handler($signo);
            }
        }
        // Pushed even when it is empty (no pcntl_signal_get_handler()), so the
        // stack stays balanced with the pops in restoreInterruptHandlers() —
        // an unbalanced stack would have one run restoring another's frame.
        $this->previousSignalHandlers[] = $frame;

        $installPid = getmypid();

        $handler = function (int $signo) use (
            $interruptId,
            $resolvedWorkflowId,
            $loadPath,
            $startedAt,
            $installPid,
            &$context,
            &$stageResults,
            &$totalTokens,
            &$totalCost,
        ): void {
            // See the fork-safety note on installInterruptHandlers(): a
            // forked 'parallel'-stage child inherits this same handler. It
            // must not call pause() — that's the parent's job for this run —
            // and it must not run PHP's shutdown sequence over the copy of
            // the parent's object graph it is holding, so it leaves through
            // ForkedChild::exitNow() rather than a plain exit(). The code is
            // still computed and passed for the signal it stands for, but a
            // SIGKILLed process reports `wifsignaled()`, not this code; see
            // the doc-block for why that trade is the right one here and the
            // wrong one for the installing process below.
            if (getmypid() !== $installPid) {
                ForkedChild::exitNow($signo === \SIGINT ? 130 : 143);
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
            // two code paths — INCLUDING the identifier bookkeeping, which is
            // what the two sites used to disagree about: this one keyed the
            // result map by the run ID while run() keyed it by the name, so
            // whether `/workflow pause <id>` worked depended on how the run had
            // ended.
            $this->rememberResult($interruptId, $partialResult, $loadPath);

            try {
                $this->pause($interruptId);
            } catch (\Throwable) {
                // Best-effort: still exit below even if pause() itself
                // couldn't write (e.g. unwritable pause dir) — resuming
                // execution as though the signal never arrived would be
                // worse than exiting with nothing captured.
            }

            // DELIBERATELY a plain exit() and not ForkedChild::exitNow():
            // this branch only ever runs in the process that installed the
            // handler, which is the live TUI/CLI process itself. Its
            // shutdown sequence is load-bearing here — the PID-aware
            // candy-core terminal restore and Bootstrap's MCP-server stop
            // hook both hang off it. See installInterruptHandlers()'s
            // doc-block.
            exit($signo === \SIGINT ? 130 : 143);
        };

        pcntl_signal(\SIGINT, $handler);
        pcntl_signal(\SIGTERM, $handler);

        return $previousAsyncSignals;
    }

    /**
     * Put the SIGINT/SIGTERM dispositions, and the pcntl_async_signals()
     * setting, back to whatever was in effect before installInterruptHandlers()
     * ran — so neither the signal handlers nor the async-dispatch mode leak past
     * this run() into the rest of the calling process (e.g. the PHPUnit process
     * running this very test suite).
     *
     * RESTORED, not reset. This used to `pcntl_signal(SIGINT, SIG_DFL)`, which
     * is only equivalent when the caller had no handler of its own — and the
     * caller that matters does: candy-core's `Program` installs a SIGINT closure
     * that flips `$running = false` and stops the loop, which is how a
     * `bin/sugarcrush` session shuts down gracefully and how PHP gets to run its
     * shutdown sequence at all — `SugarCraft\Core\Util\Tty\PosixBackend` puts
     * termios back from its DESTRUCTOR, which a process killed under SIG_DFL
     * never reaches. Since {@see \SugarCraft\Crush\Chat} dispatches `/workflow run`
     * synchronously inside `Chat::update()`, resetting to the default here left
     * every session that ran one workflow dying on an external `kill -INT` with
     * the terminal still in raw mode inside the alt screen. Nothing in the TUI
     * noticed, because the raw mode it runs under clears ISIG and an
     * interactive Ctrl+C therefore arrives as a byte rather than as a signal at
     * all: `PosixBackend` delegates to candy-pty's `TermiosFactory`, whose two
     * implementations are `SugarCraft\Pty\Posix\PosixTermios::makeRaw()`
     * (libc `cfmakeraw()`) and `SugarCraft\Pty\Posix\SttyTermios::makeRaw()`
     * (`stty raw -echo`) — both of which clear ISIG by definition. So this was
     * an external-signal-only defect, and the async half of the same
     * restoration was already correct, which is what made the broken half easy
     * to miss.
     *
     * @param bool $previousAsyncSignals The pcntl_async_signals() setting to
     *                                   restore, as returned by installInterruptHandlers().
     */
    private function restoreInterruptHandlers(bool $previousAsyncSignals): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        // This run's own frame. Popped, and a pop is correct because the only
        // other run that can be live on this engine is one NESTED inside this
        // one — which shares this call stack and has therefore already
        // restored and popped its own frame. Runs that could finish in the
        // other order (two fibers interleaving) are refused before they start;
        // see {@see $liveRunOwners}. Without that refusal this pop would hand
        // back the other run's frame and leave a Ctrl-C pausing the wrong
        // workflow.
        $frame = array_pop($this->previousSignalHandlers) ?? [];

        foreach ([\SIGINT, \SIGTERM] as $signo) {
            // SIG_DFL when nothing was captured: the only way that happens is a
            // build without pcntl_signal_get_handler(), where "what it was
            // before" is unknowable and the pre-fix behaviour is the honest
            // fallback.
            pcntl_signal($signo, $frame[$signo] ?? \SIG_DFL);
        }

        pcntl_async_signals($previousAsyncSignals);
    }
}
