<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\HomeDirectory;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\AgentStatus;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\EngineExecutor;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\ToolDeclaration;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Tools\Tool;

/**
 * Executes workflows sequentially, stage by stage.
 *
 * Coordinates with AgentWorkerPool to run agent tasks for each stage,
 * collecting results into a WorkflowResult. Handles context interpolation
 * so that `{{variable}}` tokens in prompts are replaced with context values,
 * `{{stageName.output}}` tokens reference prior stage outputs, and
 * `{{name.results}}` tokens reference one agent's output from any stage type
 * (see {@see RESULTS_CONTEXT_KEY} and {@see resultKey()}).
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
     * The context entry that holds every agent's output by result name, for
     * `{{name.results}}` (AUDIT WF-3).
     *
     * A namespace of its own because results used to live beside the user's
     * run context, as `$context[<agent>]['results']`: `/workflow run three
     * coder=x` made `$context['coder']` a string, and the write crashed the
     * stage AFTER its agent had run, dropping that agent's tokens. A
     * `key=val` pair can still spell `@results=…`, so the run entry points
     * refuse every `@`-prefixed key ({@see refuseReservedContextKeys()})
     * rather than trusting the spelling to be unlikely. It lives inside the
     * context so the pause file carries it and a resumed run can still
     * interpolate the results of the stages it skipped.
     *
     * @see resultKey() for how each stage type names its agents.
     */
    public const RESULTS_CONTEXT_KEY = '@results';

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
     * Every run whose stage loop is executing right now, keyed by a per-engine
     * sequence number, innermost (nested) last.
     *
     * AUDIT WF-2: {@see pause()} used to read only {@see $resultsByName}, which
     * {@see rememberResult()} fills after `run()` RETURNS — so the only thing
     * `/workflow pause <id>` could act on was a run that had already ended,
     * and pausing a name with a live run snapshotted the PREVIOUS run of that
     * name. A run is genuinely pausable while it is live: `Chat` drives it in
     * a `\Fiber` that suspends inside {@see AgentWorkerPool::idle()} while the
     * forked agents work, and `Chat::update()` runs in that gap. This map is
     * what that call finds.
     *
     * - `key` / `workflowId` / `loadPath`: the run's pause identity — the same
     *   three values {@see installInterruptHandlers()} closes over.
     * - `snapshot`: builds a {@see WorkflowResult} from the loop's LIVE
     *   context, stage results and totals (by reference, as the interrupt
     *   handler does), so a pause records exactly the stages that have
     *   finished.
     * - `pauseRequested`: set by a live {@see pause()}; the stage loop reads it
     *   before starting each stage and stops with {@see WorkflowStatus::Paused}.
     *
     * Interleaving is still refused by {@see $liveRunOwners}, so at most one
     * chain of NESTED runs is ever in here.
     *
     * @var array<int, array{key: string, workflowId: string, loadPath: ?string, snapshot: \Closure(WorkflowStatus): WorkflowResult, pauseRequested: bool}>
     */
    private array $liveRuns = [];

    /** Source of {@see $liveRuns} keys. */
    private int $liveRunSequence = 0;

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
     *           path, and this engine
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
        /**
         * The session's model-facing tool set — {@see Tool} instances, the
         * same `list<Tool>` {@see AgentManager} receives as its `$toolRegistry`
         * and for the same reason: a {@see CompleteRequest::$tools} entry must
         * be an OBJECT (every provider calls `->name()` or type-hints the
         * closure parameter as `Tool`), while a workflow task's `tools` is a
         * list of NAMES. {@see resolveRequestTools()} is the seam that turns
         * one into the other; without a registry the turn cannot be made, and
         * the request goes out with `tools: null` rather than with strings
         * that would fatal at the provider (E641).
         *
         * NULL, THE DEFAULT, IS NOT AN EMPTY REGISTRY — it mirrors the exact
         * semantics {@see AgentManager::__construct()} documents: null means
         * "the caller has no tool set" (every pre-wiring construction site,
         * test doubles included) and stages run toolless as they do today; an
         * empty array means "a registry exists and offers nothing", so any
         * declaration at all is unresolvable and refused loudly.
         */
        private readonly ?array $toolRegistry = null,
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
     * Point this engine's stages at the session's CURRENT backend, so every
     * stage agent runs that backend's real tool loop ({@see EngineExecutor}).
     *
     * Late and mutable for the same reason as {@see setAgentManager()}: the
     * pool is built once at launch, while Chat replaces its backend on a
     * provider switch — so Chat re-binds on every construction. A pool without
     * an EngineExecutor (a test double, an embedder's own) is left untouched.
     */
    public function bindEngineBackend(EngineBackend $engine): void
    {
        $executor = $this->pool->forkedExecutor();
        if ($executor instanceof EngineExecutor) {
            $executor->bind($engine);
        }
    }

    /**
     * Load a workflow from the registry and execute it with the given context.
     *
     * @param string $workflowPath Workflow name (loaded from registry).
     * @param array  $context      Key-value pairs for {{variable}} interpolation.
     * @return WorkflowResult
     * @throws \InvalidArgumentException When a context key is reserved (starts with `@`).
     * @throws WorkflowNotFoundException When the workflow does not exist.
     * @throws WorkflowLoadException When the workflow cannot be loaded.
     */
    public function run(string $workflowPath, array $context = []): WorkflowResult
    {
        self::refuseReservedContextKeys($context);
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
     * @throws \InvalidArgumentException When a context key is reserved (starts with `@`).
     * @throws WorkflowLoadException When the input cannot produce a Workflow.
     */
    public function runFromPhp(callable|string $workflowClass, array $context = []): WorkflowResult
    {
        self::refuseReservedContextKeys($context);

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
     * Writes a pause file to `<workflowsPath>/.running/<name>.json` containing:
     * the stages that SUCCEEDED, the context, their stage results, the
     * token/cost totals, and timing. Three cases, one serializer
     * ({@see writePauseFile()}) for all of them and for the SIGINT/SIGTERM
     * handler:
     *
     *  - A LIVE run ({@see $liveRuns}) — `/workflow pause` while `Chat`'s
     *    workflow fiber is suspended between agent polls. The file is written
     *    at once from the stages that have finished, and the run is asked to
     *    stop: its stage loop checks before starting each stage, ends with
     *    {@see WorkflowStatus::Paused}, and rewrites the file with the final
     *    set. The stage in flight is NOT interrupted — it finishes, is
     *    recorded, and the next one is not started. If that stage fails,
     *    the run reports Failed and the file keeps the successful prefix, so
     *    a resume re-runs the failed stage. If it was the LAST stage, the run
     *    simply completed and the file is withdrawn: there is nothing left to
     *    resume.
     *  - A FINISHED run this engine remembers. A FAILED one is the recovery
     *    path — pause, fix the cause, resume — and resume re-runs the stage
     *    that failed. A COMPLETED one is still accepted (it records the run,
     *    and resuming it runs nothing and reports completed).
     *  - Neither: {@see WorkflowNotRunningException}.
     *
     * AUDIT WF-2: `stagesCompleted` used to be `count($result->stageResults)`,
     * which for a failed run INCLUDES the stage that failed — so resume
     * skipped it, fed an empty `{{b.output}}` downstream and reported success.
     * Only the successful prefix is counted and persisted now (the loop fails
     * fast, so successes are always a prefix).
     *
     * Granularity is still per whole stage: a 'parallel' stage still running
     * when the pause lands is not in the file, and is re-run from scratch on
     * resume — there is no partial-credit capture for an in-progress parallel
     * sub-stage.
     *
     * EITHER IDENTIFIER WORKS: the workflow name/path {@see run()} was called
     * with, or the `<name>-<hash>` run ID the transcript printed for it. The
     * pause FILE is named by the former, because that is the string
     * {@see resume()} has to hand back to `load()`, and {@see pauseFileFor()}
     * is what makes the latter find it again.
     *
     * @param string $workflowId The workflow name/path used when calling run(),
     *        or the run ID printed for that run.
     * @throws WorkflowNotRunningException When no live or finished run is found for this workflowId.
     */
    public function pause(string $workflowId): void
    {
        $token = $this->liveRunFor($workflowId);
        if ($token !== null) {
            $this->liveRuns[$token]['pauseRequested'] = true;
            $frame = $this->liveRuns[$token];
            $this->writePauseFile($frame['key'], ($frame['snapshot'])(WorkflowStatus::Paused), $frame['loadPath']);

            return;
        }

        $key = $this->runKeyFor($workflowId);
        if ($key === null) {
            throw new WorkflowNotRunningException(
                "No result found for workflow '{$workflowId}'. Run the workflow first before pausing."
            );
        }

        $this->writePauseFile($key, $this->resultsByName[$key], $this->loadPathsByKey[$key] ?? null);
    }

    /**
     * Reload a paused workflow from its persisted state and continue execution.
     *
     * Accepts either identifier, for the reason {@see pause()} does — the run ID
     * the transcript printed, or the workflow name.
     *
     * THE RESUMED RESULT IS ABSOLUTE, not resume-relative. The paused run's
     * successful stage results are deserialized and seed the resumed run, and
     * its token/cost totals and start time carry over, so the returned
     * {@see WorkflowResult} covers the whole run: its stage list, "Stages
     * completed", and totals are what the run as a whole did (AUDIT WF-2: the
     * paused half's tokens and cost used to vanish from the totals). Because
     * it is absolute, it is also remembered ({@see rememberResult()}) under
     * the pause file's name, so a later {@see pause()} or {@see getStatus()}
     * of the resumed run answers for the resumed run rather than for the one
     * it was resumed from.
     *
     * THE PAUSE FILE IS CONSUMED once the resumed run finishes — completed or
     * failed. It used to be left behind, so `/workflow status` said `paused`
     * forever and every further resume re-ran the tail. A resumed run that is
     * itself paused leaves the fresh file its pause wrote; a second resume of
     * a run that has finished throws {@see WorkflowNotRunningException} (pause
     * the remembered result again to retry a failure). A resume that throws
     * before finishing leaves the file untouched.
     *
     * How many stages to skip: the file's `stagesCompleted`, capped at the
     * successful prefix of its recorded `stageResults` when it records any —
     * a file written before WF-2 counted the failed stage too, and re-running
     * a stage is recoverable where skipping it is not. A file that records no
     * stage results (an older hand-written shape) is trusted for its count,
     * and the skipped stages are represented by results rebuilt from the
     * context's `<stage>.output` entries.
     *
     * @param string $workflowId The run ID or the workflow name.
     * @return WorkflowResult The final result after the resumed workflow completes, fails, or is paused again.
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
        $context = self::withSanitizedResults(is_array($data['context'] ?? null) ? $data['context'] : []);
        [$stagesCompleted, $prior] = $this->priorProgress($data, $workflow, $context);

        // The pause file's OWN name is the pause identity, not the string the
        // user typed: a second interrupt during this resumed run must land on the
        // same file rather than forking a second one under the other spelling.
        // And $loadPath is threaded separately so that file stays loadable — it
        // used to be written as whatever identifier resume() was called with,
        // which for a run ID is not a name load() can resolve.
        $pauseKey = basename($pauseFile, '.json');

        $pauseRequested = false;
        $result = $this->runFromWorkflow(
            $workflow,
            $context,
            $stagesCompleted,
            is_string($data['workflowId'] ?? null) && $data['workflowId'] !== '' ? $data['workflowId'] : $workflowId,
            $pauseKey,
            $workflowPath,
            $prior,
            $pauseRequested,
        );
        $this->rememberResult($pauseKey, $result, $workflowPath);

        // A pause requested during this resume rewrote (or withdrew) the file
        // itself, and that file is the newer truth. Otherwise the file is the
        // one this call consumed.
        if (!$pauseRequested && is_file($pauseFile) && !@unlink($pauseFile) && is_file($pauseFile)) {
            throw new \RuntimeException(
                "Workflow '{$workflowId}' finished ({$result->status->value}) but its pause file "
                . "'{$pauseFile}' could not be removed, so it would still read as paused"
            );
        }

        return $result;
    }

    /**
     * How far a pause file's run got: the stage index to resume at, and the
     * prior progress to seed the resumed run with. See {@see resume()} for the
     * rules.
     *
     * @param array<string, mixed> $data    the decoded pause file
     * @param array<string, mixed> $context the pause file's context
     * @return array{0: int, 1: array{stageResults: list<StageResult>, totalTokens: int, totalCost: float, startedAt: \DateTimeImmutable}}
     */
    private function priorProgress(array $data, Workflow $workflow, array $context): array
    {
        $recorded = is_numeric($data['stagesCompleted'] ?? null) ? max(0, (int) $data['stagesCompleted']) : 0;
        $rows = is_array($data['stageResults'] ?? null) ? array_values($data['stageResults']) : [];

        $succeeded = [];
        foreach ($rows as $row) {
            $stage = is_array($row) ? $this->deserializeStageResult($row) : null;
            if ($stage === null || !$stage->isSuccess()) {
                break;
            }
            $succeeded[] = $stage;
        }

        $skip = $rows === [] ? $recorded : min($recorded, count($succeeded));
        $skip = min($skip, count($workflow->stages));
        $prior = array_slice($succeeded, 0, $skip);

        // Stages the file counts but does not record: stand-ins built from the
        // definition and the output the context kept, so the resumed result's
        // stage list still has one entry per stage the run has done.
        $stages = array_values($workflow->stages);
        for ($i = count($prior); $i < $skip; $i++) {
            $name = (string) ($stages[$i]['name'] ?? "stage-{$i}");
            $output = $context[$name . '.output'] ?? null;
            $prior[] = new StageResult(
                stageName: $name,
                status: WorkflowStatus::Completed,
                output: is_string($output) ? $output : null,
            );
        }

        $totalTokens = 0;
        $totalCost = 0.0;
        foreach ($prior as $stage) {
            $totalTokens += $this->sumTokens($stage);
            $totalCost += $this->sumCost($stage);
        }

        return [$skip, [
            'stageResults' => $prior,
            // The file's totals when it has them: they include what a failed
            // attempt spent, which is real spend even though its stage result is
            // not carried forward.
            'totalTokens' => is_numeric($data['totalTokens'] ?? null) ? (int) $data['totalTokens'] : $totalTokens,
            'totalCost' => is_numeric($data['totalCost'] ?? null) ? (float) $data['totalCost'] : $totalCost,
            'startedAt' => self::parseTime($data['startedAt'] ?? null) ?? new \DateTimeImmutable(),
        ]];
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
     * Return the current status of a workflow run.
     *
     * Accepts either identifier, for the reason {@see pause()} does. Answered
     * from, in order:
     *
     *  1. a LIVE run on this engine — {@see WorkflowStatus::Running}, or
     *     {@see WorkflowStatus::Paused} once a pause has been requested. First,
     *     because a live run is the newest truth: a resumed run's pause file
     *     still reads `paused` while the resume is executing.
     *  2. the pause file — its recorded status.
     *  3. a finished run this engine remembers — its final status, so a run
     *     that completed, failed, or finished a resume is no longer reported
     *     as whatever it last was on disk.
     *
     * AUDIT WF-2: this used to read only the pause file, so a live run, and a
     * finished one never paused, were "not found", while a resumed run that
     * had finished still read `paused` from the file resume() left behind.
     *
     * @param string $workflowId The run ID or the workflow name.
     * @return WorkflowStatus
     * @throws WorkflowNotRunningException When this engine has no live or remembered run and no pause file exists.
     */
    public function getStatus(string $workflowId): WorkflowStatus
    {
        $token = $this->liveRunFor($workflowId);
        if ($token !== null) {
            return $this->liveRuns[$token]['pauseRequested'] ? WorkflowStatus::Paused : WorkflowStatus::Running;
        }

        $pauseFile = $this->pauseFileFor($workflowId);

        if ($pauseFile === null) {
            $key = $this->runKeyFor($workflowId);
            if ($key !== null) {
                return $this->resultsByName[$key]->status;
            }

            throw new WorkflowNotRunningException(
                "No pause file found for workflow '{$workflowId}', and no run of it on this engine"
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
     * {@see serializeStageResult()}'s inverse, for seeding a resumed run. Null
     * for a row too damaged to stand for a stage (no name, unknown status).
     *
     * @param array<string, mixed> $row
     */
    private function deserializeStageResult(array $row): ?StageResult
    {
        $status = is_string($row['status'] ?? null) ? WorkflowStatus::tryFrom($row['status']) : null;
        if ($status === null || !is_string($row['stageName'] ?? null)) {
            return null;
        }

        $agents = [];
        foreach (is_array($row['agents'] ?? null) ? $row['agents'] : [] as $agent) {
            $agentStatus = is_array($agent) && is_string($agent['status'] ?? null)
                ? AgentStatus::tryFrom($agent['status'])
                : null;
            if ($agentStatus === null) {
                continue;
            }
            $agents[] = new AgentResult(
                agentId: (string) ($agent['agentId'] ?? ''),
                status: $agentStatus,
                output: is_string($agent['output'] ?? null) ? $agent['output'] : null,
                error: is_string($agent['error'] ?? null) ? new \RuntimeException($agent['error']) : null,
                tokensUsed: is_numeric($agent['tokensUsed'] ?? null) ? (int) $agent['tokensUsed'] : 0,
                costUsd: is_numeric($agent['costUsd'] ?? null) ? (float) $agent['costUsd'] : 0.0,
                startedAt: self::parseTime($agent['startedAt'] ?? null),
                completedAt: self::parseTime($agent['completedAt'] ?? null),
            );
        }

        return new StageResult(
            stageName: $row['stageName'],
            status: $status,
            output: is_string($row['output'] ?? null) ? $row['output'] : null,
            error: is_string($row['error'] ?? null) ? $row['error'] : null,
            agents: $agents,
            startedAt: self::parseTime($row['startedAt'] ?? null) ?? new \DateTimeImmutable(),
            completedAt: self::parseTime($row['completedAt'] ?? null),
        );
    }

    private static function parseTime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The stages a run has DONE — the successful prefix of its results.
     *
     * The loop fails fast, so successes are always a prefix and the first
     * failure ends it; stopping at the first non-success also keeps a stage
     * that is neither out of the count, since re-running a stage on resume is
     * recoverable and skipping one is not.
     *
     * @return list<StageResult>
     */
    private static function completedStages(WorkflowResult $result): array
    {
        $done = [];
        foreach ($result->stageResults as $stage) {
            if (!$stage->isSuccess()) {
                break;
            }
            $done[] = $stage;
        }

        return $done;
    }

    /**
     * Write the pause file for $result under $key — the ONE serializer behind
     * a live pause, a finished-run pause, the stage loop's own stop, and the
     * SIGINT/SIGTERM handler, so the format cannot drift between them.
     *
     * Only the successful prefix is persisted ({@see completedStages()}), and
     * `stagesCompleted` is its length, so a resume re-runs a stage that failed
     * instead of skipping it (AUDIT WF-2). The totals are the run's whole
     * spend, a failed attempt's included.
     */
    private function writePauseFile(string $key, WorkflowResult $result, ?string $loadPath): void
    {
        $completed = self::completedStages($result);

        $data = [
            // The RUN's own ID, not the key this was stored under: it is what the
            // transcript showed the user, and recording it is what lets a later
            // process resolve that spelling back to this file. `workflowPath` is
            // the loadable name, and the two are deliberately different fields —
            // writing the same string into both is what made a pause file taken
            // after a resume unloadable, since resume()'s identifier is an ID.
            'workflowId' => $result->workflowId,
            'workflowPath' => $loadPath ?? $key,
            'status' => WorkflowStatus::Paused->value,
            'stagesCompleted' => count($completed),
            'context' => $result->context,
            'stageResults' => array_map(
                fn(StageResult $sr) => $this->serializeStageResult($sr),
                $completed,
            ),
            'totalTokens' => $result->totalTokens,
            'totalCost' => $result->totalCost,
            'startedAt' => $result->startedAt->format(\DateTimeInterface::ATOM),
            'pausedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        // Audit M4: the pre-fix write ignored file_put_contents' return (a
        // failed pause silently lost the run) and wrote the live path
        // directly (a crash mid-write tore the file resume() needs).
        // AtomicJsonFile both throws on any failure and publishes by rename;
        // callers that must survive a lost pause — the signal handler does —
        // already wrap this in their own catch. 0600: the file holds the
        // workflow's accumulated context verbatim.
        AtomicJsonFile::new($this->getPauseFilePath($key))
            ->withPermissions(0600)
            ->write($data);
    }

    /**
     * The {@see $liveRuns} key of the live run an identifier names — its pause
     * key (the name) or its run ID — innermost first, or null when none is
     * live.
     */
    private function liveRunFor(string $identifier): ?int
    {
        foreach (array_reverse($this->liveRuns, true) as $token => $frame) {
            if ($frame['key'] === $identifier || $frame['workflowId'] === $identifier) {
                return $token;
            }
        }

        return null;
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
     * @param array{stageResults?: list<StageResult>, totalTokens?: int, totalCost?: float, startedAt?: \DateTimeImmutable} $prior
     *                                              A resumed run's progress before this call (see
     *                                              priorProgress()), so the result is absolute. Empty for a fresh run.
     * @param bool             $pauseRequested     Out: whether a pause() was requested while this run was live —
     *                                              in which case the run rewrote or withdrew its own pause file.
     * @return WorkflowResult
     */
    private function runFromWorkflow(
        Workflow $workflow,
        array $context,
        int $currentStageIndex,
        ?string $workflowIdOverride,
        ?string $pauseId = null,
        ?string $loadPath = null,
        array $prior = [],
        bool &$pauseRequested = false,
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
                $prior,
                $pauseRequested,
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
     * @param array{stageResults?: list<StageResult>, totalTokens?: int, totalCost?: float, startedAt?: \DateTimeImmutable} $prior
     */
    private function runGuardedFromWorkflow(
        Workflow $workflow,
        array $context,
        int $currentStageIndex,
        ?string $workflowIdOverride,
        ?string $pauseId,
        ?string $loadPath,
        array $prior,
        bool &$pauseRequested,
    ): WorkflowResult {
        // A resumed run starts from the paused run's progress, so everything
        // it reports — stage list, totals, start time — covers the whole run
        // and a later pause of it records the right stage count (AUDIT WF-2).
        $startedAt = $prior['startedAt'] ?? new \DateTimeImmutable();
        $stageResults = $prior['stageResults'] ?? [];
        $totalTokens = $prior['totalTokens'] ?? 0;
        $totalCost = $prior['totalCost'] ?? 0.0;

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
                stageResults: [...$stageResults, new StageResult(
                    stageName: $refusedStageName,
                    status: WorkflowStatus::Failed,
                    error: $refusalMessage,
                    startedAt: new \DateTimeImmutable(),
                    completedAt: new \DateTimeImmutable(),
                )],
                context: $context,
                totalTokens: $totalTokens,
                totalCost: $totalCost,
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

        // The live state a pause() can see while this loop runs — by reference,
        // exactly as the interrupt handler above captures it, so a snapshot holds
        // the stages that have FINISHED and nothing that is still in flight.
        // (A full closure, not `fn`: an arrow function captures by value.)
        $snapshot = static function (WorkflowStatus $status) use (
            $resolvedWorkflowId,
            $startedAt,
            &$context,
            &$stageResults,
            &$totalTokens,
            &$totalCost,
        ): WorkflowResult {
            return new WorkflowResult(
                workflowId: $resolvedWorkflowId,
                status: $status,
                stageResults: $stageResults,
                context: $context,
                totalTokens: $totalTokens,
                totalCost: $totalCost,
                startedAt: $startedAt,
                completedAt: new \DateTimeImmutable(),
            );
        };
        $liveToken = ++$this->liveRunSequence;

        try {
            $this->liveRuns[$liveToken] = [
                'key' => $interruptId,
                'workflowId' => $resolvedWorkflowId,
                'loadPath' => $loadPath,
                'snapshot' => $snapshot,
                'pauseRequested' => false,
            ];

            foreach ($workflow->stages as $stageIndex => $stage) {
                // Skip stages that were already completed (resume support)
                if ($stageIndex < $currentStageIndex) {
                    continue;
                }

                // A live pause() landed while the previous stage was in flight
                // (Chat's fiber was suspended in the pool): stop before starting
                // this one, and rewrite the file pause() wrote with the stage
                // that has finished since.
                if ($this->liveRuns[$liveToken]['pauseRequested']) {
                    $pauseRequested = true;
                    $paused = $snapshot(WorkflowStatus::Paused);
                    $this->writePauseFile($interruptId, $paused, $loadPath);

                    return $paused;
                }

                $stageStartedAt = new \DateTimeImmutable();

                $stageType = $stage['type'] ?? '';
                if (!in_array($stageType, ['parallel', 'stage', 'pipeline', 'verification'], true)) {
                    throw new UnsupportedStageTypeException(
                        "Stage type '{$stageType}' is not supported. Only 'stage', 'parallel', 'pipeline', and 'verification' are implemented."
                    );
                }

                // Every agent the stage ran, with its result name, recorded by
                // the executor as each one settles — so an executor that throws
                // AFTER an agent ran still hands that agent's tokens and output
                // to the failed StageResult below instead of dropping them.
                $dispatched = [];
                try {
                    if ($stageType === 'parallel') {
                        $stageResult = $this->executeParallelStage($stage, $context, $workflow, $dispatched);
                    } elseif ($stageType === 'stage') {
                        $stageResult = $this->executeStage($stage, $context, $dispatched, $workflow->timeout);
                    } elseif ($stageType === 'pipeline') {
                        $stageResult = $this->executePipelineStage($stage, $context, $dispatched, $workflow->maxConcurrent, $workflow->timeout);
                    } else {
                        $stageResult = $this->executeVerificationStage($stage, $context, $dispatched, $workflow->timeout);
                    }
                } catch (\Throwable $e) {
                    $stageResult = new StageResult(
                        stageName: $stage['name'] ?? 'unknown',
                        status: WorkflowStatus::Failed,
                        error: $e->getMessage(),
                        agents: array_column($dispatched, 'result'),
                        startedAt: $stageStartedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                }

                // This stage's output and its agents' results, for downstream
                // interpolation — written here, once the stage has settled, so
                // a live pause's snapshot never holds half a stage.
                $context[$stageResult->stageName . '.output'] = $stageResult->output ?? '';
                $context = self::withResults($context, $dispatched);

                $stageResults[] = $stageResult;
                $totalTokens += $this->sumTokens($stageResult);
                $totalCost += $this->sumCost($stageResult);

                // Fail fast: stop processing on first stage failure
                if ($stageResult->isFailure()) {
                    $failed = $snapshot(WorkflowStatus::Failed);

                    // The stage in flight when a pause was requested failed.
                    // The run did fail, and says so; the user's pause still
                    // stands, recording the successful prefix, so a resume
                    // re-runs the stage that failed.
                    if ($this->liveRuns[$liveToken]['pauseRequested']) {
                        $pauseRequested = true;
                        $this->writePauseFile($interruptId, $failed, $loadPath);
                    }

                    return $failed;
                }
            }

            // A pause requested during the LAST stage has nothing left to stop:
            // the run completed. The file pause() wrote at request time is
            // withdrawn, or status would report a paused run with nothing to
            // resume and stages missing from it.
            if ($this->liveRuns[$liveToken]['pauseRequested']) {
                $pauseRequested = true;
                $pauseFile = $this->getPauseFilePath($interruptId);
                if (is_file($pauseFile)) {
                    @unlink($pauseFile);
                }
            }

            return $snapshot(WorkflowStatus::Completed);
        } finally {
            unset($this->liveRuns[$liveToken]);

            if ($previousAsyncSignals !== null) {
                $this->restoreInterruptHandlers($previousAsyncSignals);
            }
        }
    }

    /**
     * Execute a single 'stage' type stage and return its StageResult.
     *
     * Builds a SubAgent from the stage's task and calls AgentWorkerPool::executeOne().
     *
     * The agent's result is named by its task name, falling back to the STAGE
     * name — never to the agent type: two stages on the default `coder` agent
     * used to overwrite each other's `{{coder.results}}` (AUDIT WF-3).
     *
     * @param array $stage        Stage array from Workflow::$stages.
     * @param array $context      Current workflow context for interpolation.
     * @param list<array{key: string, result: AgentResult}> $dispatched Out: the agent this stage ran, once it settles.
     * @param int   $stageTimeout Workflow::$timeout — this stage's wall-clock budget, in seconds.
     * @return StageResult
     */
    private function executeStage(array $stage, array $context, array &$dispatched, int $stageTimeout): StageResult
    {
        $stageName = $stage['name'] ?? 'unknown';
        $stageStartedAt = new \DateTimeImmutable();
        $stageClock = hrtime(true);

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
            id: $stageName . '-' . uniqid(getmypid() . '_', true),
            agent: $agent,
            task: $interpolatedPrompt,
            timeout: $task->timeout ?? $stageTimeout,
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
            tools: $this->resolveRequestTools($task->tools),
            systemPrompt: $agent->systemPrompt(),
        );

        // Execute via pool
        $agentResult = $this->dispatchOne($subAgent, $request, $this->stagePool($stageTimeout, $stageClock));
        $dispatched[] = ['key' => self::resultKey($task->name, $stageName), 'result' => $agentResult];

        return $this->buildStageResult($stageName, $agentResult, $stageStartedAt);
    }

    /**
     * Execute a 'pipeline' type stage and return its StageResult.
     *
     * Chains nested stages sequentially, passing each stage's output as
     * `{{prevResult}}` to the next stage. Each nested stage also gets
     * `{{stageName.output}}` available for context interpolation.
     *
     * Each step's result is named by the step's task name, falling back to the
     * step's own name (which {@see WorkflowBuilder::pipeline()} sets to the task
     * name or agent type), and is visible as `{{name.results}}` to the steps
     * after it as well as to later stages.
     *
     * @param array $stage          Stage array from Workflow::$stages.
     * @param array $context        Current workflow context for interpolation.
     * @param list<array{key: string, result: AgentResult}> $dispatched Out: each step's agent, as it settles.
     * @param int   $maxConcurrent Maximum agents that may run concurrently.
     * @param int   $stageTimeout  Workflow::$timeout — the budget for the whole
     *                             pipeline: each step gets what the steps
     *                             before it left, not a fresh allowance.
     * @return StageResult
     */
    private function executePipelineStage(array $stage, array $context, array &$dispatched, int $maxConcurrent, int $stageTimeout): StageResult
    {
        $stageName = $stage['name'] ?? 'unknown';
        $stageStartedAt = new \DateTimeImmutable();
        $stageClock = hrtime(true);

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

        foreach ($nestedStages as $stepIndex => $nestedStage) {
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
                id: $stageName . '-' . $nestedStageName . '-' . uniqid(getmypid() . '_', true),
                agent: $agent,
                task: $interpolatedPrompt,
                timeout: $task->timeout ?? $stageTimeout,
                maxRetries: $task->retries ?? 0,
                isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
                permissionGate: $this->permissionGate,
            );

            $request = new CompleteRequest(
                model: $agent->model,
                messages: [
                    ['role' => 'user', 'content' => $interpolatedPrompt],
                ],
                tools: $this->resolveRequestTools($task->tools),
                systemPrompt: $agent->systemPrompt(),
            );

            $agentResult = $this->dispatchOne($subAgent, $request, $this->stagePool($stageTimeout, $stageClock));
            $record = [
                'key' => self::resultKey($task->name, $nestedStage['name'] ?? $stageName . '_' . ($stepIndex + 1)),
                'result' => $agentResult,
            ];
            $dispatched[] = $record;
            $pipelineContext = self::withResults($pipelineContext, [$record]);

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
     * The task's result is named by its task name, falling back to the stage
     * name; the verifier's by its own task name, falling back to
     * `<stage>_verifier`. The verifier's prompt can already address the task's.
     *
     * A failing verifier's stage carries BOTH agents, so the task's tokens and
     * cost stay in the run's totals; its output and error are the verifier's.
     *
     * @param array $stage        Stage array from Workflow::$stages.
     * @param array $context      Current workflow context for interpolation.
     * @param list<array{key: string, result: AgentResult}> $dispatched Out: the task's and the verifier's agents, as each settles.
     * @param int   $stageTimeout Workflow::$timeout — one budget shared by the
     *                            task and its verifier.
     * @return StageResult
     */
    private function executeVerificationStage(array $stage, array $context, array &$dispatched, int $stageTimeout): StageResult
    {
        $stageName = $stage['name'] ?? 'unknown';
        $stageStartedAt = new \DateTimeImmutable();
        $stageClock = hrtime(true);

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
            id: $stageName . '-task-' . uniqid(getmypid() . '_', true),
            agent: $taskAgent,
            task: $taskPrompt,
            timeout: $task->timeout ?? $stageTimeout,
            maxRetries: $task->retries ?? 0,
            isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
            permissionGate: $this->permissionGate,
        );

        $taskRequest = new CompleteRequest(
            model: $taskAgent->model,
            messages: [['role' => 'user', 'content' => $taskPrompt]],
            tools: $this->resolveRequestTools($task->tools),
            systemPrompt: $taskAgent->systemPrompt(),
        );

        $taskResult = $this->dispatchOne($taskSubAgent, $taskRequest, $this->stagePool($stageTimeout, $stageClock));
        $taskRecord = ['key' => self::resultKey($task->name, $stageName), 'result' => $taskResult];
        $dispatched[] = $taskRecord;

        // If task itself fails, the whole stage fails immediately
        if ($taskResult->status === AgentStatus::Failed || $taskResult->status === AgentStatus::TimedOut) {
            return $this->buildStageResult($stageName, $taskResult, $stageStartedAt);
        }

        // --- Run the verifier, injecting task output as {{prevResult}} ---
        $verifierContext = self::withResults($context, [$taskRecord]);
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
            id: $stageName . '-verifier-' . uniqid(getmypid() . '_', true),
            agent: $verifierAgent,
            task: $verifierPrompt,
            timeout: $verifier->timeout ?? $stageTimeout,
            maxRetries: $verifier->retries ?? 0,
            isolation: $verifier->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
            permissionGate: $this->permissionGate,
        );

        $verifierRequest = new CompleteRequest(
            model: $verifierAgent->model,
            messages: [['role' => 'user', 'content' => $verifierPrompt]],
            tools: $this->resolveRequestTools($verifier->tools),
            systemPrompt: $verifierAgent->systemPrompt(),
        );

        $verifierResult = $this->dispatchOne($verifierSubAgent, $verifierRequest, $this->stagePool($stageTimeout, $stageClock));
        $dispatched[] = ['key' => self::resultKey($verifier->name, $stageName . '_verifier'), 'result' => $verifierResult];

        // Verifier failure marks the whole stage as failed. The task's agent
        // stays on the result: it ran, and its spend is real.
        if ($verifierResult->status === AgentStatus::Failed || $verifierResult->status === AgentStatus::TimedOut) {
            $failed = $this->buildStageResult($stageName, $verifierResult, $stageStartedAt);

            return new StageResult(
                stageName: $failed->stageName,
                status: $failed->status,
                output: $failed->output,
                error: $failed->error,
                agents: [$taskResult, $verifierResult],
                startedAt: $taskResult->startedAt ?? $stageStartedAt,
                completedAt: $failed->completedAt,
            );
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
     * Run one agent for a sequential, pipeline or verification stage.
     *
     * THROUGH THE MANAGER WHEN THERE IS ONE, as {@see executeParallelStage()}
     * already does: the live-agent pane and the status strip read
     * {@see AgentManager::liveOutputs()}, which only sees sub-agents the
     * manager dispatched. A stage run straight on the pool was invisible there
     * however much it streamed — so only parallel stages ever painted a tile.
     * Refusals propagate exactly as they do for a parallel stage.
     *
     * $pool is {@see stagePool()}'s budgeted clone of the shared pool, never
     * the shared pool itself, so the stage's remaining time bounds this
     * dispatch (WF-1).
     */
    private function dispatchOne(SubAgent $subAgent, CompleteRequest $request, AgentWorkerPool $pool): AgentResult
    {
        if ($this->agentManager === null) {
            return $pool->executeOne($subAgent, $request);
        }

        foreach ($this->agentManager->executeAll([$subAgent], $request, $pool) as $result) {
            return $result;
        }

        return new AgentResult(
            agentId: $subAgent->id,
            status: AgentStatus::Stopped,
            error: new \RuntimeException('cancelled before it produced a result'),
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * The shared pool, bounded by what is left of a stage's budget.
     *
     * AUDIT WF-1: `config.timeout` (Workflow::$timeout) is documented as the
     * per-stage timeout, and nothing read it — a stage had no wall-clock bound
     * at all. The remainder rather than the whole figure, so a pipeline's or a
     * verification stage's later steps get what the earlier ones left: the
     * bound is on the STAGE, and N steps each allowed the full figure would
     * let it run N times over. A budget that is already spent makes the pool
     * settle the next agent TimedOut without starting it.
     */
    private function stagePool(int $stageTimeout, int $stageClock): AgentWorkerPool
    {
        return $this->pool->withTimeBudget(self::remainingBudget($stageTimeout, $stageClock));
    }

    /** Seconds left of $stageTimeout since the hrtime(true) reading $stageClock. */
    private static function remainingBudget(int $stageTimeout, int $stageClock): float
    {
        return $stageTimeout - (hrtime(true) - $stageClock) / 1_000_000_000;
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
     * Each agent's result is named by its task name, falling back to
     * `<stage>_<n>` (1-based, in declaration order), and is recorded in
     * DECLARATION order whatever order the agents finished in. An agent the
     * pool cancelled before it produced a result (stopOnFirstFailure) records
     * nothing.
     *
     * @param array    $stage   Stage array from Workflow::$stages.
     * @param array    $context Current workflow context for interpolation.
     * @param Workflow $workflow The workflow definition (provides maxConcurrent, stopOnFirstFailure).
     * @param list<array{key: string, result: AgentResult}> $dispatched Out: one entry per agent that settled.
     * @return StageResult
     */
    private function executeParallelStage(array $stage, array $context, Workflow $workflow, array &$dispatched): StageResult
    {
        $stageName = $stage['name'] ?? 'unknown';
        $stageStartedAt = new \DateTimeImmutable();
        $stageClock = hrtime(true);

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
            tools: $this->resolveRequestTools($firstTask->tools),
            systemPrompt: $firstAgent->systemPrompt(),
        );

        $subAgents = [];
        /** @var array<string, string> $resultKeys SubAgent id => result name, in declaration order */
        $resultKeys = [];
        $agentIndex = 0;
        foreach ($tasks as $task) {
            /** @var WorkflowTask $task */
            $agentIndex++;

            // E641 fixup — REFUSAL PARITY, result deliberately discarded.
            // resolveRequestTools() is this engine's fail-loud gate on names
            // the registry cannot type, and the $defaultRequest above only
            // ran it over the FIRST task: tasks 2..N could name a tool that
            // exists in no registry and reach the pool unnoticed (with no
            // permission gate, refuseDeniedTools stays silent too). The
            // typed per-agent grant each worker finally receives is lane A's
            // AgentWorkerPool::executeAll() work — until that lands, every
            // parallel agent runs on the first task's grant anyway, so this
            // call's ONLY job is to throw here, at build time, before
            // anything dispatches. If the discard starts looking redundant
            // after per-agent grants exist, that is because the pool began
            // calling this itself — at which point delete this line, do not
            // double-resolve.
            $unreachedGrant = $this->resolveRequestTools($task->tools);
            unset($unreachedGrant);

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

            $subAgent = new SubAgent(
                id: $stageName . '-' . $agentIndex . '-' . uniqid(getmypid() . '_', true),
                agent: $agent,
                task: $interpolatedPrompt,
                timeout: $task->timeout ?? $workflow->timeout,
                maxRetries: $task->retries ?? 0,
                isolation: $task->isolation ?? \SugarCraft\Crush\Agents\Isolation::None,
                permissionGate: $this->permissionGate,
            );
            $subAgents[] = $subAgent;
            $resultKeys[$subAgent->id] = self::resultKey($task->name, $stageName . '_' . $agentIndex);
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
        // WF-1: the stage's budget bounds the whole fan-out, queue time
        // included — see stagePool() for why it is the remainder.
        $pool = $pool->withTimeBudget(self::remainingBudget($workflow->timeout, $stageClock));

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
        $dispatched = [...$dispatched, ...self::recordsInDeclarationOrder($resultKeys, $agentResults)];

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
     * Turn a task's declared tool NAMES into the typed `?list<Tool>` a
     * {@see CompleteRequest} carries to a provider.
     *
     * ## THE DEFECT THIS ENDS (E641)
     *
     * Every `tools:` handoff into a CompleteRequest used to pass
     * {@see WorkflowTask::$tools} straight through — a `list<string>` of
     * names. That is the correct shape for {@see Agent::$tools} (a carrier of
     * declarations, resolved later by {@see AgentManager::resolveGrantedTools()}
     * or not at all) and the wrong shape for a provider request: every
     * provider either type-hints its `formatTools()` closure parameter as
     * {@see Tool} ({@see \SugarCraft\Crush\Providers\OpenAIProvider}) or calls
     * `->name()` on each entry ({@see \SugarCraft\Crush\Providers\ClaudeCodeProvider}).
     * A string reaching either is a fatal TypeError/`BadMethodCall`, and it
     * was latent only because the worker on this path never consulted a
     * provider. Resolving HERE, at the one boundary both shapes meet, makes
     * the illegal state unrepresentable downstream: after this method a
     * `CompleteRequest::$tools` is either null or a list of verified Tools.
     *
     * ## `[]` AND NULL MEAN THE SAME THING, AND `[]` NEVER TRAVELS
     *
     * {@see WorkflowTask::$tools} defaults to `[]`, so "task declares no
     * tools" arrives here as the empty list — far more often than as the
     * deliberate "grant exactly nothing" an empty array would otherwise read
     * as. How providers branch settles the question: every one of them gates
     * its tool block on `$request->tools !== null`, so a literal `[]` passes
     * the gate and goes on the wire as an empty `tools` parameter — a
     * different request than one with no `tools` at all (OpenAI rejects
     * `tools: []` outright; {@see \SugarCraft\Crush\Providers\SglangProvider::defaultTopP()}
     * reads the two the same, but it is the only provider that does). This
     * engine therefore normalizes the empty declaration to null, the same
     * reading {@see AgentManager::resolveGrantedTools()} documents for the
     * delegation path ("NO DECLARATION IS NOT AN EMPTY GRANT") — the two
     * seams now answer the question identically.
     *
     * ## REGISTRY-ORDER, EXACT-NAME, FAIL-LOUD
     *
     * Selection iterates the REGISTRY, not the declaration list: registry
     * order is the documented wire order (`Bootstrap::tools()`), and iterating
     * it dedupes `['Bash','Bash']` by construction. Unlike
     * {@see AgentManager::resolveGrantedTools()} this takes EXACT names, not
     * PermissionRule patterns: a workflow declaration is parsed by
     * {@see WorkflowRegistry::requireToolList()} as plain names, and a
     * `Bash(git *)`-style pattern cannot select a Tool here — argument-scoped
     * grants are enforced per call, one layer down, where arguments exist. So
     * a pattern-shaped declaration resolves to nothing and throws, which is
     * the loud answer; the quiet one (skipping it) is this same bug wearing
     * the typo's hat: the model would be offered a smaller roster than the
     * stage's prompt claims. An unresolved name throws rather than degrading,
     * and {@see runFromWorkflow()}'s per-stage catch already turns a
     * \Throwable into that stage's failed StageResult — same delivery shape
     * as {@see refuseDeniedTools()}.
     *
     * @return ?list<Tool> null when nothing is declared or nothing can be
     *                      resolved (no registry wired); otherwise one Tool
     *                      per declared name, in registry order.
     * @throws \RuntimeException When a declaration is not a non-empty string,
     *         names no tool in the registry, or the registry holds a non-{@see Tool}.
     */
    private function resolveRequestTools(array $taskTools): ?array
    {
        if ($taskTools === []) {
            return null;
        }

        if ($this->toolRegistry === null) {
            // No registry supplied at all: the names cannot be typed, and the
            // alternative — shipping the raw strings — is the E641 fatal.
            // Toolless is the documented AgentManager reading of this state.
            return null;
        }

        $declared = [];
        foreach ($taskTools as $tool) {
            if (!is_string($tool) || $tool === '') {
                throw new \RuntimeException(sprintf(
                    'A workflow task declares a tool that is not a non-empty tool name (%s); it cannot be resolved against the tool registry.',
                    get_debug_type($tool),
                ));
            }

            $declared[$tool] = true;
        }

        $resolved = [];
        $matched = [];
        foreach ($this->toolRegistry as $tool) {
            if (!$tool instanceof Tool) {
                throw new \RuntimeException(sprintf(
                    'The tool registry holds a non-%s entry (%s); a workflow tool grant cannot be built from it.',
                    Tool::class,
                    get_debug_type($tool),
                ));
            }

            if (isset($declared[$tool->name()])) {
                $resolved[] = $tool;
                $matched[$tool->name()] = true;
            }
        }

        foreach (array_keys($declared) as $name) {
            if (!isset($matched[$name])) {
                throw new \RuntimeException(sprintf(
                    'A workflow task declares tool "%s", which this session\'s tool registry does not contain. The stage cannot be dispatched with a smaller roster than its prompt describes.',
                    $name,
                ));
            }
        }

        return $resolved;
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
     * Interpolate {{variable}}, {{stageName.output}}, and {{name.results}} tokens in a string.
     *
     * - `{{name.results}}` is replaced with one agent's output, from the
     *   {@see RESULTS_CONTEXT_KEY} map ({@see resultKey()} names its entries).
     * - `{{stageName.output}}` is replaced with the output of a prior stage result.
     * - `{{variable}}` is replaced with $context['variable'].
     *
     * An unresolved token is left as written. So is one whose value is not a
     * string or a number: a `runFromPhp()` caller can put anything in the
     * context, and an array reaching the replacement used to be a TypeError
     * that failed the stage — after the agents before it had run.
     *
     * @param string $text    The text containing interpolation tokens.
     * @param array  $context Current workflow context.
     * @return string Interpolated text.
     */
    private function interpolateContext(string $text, array $context): string
    {
        $results = $context[self::RESULTS_CONTEXT_KEY] ?? [];
        $results = is_array($results) ? $results : [];

        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\.results\}\}/',
            static fn (array $matches): string => self::interpolatable($results[$matches[1]] ?? null) ?? $matches[0],
            $text
        );

        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\.output\}\}/',
            static fn (array $matches): string => self::interpolatable($context[$matches[1] . '.output'] ?? null) ?? $matches[0],
            $text
        );

        return preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/',
            static fn (array $matches): string => self::interpolatable($context[$matches[1]] ?? null) ?? $matches[0],
            $text
        );
    }

    /** $value as interpolated text, or null when it is not text a prompt can carry. */
    private static function interpolatable(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * The name an agent's output is recorded under for `{{name.results}}`:
     * its task's own name, else $fallback — the stage name for a sequential
     * stage or a verification task, the step name for a pipeline step,
     * `<stage>_verifier` for a verifier, `<stage>_<n>` for a parallel agent.
     *
     * AUDIT WF-3: the fallback used to be the agent TYPE, so every unnamed
     * stage on the default `coder` agent wrote the same `coder` entry.
     */
    private static function resultKey(?string $taskName, string $fallback): string
    {
        return $taskName !== null && $taskName !== '' ? $taskName : $fallback;
    }

    /**
     * $context with each record's output written into its
     * {@see RESULTS_CONTEXT_KEY} map, in record order — a later record with
     * the same name overwrites an earlier one, as a later stage's does.
     *
     * @param array<array-key, mixed> $context
     * @param list<array{key: string, result: AgentResult}> $records
     * @return array<array-key, mixed>
     */
    private static function withResults(array $context, array $records): array
    {
        if ($records === []) {
            return $context;
        }

        $results = $context[self::RESULTS_CONTEXT_KEY] ?? [];
        $results = is_array($results) ? $results : [];
        foreach ($records as $record) {
            $results[$record['key']] = $record['result']->output ?? '';
        }
        $context[self::RESULTS_CONTEXT_KEY] = $results;

        return $context;
    }

    /**
     * A parallel stage's results paired with the names they are recorded
     * under, in DECLARATION order.
     *
     * The pool yields in COMPLETION order (and not at all for an agent
     * stopOnFirstFailure cancelled), so `$agentResults[$i]` is not
     * `$tasks[$i]`'s: each result is matched by its SubAgent id. A result
     * whose id names no agent of this stage — an executor that did not echo
     * the id it was handed — takes the first name no result has claimed.
     *
     * @param array<string, string> $resultKeys   SubAgent id => result name, in declaration order
     * @param list<AgentResult>     $agentResults
     * @return list<array{key: string, result: AgentResult}>
     */
    private static function recordsInDeclarationOrder(array $resultKeys, array $agentResults): array
    {
        $byId = [];
        $unmatched = [];
        foreach ($agentResults as $agentResult) {
            if (isset($resultKeys[$agentResult->agentId]) && !isset($byId[$agentResult->agentId])) {
                $byId[$agentResult->agentId] = $agentResult;
            } else {
                $unmatched[] = $agentResult;
            }
        }

        $records = [];
        foreach ($resultKeys as $id => $key) {
            $agentResult = $byId[$id] ?? array_shift($unmatched);
            if ($agentResult !== null) {
                $records[] = ['key' => $key, 'result' => $agentResult];
            }
        }

        return $records;
    }

    /**
     * Refuse a run context that would shadow the engine's own entries.
     *
     * Every `@`-prefixed key is reserved, not only {@see RESULTS_CONTEXT_KEY}:
     * `/workflow run <name> @results=x` would otherwise replace every
     * agent's recorded output with a string. {@see resume()} does not come
     * through here — its context is the pause file's, which legitimately
     * carries the results map ({@see withSanitizedResults()}).
     *
     * @param array<array-key, mixed> $context
     * @throws \InvalidArgumentException When a key starts with `@`.
     */
    private static function refuseReservedContextKeys(array $context): void
    {
        foreach (array_keys($context) as $key) {
            if (is_string($key) && str_starts_with($key, '@')) {
                throw new \InvalidArgumentException(
                    "Workflow context key '{$key}' is reserved: keys starting with '@' hold the engine's own "
                    . "state (agent results for {{name.results}} live under '" . self::RESULTS_CONTEXT_KEY . "'). "
                    . 'Rename the key.'
                );
            }
        }
    }

    /**
     * A pause file's context with its results map reduced to name => text
     * entries — the only shape {@see interpolateContext()} reads — so a
     * hand-edited or truncated file cannot put anything else there.
     *
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    private static function withSanitizedResults(array $context): array
    {
        if (!array_key_exists(self::RESULTS_CONTEXT_KEY, $context)) {
            return $context;
        }

        $results = $context[self::RESULTS_CONTEXT_KEY];
        $context[self::RESULTS_CONTEXT_KEY] = is_array($results)
            ? array_filter($results, static fn (mixed $v, int|string $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH)
            : [];

        return $context;
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

            // Reuse the exact same serializer (writePauseFile()) a cooperative
            // pause uses, so the persisted file format never drifts between
            // the code paths — INCLUDING the identifier bookkeeping, which is
            // what the two sites used to disagree about: this one keyed the
            // result map by the run ID while run() keyed it by the name, so
            // whether `/workflow pause <id>` worked depended on how the run had
            // ended.
            $this->rememberResult($interruptId, $partialResult, $loadPath);

            try {
                $this->writePauseFile($interruptId, $partialResult, $loadPath);
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
