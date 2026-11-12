<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Code\Background\BackgroundSupervisor;
use SugarCraft\Code\Support\ForkedChild;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\PrivateDir;
use SugarCraft\Code\Support\ProcessTree;
use SugarCraft\Code\Context\EnvironmentBlock;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Agents\AgentStatus;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookDispatcher;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\ToolDeclaration;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Util\PathGlob;

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
 * O1 resilience records: for a registry-backed run (run()/resume()) the
 * same pause record is ALSO refreshed after every stage that completes —
 * same serializer, same wire shape, same path. A SIGKILL or a dead machine
 * can then never lose a finished stage: resume() replays from the first
 * stage the record does not count. Clean terminals (completed, failed,
 * cancelled with no pause standing) withdraw it, so an undisturbed run
 * leaves exactly the trace it always left. The in-flight stage of a killed
 * run always re-runs — stage granularity is the contract, and the AUDIT
 * WF-2 rule holds: re-running a stage is recoverable, skipping one that
 * lied is not.
 *
 * P2 reconciliation: every record now carries the WRITER'S process identity
 * (`pid`, and the kernel start instant `pidStartedAt` where procfs can
 * answer). A record that a cooperative stop wrote is stamped clean and
 * reads exactly as it always did; a crash-class record (the O1 refresh is
 * the only writer that leaves one) is judged AT QUERY TIME, never polled —
 * status() reports the run as `orphaned` when its writer is verifiably
 * dead (pid-reuse defeated by the start instant) and `running` when the
 * writer is verifiably live elsewhere, where resume() refuses rather than
 * racing a second writer onto the same run. An orphaned record resumes
 * through the very same replay path a cooperative pause takes: the killed
 * stage's partial work is lost in both cases, and that was already the
 * pause contract.
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
 * finishes. That second half matters now that {@see \SugarCraft\Code\Chat}
 * dispatches run() inside a live TUI: sugar-core's `Program` installs its own
 * SIGINT closure for graceful shutdown, and resetting to `SIG_DFL` here left
 * an external `kill -INT` killing the process outright, so PHP's shutdown
 * sequence — and with it PosixBackend's destructor, which is what puts termios
 * back — never ran, leaving the terminal in raw mode inside the alt screen.
 *
 * Mirrors charmbracelet/crush WorkflowEngine implementation.
 */
final class WorkflowEngine implements WorkflowEngineInterface
{
    private const PAUSE_DIR = '.running';

    /**
     * O3: how long a stage waits for a contended host resource before it
     * fails loudly, when the task did not ask for a bound of its own.
     */
    private const DEFAULT_STAGE_LOCK_WAIT_SECONDS = 60;

    /**
     * S3: how many bytes of a captured stage's output reach downstream
     * prompts alongside the capture file's path. Bounded on purpose: the
     * point of capture is a stable prompt envelope, so the preview must not
     * reintroduce the unbounded dump it replaces.
     */
    public const CAPTURE_PREVIEW_BYTES = 2048;

    /** Lazily-built store for O3 resource locks, rooted beside the registry. */
    private ?StageResourceLocks $stageLocks = null;

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
     * The context entry holding the re-encoded JSON document of each
     * schema'd stage's output, keyed by result name, for the
     * `{{name.<pointer>}}` interpolation form (RFC 6901).
     *
     * Typed rows live in a map of their OWN — never in a suffixed slot of
     * the {@see RESULTS_CONTEXT_KEY} map — because result names are
     * arbitrary strings: a `from:`-template fan-out names its tasks by
     * file relative path, so a task named `a.json` exists, and one shared
     * map would let that task's text row impersonate or clobber task `a`'s
     * typed row. Separate namespaces make that collision unrepresentable.
     *
     * Values are JSON STRINGS like the text rows, not arrays: a string
     * survives the resume path's row filtering and the pause file's
     * json_encode carries it, so the typed map round-trips with no extra
     * machinery.
     *
     * @see jsonPointerValue() for how the rows are addressed.
     */
    public const TYPED_RESULTS_CONTEXT_KEY = '@typedResults';

    /**
     * P1-1: the context entry a `from:` fan-out hands each item's
     * interpolation — `{item: mixed, index: int}` — read ONLY by the item
     * pass of {@see interpolateContext()} (`{{item}}`, `{{item.<pointer>}}`,
     * `{{index}}`). `@`-prefixed, so no run context can shadow or forge it
     * ({@see refuseReservedContextKeys()}), and it only ever lives on a
     * per-item COPY of the context, never on the running one, so it never
     * reaches a pause record.
     */
    public const FAN_OUT_ITEM_CONTEXT_KEY = '@fanOutItem';

    /**
     * P1-5: the plain context keys a `loop:` injects per round —
     * `{{round}}` (1-based), `{{previous}}` (the prior round's stage output,
     * from round 2), and with `accumulate:` `{{seen}}` (JSON list of every
     * dedup key seen so far) and `{{fresh}}` (JSON list of the items the
     * previous round added). They shadow same-named run-context keys only
     * while the loop runs.
     */
    private const LOOP_TOKENS = ['round', 'previous', 'seen', 'fresh'];

    /** Scan outcome of {@see scanVerdict()}: the pattern matched — reject. */
    private const VERDICT_REJECTED = 'rejected';
    /** Scan outcome: no match — the verdict stands. */
    private const VERDICT_CLEAN = 'clean';
    /** Scan outcome: the pattern could not compile — fail CLOSED, never clean. */
    private const VERDICT_INVALID = 'invalid';
    /** Scan outcome: the verifier asked a human — stop for a person, never re-dispatch. */
    private const VERDICT_ESCALATED = 'escalated';

    /**
     * The documented escalation marker: a line of the verifier's output that
     * starts (leading spaces/tabs allowed) with `ESCALATE:`. A question, not a
     * verdict — unlike a reject match it consumes no retries and re-dispatches
     * nothing; the stage fails once and the run stops for a human.
     */
    private const ESCALATION_PATTERN = '/^[ \t]*ESCALATE:/m';

    /**
     * The prefix every escalated-verifier failure reason carries, spelled
     * exactly as {@see self::scanVerdict()} builds it. The CLI surface
     * (`candy-code workflow`) keys its exit-3 mapping on this constant rather
     * than re-spelling the sentence: a verifier escalation is a stop FOR A
     * PERSON, not a plain stage failure, and the two must stay tellable apart from
     * outside the engine without parsing prose.
     */
    public const ESCALATION_MARKER = 'escalated: ';

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
     * {@see \SugarCraft\Code\Agents\AgentWorkerPool::idle()}, and finish in
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
     * Optional observer invoked by {@see installInterruptHandlers()}'s handler
     * AFTER the cooperative pause record has been written and BEFORE the
     * process exits, receiving the partial result and the pause file path (or
     * null if the record could not be located) and returning the exit code.
     *
     * Null — the default — keeps the historical behaviour byte-identical:
     * `exit(130/143)` straight through the load-bearing shutdown sequence.
     * The headless `candy-code workflow` verb (W-1/D4) installs an observer
     * so a signal-pause can emit its machine document on stdout and exit 3;
     * nothing else in-tree sets it. Mutable-service precedent: same shape as
     * {@see setAgentManager()} — the engine is a service the caller wires.
     *
     * @var (\Closure(WorkflowResult, ?string): int)|null
     */
    private ?\Closure $signalPauseHandler = null;

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
     * and B's stage results, `status()` answers identically for both ids,
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
     * {@see \SugarCraft\Code\Chat::driveWorkflowFiber()}), and THEN the
     * second submit dispatches against a live first one. They now get told,
     * instead of quietly getting two runs that corrupt each other's
     * bookkeeping.
     *
     * Note also what has to be true for the first run to still be live:
     * {@see \SugarCraft\Code\Agents\AgentWorkerPool::idle()} is the ONLY
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
     * @var array<int, array{key: string, workflowId: string, loadPath: ?string, snapshot: \Closure(WorkflowStatus): WorkflowResult, pauseRequested: bool, inputHashes: array<string, string>, dynamicWaves: array<array-key, mixed>, inputJoins: array<string, list<array{from: list<string>, as: string}>>}>
     */
    private array $liveRuns = [];

    /** The error a stage the cancel interrupted carries ({@see cancelledRun()}). */
    public const CANCELLED_STAGE_ERROR = 'cancelled with Esc Esc while it ran; its agents were stopped';

    /**
     * C11: how many workflows one run may span through `uses:` stages, outer
     * included — a -> b -> c is depth 3 and legal, the fourth node refuses.
     * The linter pre-checks the same bound statically; this is the runtime
     * door for plans assembled at run time.
     */
    public const MAX_USES_DEPTH = 3;

    /** Source of {@see $liveRuns} keys. */
    private int $liveRunSequence = 0;

    /**
     * The cancellation token of the OUTERMOST live run that was given one
     * ({@see run()}, {@see resume()}), or null. Engine state rather than a
     * parameter threaded through every stage helper, so a NESTED run — a
     * stage re-entering {@see run()} on the same call stack — is stopped by
     * the same Esc Esc as the run around it.
     */
    private ?CancellationToken $runCancellation = null;

    /**
     * The pools the current stage is dispatching on, held WEAKLY: a stage
     * pool is single-use and dropped when its stage returns, and a strong
     * reference here would keep its forked-worker bookkeeping and IPC
     * directory alive until the run ended. {@see cancelDispatchingPools()}
     * is what reads it.
     *
     * @var list<\WeakReference<AgentWorkerPool>>
     */
    private array $dispatchPools = [];

    /**
     * C11 include-compose: the names of the workflows whose stage loops this
     * engine is executing right now, outermost first — the uses-chain this
     * run is walking. {@see executeIncludedWorkflow()} refuses an include
     * whose name is already on it (a cycle) or whose addition would exceed
     * {@see MAX_USES_DEPTH} (depth), and sets it for the nested run so the
     * refusal sees the whole path, not just one hop. Engine state rather
     * than a loop parameter because a resume re-enters at a stage boundary
     * and rebuilds the chain by walking its outer stages again — the same
     * reason the pause-within-an-inner limitation is what it is.
     *
     * @var list<string>
     */
    private array $usesChain = [];

    /**
     * P0-3: the CPU count the default width is computed from; null asks
     * {@see CpuCount::detect()}. Injectable ({@see setCpuCount()}) so a test
     * pins the width a host-independent number.
     */
    private ?int $cpuCount = null;

    /**
     * P0-3 `config.maxAgents`: agents the OUTERMOST live run has dispatched
     * so far, every attempt counted (retries, stall restarts, loop rounds,
     * included workflows' agents). Engine state rather than a loop local for
     * the {@see $usesChain} reason: an included run shares its parent's
     * count, so the backstop is run-wide, not per include.
     */
    private int $agentsDispatched = 0;

    /** The outermost run's `config.maxAgents`; null outside a run. */
    private ?int $agentCeiling = null;

    /**
     * @param string $model    The model every stage's agent runs on. A workflow
     *        stage names an agent TYPE ('reviewer', 'coder'), never a model, so
     *        the model has to come from the run — and until this parameter
     *        existed it was the literal `claude-sonnet-4-6` written into all six
     *        `new Agent(...)` sites below. That was invisible while nothing
     *        constructed this class; the moment {@see
     *        \SugarCraft\Code\Cli\Bootstrap::chat()} does, a session on any
     *        other deployment would have had `/workflow run` dispatch its
     *        sub-agents at a model that session never selected. The old literals
     *        remain the defaults so a caller that does not care keeps today's
     *        behaviour.
     * @param string $provider The provider label those agents carry, same
     *        reasoning. {@see \SugarCraft\Code\Agents\ProcessExecutor} sends the
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
     *        {@see \SugarCraft\Code\Agents\ProcessExecutor}'s worker is still
     *        the P1.S5 simulation, so a pool-executed stage makes no provider
     *        request and issues no tool call at all. `Ask` is likewise not a
     *        refusal here: settling one needs the blocking prompt UI, which
     *        this engine has no channel to, so an Ask passes the declaration
     *        check and is left to whichever layer eventually owns the call.
     *
     *        And one corollary a reader of the two paragraphs above would
     *        otherwise assume the other way: whether a stage's DECLARED tool
     *        list is a capability boundary depends on the dispatch path.
     *        {@see \SugarCraft\Code\Agents\AgentWorkerPool::executeAll()}
     *        (AgentWorkerPool.php:648-667) builds each per-agent request with
     *        `tools: $toolGrantResolver($agent)` when the caller supplies a
     *        resolver, and with `tools: $request->tools` — the FIRST task's
     *        list — when it does not. The manager path takes the first
     *        branch: {@see AgentManager::executeAll()} resolves every batch
     *        member's declaration against the session registry before the
     *        pool forks anything, and hands the result down as that resolver,
     *        so a parallel agent that declared `[Read]` is handed its own
     *        grant rather than a sibling's — and
     *        {@see \SugarCraft\Code\Hooks\BuiltIn\SubAgentGrantHook} holds
     *        every call it then makes to the same declaration. An embedder
     *        driving the pool without a resolver — or a manager with no
     *        registry, its documented escape hatch — still gets the shared
     *        first list, and on that path the declaration remains a request
     *        for capability, not a boundary. Neither branch is a bypass of
     *        the check above: it examines every task's declaration, so what
     *        reaches an agent — its own grant or the first task's list — is
     *        always a subset of the checked union.
     *
     * @param ?string $environmentRoot The session's resolved project root -
     *        `--root`'s value as {@see \SugarCraft\Code\Cli\Bootstrap::chat()}
     *        resolved it, or null for an engine whose launch has none. It is
     *        carried to every per-stage `new Agent(...)` below as
     *        {@see \SugarCraft\Code\Agents\Agent::$environmentRoot}, which is
     *        what makes that agent's last-resort environment capture name the
     *        directory the stage's tools are jailed to rather than the process
     *        directory the launch happened to start in. THE ROOT, NOT A BLOCK
     *        — as originally written here, true of every render until
     *        cc-fix-1111 M-11 threaded the per-stage write signal: the P3.S6
     *        paragraph of {@see \SugarCraft\Code\Agents\Agent::systemPrompt()}
     *        measures what an attached block would change, and the build-out
     *        it anticipated has since landed. On the suppression leg — only
     *        while every dispatched result so far has POSITIVELY reported no
     *        write, {@see renderStagePrompt()} — the engine now passes an
     *        explicitly-marked capture so the render pays three live git polls
     *        instead of five; every other render stays the unmarked fallback
     *        exactly as pinned, and the first prompt of a run is byte-identical
     *        to what this engine emitted before M-11. Only the anchor of the
     *        capture moves; the volatile half is re-polled on every render
     *        either way. This closes a seam a phase
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
        /**
         * C6: the hook dispatcher this run announces stage lifecycle to
         * ({@see HookEvent::StageStarted()} and siblings). NULL, THE DEFAULT,
         * IS UNARMED: every announce site early-returns, so an engine built
         * without it behaves byte-identically to one built before C6 existed
         * — pinned, not asserted in prose. When present, the four lifecycle
         * events are dispatched inline and synchronously on the run's own
         * path; they are observe-only and cannot alter the run. The TUI seat
         * hands it over after construction — {@see bindHookDispatcher()}.
         */
        private ?HookDispatcher $hookDispatcher = null,
    ) {}

    /**
     * M-11 (cc-fix-1111): the run-level write signal threaded through every
     * stage render of one run. TRI-STATE, mirroring the main loop's
     * Runtime::markWriteSinceLastRender step semantics exactly:
     * NULL (the reset value at run start) means no positive report yet, so
     * every render keeps the full five git subprocess polls — the first
     * prompt of a run is byte-identical to what the pre-M-11 engine emitted;
     * FALSE means every dispatched result so far positively reported "no
     * write happened and none was possible", which lets later renders
     * suppress exactly the two git diff sections (three polls, not five);
     * TRUE means some result ran or may have run a tool, and suppression
     * stays off until a later positive report parks it back to false.
     * The git branch/status/log half is re-polled on EVERY render either
     * way — the live-polled law ({@see \SugarCraft\Code\Tests\Providers\PromptStabilityTest})
     * is untouched; only the diff tail moves, the same lever the main chat
     * loop already ships. Freshness law quoted here per house discipline:
     * "a model reading a stale git status after its own edits is worse than
     * either cost" — which is why only POSITIVE reports move this field and
     * the register measured 399 ms total / 373 ms of it in the diff sections.
     */
    private ?bool $stageWriteSignal = null;

    /**
     * Render a stage agent's system prompt under the current write signal.
     *
     * M-11 (cc-fix-1111), register option (a): "thread per-stage
     * write-signal through AgentResult/complete frame OR take the
     * constant-false hoist for tool-less workers". The threading is live;
     * the signal is a self-report, never a structural guess (the hoist
     * variant was declined for deriving from worker STRUCTURE instead of
     * what the stage did — see the AgentResult::$requestedWrite docblock).
     * Suppression passes an explicitly-marked capture at the same root the
     * unmarked fallback would use ($this->environmentRoot ?? getcwd()), so
     * the static half and the three live-polled commands are identical
     * bytes; only the two diff sections are withheld, and only while every
     * report so far positively says nothing wrote.
     */
    private function renderStagePrompt(Agent $agent): string
    {
        if ($this->stageWriteSignal === false) {
            return $agent->systemPrompt(
                EnvironmentBlock::capture($this->environmentRoot ?? (string) getcwd(), $agent->model)
                    ->withWriteSinceLastRender(false)
            );
        }

        return $agent->systemPrompt();
    }

    /**
     * Fold one dispatched result's self-report into the run-level signal.
     * Only POSITIVE reports move it (null leaves the identity alone —
     * exactly the Runtime step semantics the main loop pins).
     */
    private function foldStageWriteSignal(AgentResult $result): void
    {
        if ($result->requestedWrite !== null) {
            $this->stageWriteSignal = $result->requestedWrite;
        }
    }

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

        // The stage grants are judged through the session's manager too
        // (W2-g): an executor bound before the manager arrived picks it up.
        $executor = $this->pool->forkedExecutor();
        if ($executor instanceof EngineExecutor && $executor->engine() !== null) {
            $executor->bind($executor->engine(), $agentManager);
        }
    }

    /**
     * Pin the CPU count the default parallel width is computed from (P0-3:
     * an unset `maxConcurrent` runs `min(16, max(1, cpus - 2))` agents at
     * once). Null restores host detection. A mutable-service setter for the
     * {@see setAgentManager()} reason.
     */
    public function setCpuCount(?int $cpus): void
    {
        if ($cpus !== null && $cpus < 1) {
            throw new \InvalidArgumentException("setCpuCount() needs at least 1 CPU, got {$cpus}");
        }

        $this->cpuCount = $cpus;
    }

    /**
     * The width a parallel stage of $workflow runs at (P0-3): the explicit
     * `maxConcurrent` when one is set, null (no cap) for an explicit 0, and
     * {@see Workflow::defaultConcurrency()} of the host's CPUs when unset.
     */
    public function concurrencyFor(Workflow $workflow): ?int
    {
        if ($workflow->maxConcurrent === null) {
            return Workflow::defaultConcurrency($this->cpuCount ?? CpuCount::detect());
        }

        return $workflow->maxConcurrent <= 0 ? null : $workflow->maxConcurrent;
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
     *
     * The session's AgentManager, when one is set, rides along: it is what
     * judges each stage call against the stage's own `tools:` declaration
     * ({@see \SugarCraft\Code\Hooks\BuiltIn\SubAgentGrantHook}).
     */
    public function bindEngineBackend(EngineBackend $engine): void
    {
        $executor = $this->pool->forkedExecutor();
        if ($executor instanceof EngineExecutor) {
            $executor->bind($engine, $this->agentManager);
        }
    }

    /**
     * Late and mutable for the same reason as {@see bindEngineBackend()}: the
     * session build site owns the hook chain and hands it over only after the
     * launch workspace closes, through {@see
     * \SugarCraft\Code\Hooks\HookManager::armWorkflow()}, before the engine's
     * first run. Null keeps the constructor's documented unarmed contract —
     * the engine announces nothing. The CLI verb seat never calls this, so a
     * headless run stays byte-identical to the shape it has always had.
     */
    public function bindHookDispatcher(?HookDispatcher $hookDispatcher): void
    {
        $this->hookDispatcher = $hookDispatcher;
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
    public function run(string $workflowPath, array $context = [], ?CancellationToken $cancellation = null): WorkflowResult
    {
        self::refuseReservedContextKeys($context);
        $workflow = $this->registry->load($workflowPath);
        $result = $this->underCancellation(
            $cancellation,
            fn(): WorkflowResult => $this->runFromWorkflow($workflow, $context, 0, null, $workflowPath, $workflowPath),
        );
        $this->rememberResult($workflowPath, $result, $workflowPath);

        return $result;
    }

    /**
     * Run $run with $cancellation as the live run's token: while it runs, a
     * cancel kills every agent the current stage has in flight
     * ({@see cancelDispatchingPools()}), and the stage loop stops at its next
     * check with a {@see WorkflowStatus::Cancelled} result
     * ({@see runGuardedFromWorkflow()}).
     *
     * The PUSH half is what makes Esc Esc effective: while Chat handles the
     * keystroke the run's fiber is suspended inside the pool's idle poll, so
     * nothing on the run's stack can look at a flag until the stage's agents
     * finish on their own — which, for a stage running a model's tool loop,
     * is the whole stage. {@see AgentWorkerPool::cancelAll()} on the loop is
     * asynchronous since wave 10 (`killTreeAsync` with a grace), so calling
     * it from inside Chat's `update()` does not block the frame.
     *
     * A run nested inside a run that already has a token keeps the outer one.
     */
    private function underCancellation(?CancellationToken $cancellation, \Closure $run): WorkflowResult
    {
        if ($cancellation === null || $this->runCancellation !== null) {
            return $run();
        }

        $this->runCancellation = $cancellation;
        $detach = $cancellation->onCancel(function (): void {
            $this->cancelDispatchingPools();
        });

        try {
            return $run();
        } finally {
            $detach();
            $this->runCancellation = null;
            $this->dispatchPools = [];
        }
    }

    /** Cancel every pool the current stage is still dispatching on. */
    private function cancelDispatchingPools(): void
    {
        foreach ($this->dispatchPools as $reference) {
            $reference->get()?->cancelAll();
        }
    }

    /**
     * Register $pool as one the current stage dispatches on, so a cancel
     * reaches its agents. A pool built AFTER the cancel — a pipeline's next
     * step — is cancelled before it dispatches anything, so its first
     * `executeAll()` returns at once rather than starting work the user
     * stopped.
     */
    private function dispatching(AgentWorkerPool $pool): AgentWorkerPool
    {
        if ($this->runCancellation === null) {
            return $pool;
        }

        $this->dispatchPools = array_values(array_filter(
            $this->dispatchPools,
            static fn(\WeakReference $reference): bool => $reference->get() !== null,
        ));
        $this->dispatchPools[] = \WeakReference::create($pool);

        if ($this->runCancellation->isCancelled()) {
            $pool->cancelAll();
        }

        return $pool;
    }

    /**
     * The result a cancelled run returns: the stages that settled, the last
     * of them marked {@see WorkflowStatus::Cancelled} when the cancel landed
     * while it ran — its agents were killed, so whatever it reports is not
     * the stage's work — and the run itself Cancelled.
     *
     * A pause requested before the cancel still stands, recording the
     * successful prefix, as it does for a run whose stage failed.
     *
     * @param \Closure(WorkflowStatus): WorkflowResult $snapshot
     * @param list<StageResult> $stageResults by reference: the live list the snapshot reads
     */
    private function cancelledRun(
        \Closure $snapshot,
        array &$stageResults,
        bool $stageInterrupted,
        string $interruptId,
        ?string $loadPath,
        bool $pauseWasRequested,
        bool &$pauseRequested,
    ): WorkflowResult {
        if ($stageInterrupted && $stageResults !== []) {
            $last = array_key_last($stageResults);
            $stage = $stageResults[$last];
            $stageResults[$last] = new StageResult(
                stageName: $stage->stageName,
                status: WorkflowStatus::Cancelled,
                output: $stage->output,
                error: self::CANCELLED_STAGE_ERROR,
                agents: $stage->agents,
                startedAt: $stage->startedAt,
                completedAt: $stage->completedAt,
            );
        }

        $cancelled = $snapshot(WorkflowStatus::Cancelled);
        if ($pauseWasRequested) {
            $pauseRequested = true;
            $this->writePauseFile($interruptId, $cancelled, $loadPath, true);
        } elseif ($loadPath !== null) {
            // O1: a cancelled run with no pause standing leaves no file
            // today; the resilience record must not either.
            $this->withdrawPauseRecord($interruptId);
        }

        return $cancelled;
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
     * Execute a {@see Workflow} value the CALLER built — the model-authored
     * plan the `Workflow` tool parses with
     * {@see WorkflowRegistry::fromYamlString()} (roadmap 4.10-2) — through the
     * same stage loop, declaration checks and fail-fast rule as {@see run()}.
     *
     * What it deliberately leaves out, because the workflow has no registry
     * name to come back by:
     *  - no result is remembered and no pause file is written, so
     *    {@see pause()}/{@see resume()} do not apply — there is nothing
     *    `load()` could re-read;
     *  - no SIGINT/SIGTERM handlers (R28) are installed. Their only job is
     *    that pause file, and the tool runs this inside the forked turn child,
     *    where a handler's plain `exit()` would run PHP's shutdown over the
     *    copy of the TUI's object graph that child holds. A cancelled turn
     *    kills the child's process tree, stage agents included.
     *
     * Stage agents run on THIS engine's pool, so a caller that wants them on a
     * particular session engine builds the engine with its own pool — the
     * `Workflow` tool builds one per call — rather than re-binding a shared
     * one ({@see bindEngineBackend()} is a setter on the pool's executor, and
     * a re-bind would move every other run on that pool to a different
     * engine).
     *
     * @param array<string, mixed> $context Key-value pairs for {{variable}} interpolation.
     * @throws \InvalidArgumentException When a context key is reserved (starts with `@`).
     */
    public function runWorkflow(Workflow $workflow, array $context = [], ?CancellationToken $cancellation = null): WorkflowResult
    {
        self::refuseReservedContextKeys($context);

        return $this->underCancellation(
            $cancellation,
            fn (): WorkflowResult => $this->runFromWorkflow($workflow, $context, 0, null, interruptible: false),
        );
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
            $this->writePauseFile($frame['key'], ($frame['snapshot'])(WorkflowStatus::Paused), $frame['loadPath'], true);

            return;
        }

        $key = $this->runKeyFor($workflowId);
        if ($key === null) {
            throw new WorkflowNotRunningException(
                "No result found for workflow '{$workflowId}'. Run the workflow first before pausing."
            );
        }

        $this->writePauseFile($key, $this->resultsByName[$key], $this->loadPathsByKey[$key] ?? null, true);
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
     * the pause file's name, so a later {@see pause()} or {@see status()}
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
     * @return WorkflowResult The final result after the resumed run completes, fails, or is paused again.
     * @throws WorkflowNotRunningException When no pause file exists for this workflow, or when the
     *                                      only file found is a crash record whose writer process
     *                                      is still live elsewhere (refused so two processes never
     *                                      drive one run; an ORPHANED record resumes normally).
     * @throws WorkflowNotFoundException   When the workflow definition can no longer be loaded.
     */
    public function resume(string $workflowId, ?CancellationToken $cancellation = null): WorkflowResult
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

        // A crash-class record whose writer is still running is NOT a pause at
        // all: another process owns this run and its record. Resuming here would
        // start a second writer racing the first over the same file (and
        // double-dispatching any agent stages), so refuse loudly instead — the
        // orphaned case falls through to the ordinary replay path below.
        if (self::recordNeedsLivenessJudgement($data) && !self::crashRecordIsOrphaned($data, $pauseFile)) {
            throw new WorkflowNotRunningException(
                "Workflow '{$workflowId}' is live in another process (pid "
                . (int) ($data['pid'] ?? 0) . "), so its record '" . basename($pauseFile, '.json')
                . "' cannot be resumed here"
            );
        }

        $workflow = $this->registry->load($workflowPath);
        $context = self::withSanitizedResults(is_array($data['context'] ?? null) ? $data['context'] : []);
        [$stagesCompleted, $prior] = $this->priorProgress($data, $workflow, $context);
        // C9L: a settled join is a decision already paid for. If the workflow
        // file was edited mid-pause so any recorded join stage's declaration
        // no longer matches the ledger the pause wrote, resuming would
        // re-decide it against the new shape — refuse the resume, loudly.
        $this->refuseEditedInputJoins($workflowId, $workflow, $prior);

        // The pause file's OWN name is the pause identity, not the string the
        // user typed: a second interrupt during this resumed run must land on the
        // same file rather than forking a second one under the other spelling.
        // And $loadPath is threaded separately so that file stays loadable — it
        // used to be written as whatever identifier resume() was called with,
        // which for a run ID is not a name load() can resolve.
        $pauseKey = basename($pauseFile, '.json');

        $pauseRequested = false;
        $runId = is_string($data['workflowId'] ?? null) && $data['workflowId'] !== '' ? $data['workflowId'] : $workflowId;
        // A full closure, not `fn`: $pauseRequested is an OUT parameter, and
        // an arrow function would capture a copy of it.
        $result = $this->underCancellation(
            $cancellation,
            function () use ($workflow, $context, $stagesCompleted, $runId, $pauseKey, $workflowPath, $prior, &$pauseRequested): WorkflowResult {
                return $this->runFromWorkflow(
                    $workflow,
                    $context,
                    $stagesCompleted,
                    $runId,
                    $pauseKey,
                    $workflowPath,
                    $prior,
                    $pauseRequested,
                );
            },
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
     * @return array{0: int, 1: array{stageResults: list<StageResult>, totalTokens: int, totalCost: float, startedAt: \DateTimeImmutable, stageInputHashes: array<string, string>, dynamicWaves: array<array-key, mixed>, inputJoins: array<string, list<array{from: list<string>, as: string}>>, recordedResults: array<string, StageResult>, totalsCountRecordedRows: bool}}
     */
    private function priorProgress(array $data, Workflow $workflow, array $context): array
    {
        $recorded = is_numeric($data['stagesCompleted'] ?? null) ? max(0, (int) $data['stagesCompleted']) : 0;
        $rows = is_array($data['stageResults'] ?? null) ? array_values($data['stageResults']) : [];

        $succeeded = [];
        // C13: every settled recorded row — successes and C2 skips — keyed by
        // stage name, not just the positionally-skipped prefix. The skip stays
        // positional (skipping an unrecorded stage is not recoverable); this
        // map exists so the hash-gated cache can find a later stage's recorded
        // answer (a skip row can never serve one: cachedStageResult demands
        // a success).
        $recordedByName = [];
        foreach ($rows as $row) {
            $stage = is_array($row) ? $this->deserializeStageResult($row) : null;
            // C2: a recorded skip is a settled fact of the prefix, not a
            // break — the resume must neither re-dispatch nor re-decide the
            // stage; failures and cancellations still end the walk.
            if ($stage === null || (!$stage->isSuccess() && $stage->status !== WorkflowStatus::Skipped)) {
                break;
            }
            $succeeded[] = $stage;
            $recordedByName[$stage->stageName] = $stage;
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
            // C13 substrate pass-through: whatever hashes this file carries.
            // An old record has none, and an empty map is what the resumed
            // run seeds with — absence downstream is a cache miss, by law.
            'stageInputHashes' => is_array($data['stageInputHashes'] ?? null) ? $data['stageInputHashes'] : [],
            // C8 substrate, same law: the materialised wave lists ride the
            // pause file verbatim. A resume replays what this map records and
            // NEVER re-globs; a pre-C8 record carries none, which is exactly
            // the state where a fresh (unstarted) dynamic stage may expand.
            'dynamicWaves' => is_array($data['dynamicWaves'] ?? null) ? $data['dynamicWaves'] : [],
            // C9L substrate, same law: the recorded join declarations verbatim.
            // A pre-C9L record carries none — absence makes the resume-side
            // guard proceed (a missing ledger is "no claim made", not a broken
            // one; the join hazard predates the record, refusing old pauses
            // would buy zero safety for zero cost paid).
            'inputJoins' => is_array($data['inputJoins'] ?? null) ? $data['inputJoins'] : [],
            // P0-3: the dispatch count so far; a pre-P0-3 or hand-mangled
            // record restarts the count at zero (it can only be generous).
            'agentsDispatched' => is_int($data['agentsDispatched'] ?? null) && $data['agentsDispatched'] > 0
                ? $data['agentsDispatched']
                : 0,
            'recordedResults' => $recordedByName,
            // Whether the seed totals are the file's cumulative spend (which
            // was written in the same snapshot as the rows above, so it ALREADY
            // counts every recorded row) or a sum of the skipped prefix (which
            // counts only that prefix). The cache carry reads this to decide
            // whether a carried row's spend is already paid for. A record
            // carrying only one numeric total falls to the conservative side:
            // the carry adds, so a report may over-count but never deflate.
            'totalsCountRecordedRows' => is_numeric($data['totalTokens'] ?? null)
                && is_numeric($data['totalCost'] ?? null),
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
     *  2. the pause file — its recorded status, with one reconciliation: an
     *     O1 crash record (stamped by its writer's process, without the
     *     cooperative marker) is judged at query time. A dead writer answers
     *     {@see WorkflowStatus::Orphaned} — resumable progress, honestly
     *     labeled, not a pause that was ever chosen; a writer still live
     *     elsewhere answers {@see WorkflowStatus::Running}. Cooperative and
     *     legacy (unsigned) records read exactly as they always have.
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
     * @throws WorkflowNotRunningException When this engine has no live or remembered run and no pause file exists,
     *                                     or when a pause file exists but is corrupt (missing, non-string, or
     *                                     unrecognized status value).
     */
    public function status(string $workflowId): WorkflowStatus
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

        // m10: the enum is string-backed and WorkflowStatus::from() narrows its
        // parameter to string, so a status written as any other JSON type (an
        // array, a bool, a number) raises a TypeError inside from(). A TypeError
        // is NOT a ValueError, so it would sail straight past the catch below
        // and crash the caller instead of being handled. Refuse it with the
        // same loud WorkflowNotRunningException contract every other corrupt
        // shape on this path already carries — a narrow type check, so genuine
        // engine Errors keep propagating untouched.
        if (!is_string($statusValue)) {
            throw new WorkflowNotRunningException(
                "Pause file for '{$workflowId}' is corrupt: status value is "
                . get_debug_type($statusValue) . ', expected string'
            );
        }

        try {
            $recorded = WorkflowStatus::from($statusValue);
        } catch (\ValueError) {
            throw new WorkflowNotRunningException(
                "Pause file for '{$workflowId}' is corrupt: invalid status value '{$statusValue}'"
            );
        }

        if ($recorded === WorkflowStatus::Paused && self::recordNeedsLivenessJudgement($data)) {
            return self::crashRecordIsOrphaned($data, $pauseFile)
                ? WorkflowStatus::Orphaned
                : WorkflowStatus::Running;
        }

        return $recorded;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::status()} instead. REMOVED-IN: 2.0.0.
     */
    public function getStatus(string $workflowId): WorkflowStatus { return $this->status($workflowId); }

    /**
     * Build the filesystem path to a workflow's pause file.
     *
     * Anchored to the REGISTRY's own user-tier directory rather than to
     * {@see HomeDirectory::path()}. The two agree for the default registry — it
     * expands `~/.candy-code/workflows/` through the same resolver — but they
     * stop agreeing the moment a caller points a registry somewhere else, and
     * this directory is not a cache: {@see resume()} reads `workflowPath` and
     * `context` back out of it and hands them to `load()`. A registry pointed at
     * a trusted directory that still paused into `~` under the stand-in home
     * (which is `sys_get_temp_dir()`, i.e. world-writable) would be resumable
     * from a file any local user could have written — the exact hazard
     * {@see \SugarCraft\Code\Cli\Bootstrap::workflowEngine()}'s docblock claims
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
                    'attempts' => $ar->attempts,
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
                // A pause file written before retries existed has no count:
                // every agent then ran once.
                attempts: is_int($agent['attempts'] ?? null) && $agent['attempts'] > 0 ? $agent['attempts'] : 1,
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
     * The stages a run has DONE — the settled prefix of its results:
     * successes, with C2's recorded skips riding along.
     *
     * The loop fails fast, so settled rows are always a prefix and the first
     * failure ends it; a skip belongs in the prefix because its stage is a
     * settled fact of the run — re-running a stage on resume is recoverable,
     * but re-deciding a skip would let it run under outputs that changed
     * since the file was written.
     *
     * @return list<StageResult>
     */
    private static function completedStages(WorkflowResult $result): array
    {
        $done = [];
        foreach ($result->stageResults as $stage) {
            if (!$stage->isSuccess() && $stage->status !== WorkflowStatus::Skipped) {
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
     * Only the settled prefix — successes and recorded skips ({@see
     * completedStages()}) — is persisted, and `stagesCompleted` is its length,
     * so a resume re-runs a stage that failed instead of skipping it (AUDIT
     * WF-2). The totals are the run's whole spend, a failed attempt's included.
     *
     * $clean is the writer's declaration of HOW this record came to exist:
     * true when a cooperative stop (explicit pause, the stage loop's own
     * pause stop, a cancelled/failed run with pause standing, the signal
     * handler) reached the write on purpose, false for the O1 per-stage
     * refresh, which is written during ordinary progress and is therefore the
     * only shape a SIGKILL can leave behind. Every write also signs the
     * record with the writing process's identity (`pid`, plus `pidStartedAt`
     * where procfs can answer), so a later reader can tell three things
     * apart: a hand-written or cooperative record (no stamp or `pausedCleanly`
     * — read exactly as before), a crashed run's still-progress record
     * ({@see WorkflowStatus::Orphaned}), and a run whose writer is STILL live
     * elsewhere ({@see WorkflowStatus::Running} — never resumed from a second
     * process). Purely additive: a reader that ignores the keys sees the old
     * file.
     */
    private function writePauseFile(string $key, WorkflowResult $result, ?string $loadPath, bool $clean): void
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

        // Process identity + cooperative-stop marker (P2 orphan
        // reconciliation). The stamp is what lets status()/resume() tell a
        // crashed run's progress record from a paused one and from a record
        // whose writer is still working in another process; the heartbeat
        // age of this very file is the secondary bound. Absent on legacy
        // records, which keep their pre-P2 reading exactly.
        $writerPid = (int) \getmypid();
        $data['pid'] = $writerPid;
        $pidStartedAt = $writerPid > 0 ? BackgroundSupervisor::procStartTime($writerPid) : null;
        if ($pidStartedAt !== null) {
            $data['pidStartedAt'] = $pidStartedAt;
        }
        if ($clean) {
            $data['pausedCleanly'] = true;
        }

        // C13 substrate, purely additive: the identity hashes of every stage
        // this live run has settled. Read from the slot, so every writer —
        // the O1 refresh, an explicit pause, the signal handler — persists
        // the same map. A run that is not live here (pause-after-finish) or
        // a pre-C13 resume seed simply omits the key: absence is a cache
        // miss, never a mis-read.
        $slotToken = $this->liveRunFor($key);
        $hashes = $slotToken === null ? [] : ($this->liveRuns[$slotToken]['inputHashes'] ?? []);
        if ($hashes !== []) {
            $data['stageInputHashes'] = $hashes;
        }
        // C8 substrate, same law: the materialised wave lists. A resume
        // replays what this map records; a run with no dynamic stage omits
        // the key entirely.
        $waves = $slotToken === null ? [] : ($this->liveRuns[$slotToken]['dynamicWaves'] ?? []);
        if ($waves !== []) {
            $data['dynamicWaves'] = $waves;
        }
        // C9L substrate, same law: the join DECLARATIONS (never payloads) of
        // every settled stage that joins. A resume whose edited YAML no longer
        // matches this ledger refuses (refuseEditedInputJoins); a run with no
        // joined stage omits the key entirely.
        $joins = $slotToken === null ? [] : ($this->liveRuns[$slotToken]['inputJoins'] ?? []);
        if ($joins !== []) {
            $data['inputJoins'] = $joins;
        }
        // P0-3: the run-wide dispatch count, so a resume keeps counting
        // against config.maxAgents instead of starting a fresh allowance.
        // Only a LIVE run's count is true; a run with none omits the key.
        if ($slotToken !== null && $this->agentsDispatched > 0) {
            $data['agentsDispatched'] = $this->agentsDispatched;
        }

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
     * Withdraw the O1 per-stage resilience record at a clean terminal state
     * (completed / failed / cancelled with no pause standing). A vanished
     * file is success: an explicit resume() may have consumed it in the
     * meantime, and the run's remembered result then carries the truth.
     */
    private function withdrawPauseRecord(string $key): void
    {
        $pauseFile = $this->getPauseFilePath($key);

        if (is_file($pauseFile)) {
            @unlink($pauseFile);
        }
    }

    /**
     * How stale the heartbeat (the pause file's own mtime, refreshed by every
     * O1 write) may be before a crash-class record whose writer we cannot
     * positively identify is judged orphaned rather than live.
     *
     * Sized at twice the default per-stage timeout (3600s — {@see
     * \SugarCraft\Code\Workflows\Workflow::$timeout}), because a live run
     * legitimately writes nothing between stage boundaries. This window
     * governs ONLY the fallback where the writer's process start time could
     * not be compared (no procfs start-time answer); when `pidStartedAt` was
     * recorded and procfs answers, the comparison is exact and the window
     * never applies. A stage configured with a custom timeout longer than
     * this window can therefore only be trusted as live while pid + start
     * time verify; on platforms where they cannot, a very long live stage may
     * be misjudged orphaned — the misjudgement is safe in the resume-
     * allowed direction only because the writer's next stage-boundary refresh
     * simply re-stamps the record.
     */
    public const ORPHAN_HEARTBEAT_STALE_SECONDS = 7200;

    /**
     * Is this crash-class pause record (O1-shaped: pid stamped, no
     * `pausedCleanly` marker) the residue of a dead process, or is its
     * writer still at work?
     *
     * Liveness is checked AT QUERY TIME, on demand — never polled (E646).
     * The semantics mirror the private {@see \SugarCraft\Code\Agents\TaskList}
     * claim-release probe by reusing its two PUBLIC helpers rather than
     * copying them: a zombie is dead, and a recorded start time that procfs
     * can still read decides pid-reuse EXACTLY (a recycled pid starts later,
     * so it can never masquerade as the original writer). Only when no
     * start-time evidence exists anywhere does the heartbeat window decide,
     * erring toward "alive" while fresh — a record judged a little longer,
     * never one stolen from a writer still working.
     *
     * @param array<string,mixed> $data   Decoded pause record.
     * @param string              $pauseFile Path to the record (its mtime is the heartbeat).
     */
    private static function crashRecordIsOrphaned(array $data, string $pauseFile): bool
    {
        $pid = isset($data['pid']) && is_int($data['pid']) ? $data['pid'] : 0;
        $recordedStart = isset($data['pidStartedAt']) && is_int($data['pidStartedAt']) ? $data['pidStartedAt'] : null;

        $stat = $pid > 0 ? ProcessTree::stat($pid) : null;
        $observedStart = $pid > 0 ? BackgroundSupervisor::procStartTime($pid) : null;

        if ($stat !== null && ($stat['state'] === 'Z' || $stat['state'] === 'X')) {
            return true; // zombie / reaped-exit: will do no more work
        }

        if ($recordedStart !== null && $observedStart !== null) {
            return $observedStart !== $recordedStart; // exact, pid-reuse-proof
        }

        if ($pid <= 0) {
            return true; // unsigned crash record: no writer to be alive
        }

        $alive = \function_exists('posix_kill')
            ? (@\posix_kill($pid, 0) || \posix_get_last_error() === 1) // EPERM: alive, another uid
            : $stat !== null;

        if (!$alive) {
            return true;
        }

        // The stat cache must not hand back a pre-refresh mtime: in a
        // long-lived reader the cached value would over-age a live writer's
        // heartbeat exactly when no start-time proof is available.
        \clearstatcache(true, $pauseFile);
        $heartbeat = time() - (int) @filemtime($pauseFile);

        return $heartbeat > self::ORPHAN_HEARTBEAT_STALE_SECONDS;
    }

    /**
     * Does this decoded record claim a cooperative pause, or is it the raw
     * O1 progress shape a crash can leave? Cooperative and legacy (unsigned)
     * records keep their pre-P2 reading exactly; only stamped crash records
     * consult {@see crashRecordIsOrphaned()} — and never inside a run this
     * same process has live ({@see liveRunFor()} answered first upstream).
     *
     * @param array<string,mixed> $data
     */
    private static function recordNeedsLivenessJudgement(array $data): bool
    {
        return ($data['status'] ?? null) === WorkflowStatus::Paused->value
            && isset($data['pid'])
            && ($data['pausedCleanly'] ?? null) !== true;
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
     * @param bool             $interruptible      Whether the R28 SIGINT/SIGTERM handlers are installed for the
     *                                              loop. False only for {@see runWorkflow()}, whose run cannot be
     *                                              resumed and may be inside a forked turn child.
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
        bool $interruptible = true,
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
            $result = $this->runGuardedFromWorkflow(
                $workflow,
                $context,
                $currentStageIndex,
                $workflowIdOverride,
                $pauseId,
                $loadPath,
                $prior,
                $pauseRequested,
                $interruptible,
            );
            // C6: the single run terminal for every entry point. Announced
            // here — not in a finally — so a throwing run announces nothing
            // (a crash is not a completion), while every RETURNED result,
            // including a pre-flight refusal, passes the natural-terminal
            // check inside the announce method. Nested `uses:` include runs
            // re-enter this funnel and announce their own completion.
            $this->announceWorkflowCompleted($result);

            return $result;
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
                    . '(Esc Esc stops a run, but its agents take a moment to wind down.)'
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
        bool $interruptible = true,
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

        // P0-3: the OUTERMOST run owns the agent backstop; an included run
        // (usesChain non-empty) keeps counting against its parent's ceiling.
        // A resume continues the count its pause record carries.
        if ($this->usesChain === []) {
            $this->agentsDispatched = $prior['agentsDispatched'] ?? 0;
            $this->agentCeiling = $workflow->maxAgents;
        }

        // Whole-workflow pre-flight: a stage 5 that declares a refused tool
        // must not cost four stages of real agent work first. Reported as that
        // stage's failure rather than thrown — see preflightRefusal().
        $refusalResult = $this->preflightRefusal($workflow, $resolvedWorkflowId, $stageResults, $context, $totalTokens, $totalCost, $startedAt);

        if ($refusalResult !== null) {
            return $refusalResult;
        }

        $previousAsyncSignals = !$interruptible ? null : $this->installInterruptHandlers(
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
                // C13/C8 replay ledgers, seeded from the pause record; an old
                // record has neither and absence downstream is a cache miss.
                'inputHashes' => $prior['stageInputHashes'] ?? [],
                'dynamicWaves' => $prior['dynamicWaves'] ?? [],
                // C9L: seed so a RE-pause of this resumed run preserves the
                // first incarnation's join rows (lineage law, see refuse()).
                'inputJoins' => $prior['inputJoins'] ?? [],
            ];

            // M-11 (cc-fix-1111): one run, one signal register; reset at the
            // top so a fresh run (or a resume) starts with no positive
            // reports and its first render pays the full five polls.
            $this->stageWriteSignal = null;

            foreach ($workflow->stages as $stageIndex => $stage) {
                // Skip stages that were already completed (resume support)
                if ($stageIndex < $currentStageIndex) {
                    continue;
                }

                // Cancelled (Chat's Esc Esc) before this stage started — on the
                // run's very first tick, or between two stages: nothing more
                // is dispatched. Checked ahead of a pause, because a cancel is
                // the later and stronger of the two requests.
                if ($this->runCancellation?->isCancelled()) {
                    return $this->cancelledRun(
                        $snapshot,
                        $stageResults,
                        false,
                        $interruptId,
                        $loadPath,
                        $this->liveRuns[$liveToken]['pauseRequested'],
                        $pauseRequested,
                    );
                }

                // A live pause() landed while the previous stage was in flight
                // (Chat's fiber was suspended in the pool): stop before starting
                // this one, and rewrite the file pause() wrote with the stage
                // that has finished since.
                if ($this->liveRuns[$liveToken]['pauseRequested']) {
                    $pauseRequested = true;
                    $paused = $snapshot(WorkflowStatus::Paused);
                    $this->writePauseFile($interruptId, $paused, $loadPath, true);

                    return $paused;
                }

                $stageStartedAt = new \DateTimeImmutable();

                $stageType = $stage['type'] ?? '';
                if (!in_array($stageType, Workflow::STAGE_TYPES, true)) {
                    throw new UnsupportedStageTypeException(
                        "Stage type '{$stageType}' is not supported. Only 'stage', 'parallel', 'pipeline', and 'verification' are implemented."
                    );
                }

                // C2's when-guard, extracted verbatim by C4 to fund the loop's
                // do-head inside the DocFigure window (see skippedWhenRow).
                $skipped = $this->skippedWhenRow($stage, $context, $stageStartedAt);
                if ($skipped !== null) {
                    $stageResults[] = $skipped;
                    // C6 adjudication: a skipped stage never reached a
                    // dispatch, so it raises ONLY StageComplete — status
                    // carries the distinction — and no StageStarted. Round
                    // 0 says "no round ever ran".
                    $this->announceStageComplete($resolvedWorkflowId, $skipped->stageName, $skipped->status->value, 0);
                    continue;
                }

                // C13 opt-in: a `cache: true` stage whose record still matches
                // its declaration carries without dispatch — door defaults closed.
                $cached = $this->cachedStageResult($stage, $stageType, $prior);
                if ($cached !== null) {
                    // C6: a cache replay announces NOTHING — no dispatch
                    // happened this run, so neither StageStarted nor a settle
                    // event would be true. The replayed row still rides the
                    // workflow's own totals and its WorkflowComplete totals.
                    $context[$cached->stageName . '.output'] = ($stage['capture'] ?? false) === true
                        ? $this->captureStageOutput($interruptId, $stageIndex, $cached)
                        : $cached->output ?? '';
                    $stageResults[] = $cached;
                    if (($prior['totalsCountRecordedRows'] ?? false) !== true) {
                        $totalTokens += $this->sumTokens($cached);
                        $totalCost += $this->sumCost($cached);
                    }
                    continue;
                }

                // C4: a `loop:` map (parse-normalized, so is_array is the
                // whole door) re-dispatches this stage until the clause holds
                // or max rounds ran. Non-looped stages never enter the do —
                // their body bytes and behavior are the pre-C4 ones.
                $loop = is_array($stage['loop'] ?? null) ? $stage['loop'] : null;
                $round = 1;
                // P1-5: a looped stage's per-round tokens ({{previous}},
                // {{seen}}, {{fresh}}, like {{round}}) shadow same-named
                // context keys only while the loop runs; the author's values
                // come back afterwards. `accumulate:` keeps its deduped set
                // here, in memory — a resume re-runs the whole loop.
                $loopName = (string) ($stage['name'] ?? 'unknown');
                $accumulate = is_array($loop['accumulate'] ?? null) ? $loop['accumulate'] : null;
                $accumulator = ['items' => [], 'keys' => [], 'index' => [], 'fresh' => [], 'dry' => 0];
                $shadowed = [];
                if ($loop !== null) {
                    foreach (self::LOOP_TOKENS as $token) {
                        if (array_key_exists($token, $context)) {
                            $shadowed[$token] = $context[$token];
                        }
                    }
                    if ($accumulate !== null) {
                        $context[StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY] = 0;
                    }
                }
                do {
                    if ($loop !== null) {
                        $context['round'] = $round;
                        // Round 1 has no previous round: the token keeps
                        // whatever the context had (usually nothing, so it
                        // stays verbatim).
                        if ($round > 1) {
                            $context['previous'] = $context[$loopName . '.output'] ?? '';
                        }
                        if ($accumulate !== null) {
                            $context['seen'] = self::compactJson($accumulator['keys']);
                            $context['fresh'] = self::compactJson($accumulator['fresh']);
                        }
                    }

                    // C6: one StageStarted per dispatched ROUND, raised before
                    // any resource acquisition — a looped stage announces once
                    // per round, matching what the executor will see.
                    $this->announceStageStarted($resolvedWorkflowId, (string) ($stage['name'] ?? 'unknown'), $round);

                    // Every agent the stage ran is recorded by the executor as each
                    // settles — an executor that throws AFTER one ran still bills it.
                    $dispatched = [];
                    $heldLocks = [];
                    $failedNames = null;
                    $votes = null;

                    try {
                        // O3: exclusive, sorted, bounded flocks on every declared
                        // resource BEFORE dispatch — full law: acquireStageResources().
                        $heldLocks = $this->acquireStageResources($stage, $stageType);

                        // C11: an include-compose stage runs a named workflow as
                        // its body; the registry already refused body+uses.
                        // C4: inside the do-body on purpose — a looped uses
                        // stage re-dispatches the included workflow per round.
                        if (array_key_exists('uses', $stage)) {
                            $stageResult = $this->executeIncludedWorkflow($stage, $context, $workflow, $stageStartedAt);
                        } elseif ($stageType === 'parallel') {
                            $stageResult = $this->executeParallelStage($stage, $context, $workflow, $dispatched, $this->liveRuns[$liveToken]['dynamicWaves'], $failedNames);
                        } elseif ($stageType === 'stage') {
                            $stageResult = $this->executeStage($stage, $context, $dispatched, $workflow->timeout);
                        } elseif ($stageType === 'pipeline') {
                            $stageResult = $this->executePipelineStage($stage, $context, $dispatched, $workflow->maxConcurrent, $workflow->timeout);
                        } else {
                            $stageResult = $this->executeVerificationStage($stage, $context, $dispatched, $workflow->timeout, null, $votes);
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
                    } finally {
                        // O3: every lock this stage took goes back on every exit
                        // path — success, failure, throw, pause-request return.
                        $this->releaseStageResources($heldLocks);
                    }

                    // P1-5: fold this round's items into the accumulated set
                    // before anything reads the round — a round whose rows
                    // cannot be folded fails like any other failed round.
                    if ($accumulate !== null) {
                        $stageResult = self::accumulateRound($stage, $stageType, $stageResult, $dispatched, $accumulate, $accumulator);
                        $context[StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY] = $accumulator['dry'];
                    }

                    // This stage's output and its agents' results, for downstream
                    // interpolation — written here, once the stage has settled, so
                    // a live pause's snapshot never holds half a stage.
                    //
                    // S3: a captured stage hands downstream the capture file's
                    // PATH plus a bounded preview, not the whole dump — the file
                    // holds every byte for whoever needs detail, the prompt
                    // envelope stays cache-stable, and no reader inherits a
                    // length-truncation hazard. Uncaptured runs take the right
                    // side of the ternary: byte-identical to before S3.
                    $context[$stageResult->stageName . '.output'] = ($stage['capture'] ?? false) === true && !$stageResult->isFailure()
                        ? $this->captureStageOutput($interruptId, $stageIndex, $stageResult)
                        : $stageResult->output ?? '';
                    $context = self::withResults($context, $dispatched);
                    // P1-3: a minSuccess stage records its casualties for
                    // `{{<stage>.failed}}` — a JSON list (empty when none
                    // failed) under the `<stage>.failed` context key, beside
                    // `<stage>.output`, so it rides the pause record's
                    // context verbatim like the output does.
                    if ($failedNames !== null) {
                        $context[$stageResult->stageName . '.failed'] = json_encode($failedNames, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    }
                    // P1-4: a verifier panel's votes ride beside it the same
                    // way, as `{{<stage>.votes}}`.
                    if ($votes !== null) {
                        $context[$stageResult->stageName . '.votes'] = self::compactJson($votes);
                    }
                    // P0-3: every dispatch was reserved once up front; the
                    // retries and stall restarts the pool ran on top of that
                    // are counted as they settle, so the NEXT dispatch past
                    // the ceiling is refused.
                    foreach ($dispatched as $record) {
                        $this->agentsDispatched += max(0, $record['result']->attempts - 1);
                    }

                    $totalTokens += $this->sumTokens($stageResult);
                    $totalCost += $this->sumCost($stageResult);
                    $round++;
                } while ($loop !== null
                    && $round <= $loop['max']
                    && $this->runCancellation?->isCancelled() !== true
                    && !StageCondition::evaluate($loop['until'], $context));

                if ($loop !== null) {
                    foreach (self::LOOP_TOKENS as $token) {
                        unset($context[$token]);
                    }
                    $context = $shadowed + $context;
                    unset($context[StageCondition::LOOP_DRY_ROUNDS_CONTEXT_KEY]);

                    // P1-5: an accumulating loop's recorded result IS the
                    // accumulated set — the stage output, the typed row
                    // under the stage name (so `{{find.items/0/id}}`, pointer
                    // conditions and `from: {stage: find, pointer: /items}`
                    // all read it) and the row the pause file keeps.
                    if ($accumulate !== null && !$stageResult->isFailure()) {
                        $document = self::compactJson([
                            'items' => $accumulator['items'],
                            'keys' => $accumulator['keys'],
                            'rounds' => $round - 1,
                        ]);
                        $stageResult = new StageResult(
                            stageName: $stageResult->stageName,
                            status: $stageResult->status,
                            output: $document,
                            error: $stageResult->error,
                            agents: $stageResult->agents,
                            startedAt: $stageResult->startedAt,
                            completedAt: $stageResult->completedAt,
                        );
                        $context[$stageResult->stageName . '.output'] = ($stage['capture'] ?? false) === true
                            ? $this->captureStageOutput($interruptId, $stageIndex, $stageResult)
                            : $document;
                        $typedRows = is_array($context[self::TYPED_RESULTS_CONTEXT_KEY] ?? null) ? $context[self::TYPED_RESULTS_CONTEXT_KEY] : [];
                        $typedRows[$stageResult->stageName] = $document;
                        $context[self::TYPED_RESULTS_CONTEXT_KEY] = $typedRows;
                    }
                }

                $stageResults[] = $stageResult;

                // C6: the settle event. The engine's own fail-fast predicate
                // (StageResult::isFailure() — the same one the fail-fast door
                // below consults) is the SOLE trigger, so every settled stage
                // raises exactly ONE of StageFail / StageComplete, never both
                // and never none. `round` is the round that settled it
                // ($round has just been incremented, so rounds-run == $round-1).
                if ($stageResult->isFailure()) {
                    $this->announceStageFail(
                        $resolvedWorkflowId,
                        $stageResult->stageName,
                        $stageResult->status->value,
                        $round - 1,
                        $stageResult->error ?? '',
                    );
                } else {
                    $this->announceStageComplete(
                        $resolvedWorkflowId,
                        $stageResult->stageName,
                        $stageResult->status->value,
                        $round - 1,
                    );
                }

                // C13 substrate: record WHAT THIS STAGE WAS ASKED to run —
                // the pre-interpolation prompt template plus model and
                // schema identity — so a future resume-side cache can match
                // a settled stage against a later run's declarations without
                // re-dispatching. Only successful settles are hashed: a
                // failed stage produced nothing cacheable, and an identity
                // for it would read as an invitation. Stored by stage name
                // on the live slot, so every writer of the pause record
                // (incremental, explicit, signal) carries the same map.
                if ($stageResult->isSuccess()) {
                    $this->liveRuns[$liveToken]['inputHashes'][$stageResult->stageName] = self::stageInputHash($stage, $stageType);
                    $joinRows = $this->stageJoinDeclarations($stage, $stageType);
                    if ($joinRows !== []) {
                        $this->liveRuns[$liveToken]['inputJoins'][$stageResult->stageName] = $joinRows;
                    }
                }

                // O1: refresh the resilience record with this stage counted.
                // Best-effort deliberately — a resilience write that fails
                // must not break the run it protects; the explicit pause
                // paths still throw through their callers, and only registry-
                // backed runs (a loadPath to resume from) keep a record at
                // all, so runFromPhp()/runWorkflow() stay byte-identical.
                if ($loadPath !== null && !$stageResult->isFailure()) {
                    try {
                        // Crash-class by construction: this write happens in
                        // the middle of ordinary progress, so it is the only
                        // shape a SIGKILL can leave. Not a cooperative stop.
                        $this->writePauseFile($interruptId, $snapshot(WorkflowStatus::Paused), $loadPath, false);
                    } catch (\Throwable) {
                        // Ignored: the next refresh, or an explicit pause, retries.
                    }
                }

                // Cancelled WHILE this stage ran: its agents were killed
                // (underCancellation()), so the stage's result — a failure, or
                // a "success" missing the agents that died — is not its work,
                // and no later stage runs. The tokens it spent are still the
                // run's spend, counted above.
                if ($this->runCancellation?->isCancelled()) {
                    return $this->cancelledRun(
                        $snapshot,
                        $stageResults,
                        true,
                        $interruptId,
                        $loadPath,
                        $this->liveRuns[$liveToken]['pauseRequested'],
                        $pauseRequested,
                    );
                }

                // Fail fast: stop processing on first stage failure
                if ($stageResult->isFailure()) {
                    $failed = $snapshot(WorkflowStatus::Failed);

                    // The stage in flight when a pause was requested failed.
                    // The run did fail, and says so; the user's pause still
                    // stands, recording the successful prefix, so a resume
                    // re-runs the stage that failed.
                    if ($this->liveRuns[$liveToken]['pauseRequested']) {
                        $pauseRequested = true;
                        $this->writePauseFile($interruptId, $failed, $loadPath, true);
                    } elseif ($loadPath !== null) {
                        // O1: a failed run with no pause standing leaves no
                        // file today, and the resilience record must not
                        // either — status() reads a file BEFORE the
                        // remembered result, so a leftover would report the
                        // Failed run as Paused with nothing new to resume.
                        $this->withdrawPauseRecord($interruptId);
                    }

                    return $failed;
                }
            }

            // A pause requested during the LAST stage has nothing left to stop:
            // the run completed. The file pause() wrote at request time is
            // withdrawn, or status would report a paused run with nothing to
            // resume and stages missing from it. A clean completion likewise
            // withdraws the O1 resilience record (3); an interrupted one keeps
            // it for resume().
            if ($this->liveRuns[$liveToken]['pauseRequested']) {
                $pauseRequested = true;
                $this->withdrawPauseRecord($interruptId);
            } elseif ($loadPath !== null) {
                $this->withdrawPauseRecord($interruptId);
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
     * C9: build a fan-in join payload and inject it into a COPY of the
     * interpolation context for one stage task.
     *
     * The join never persists into the running context: pause files carry no
     * `input` row, and a resumed run re-derives the same bytes from the same
     * recorded rows — the whole byte-stability story across pause/resume.
     * What the pause record DOES carry since C9L is the `inputJoins` ledger
     * ({@see refuseEditedInputJoins()}): the join DECLARATIONS of every
     * settled joined stage, so an edit to a declaration mid-pause REFUSES the
     * resume instead of silently re-deciding it. The payload itself is still
     * nothing-is-stored — always re-derived, never replayed. (The same
     * discipline {@see withSanitizedResults()} gives the typed map by
     * neutralizing hand-edited junk AT READ TIME; here a hand-edited
     * declaration cannot even reach read time.) The fixed name `input`
     * shadows a prior stage named `input` inside the joining stage's own
     * prompt only; downstream stages and the results map that survives the
     * stage keep the legacy meaning.
     *
     * Shapes ({@see \SugarCraft\Code\Workflows\TaskBuilder::trustedJoin()}
     * gated the vocabulary at load; this helper is the runtime half):
     * - `file-list`: present source rows joined by newlines — capture paths
     *   ride the rows themselves per the capture shape, so a file list IS the
     *   joined text. Every source missing degrades to a note line, NOT a
     *   refusal: a fan-in over optional producers must be able to say
     *   "nothing".
     * - `first`: the first present row verbatim.
     * - `all`: every present row under a `=== name ===` banner, declaration
     *   order, absent sources skipped.
     * - `results`: a JSON document keyed by source name whose members are the
     *   decoded typed rows ({@see TYPED_RESULTS_CONTEXT_KEY}) where present,
     *   falling back to the text row; the join's text row IS that JSON, so
     *   `{{input.results}}` and pointer paths like `{{input.a/b}}` agree (the
     *   dot names the row, the remainder is an RFC 6901 pointer) — mirroring
     *   how a schema'd stage's own text row already carries its raw JSON.
     *
     * `first`/`all`/`results` refuse loudly when EVERY named source is
     * missing: text conjured from nothing would let a `when:`-skipped source
     * ({@see skippedWhenRow()} — a skipped stage writes NOTHING to the
     * context) pass as if it had contributed. The refusal is raw English and
     * lands as a stage failure through the dispatch catch — parse cannot know
     * skips, only run can.
     *
     * @param array<array-key, mixed> $context
     * @param array{from: list<string>, as: string}|null $spec
     *
     * @return array<array-key, mixed>
     */
    private static function resolveStageInput(array $context, ?array $spec): array
    {
        if ($spec === null) {
            return $context;
        }

        /** @var array<string, string> $rows */
        $rows = [];
        /** @var array<string, mixed> $members */
        $members = [];
        $typedRows = $context[self::TYPED_RESULTS_CONTEXT_KEY] ?? null;
        if (!is_array($typedRows)) {
            $typedRows = [];
        }

        foreach ($spec['from'] as $name) {
            $row = $context[$name . '.output'] ?? null;
            if (!is_string($row)) {
                $textMap = $context[self::RESULTS_CONTEXT_KEY] ?? null;
                $row = is_array($textMap) ? ($textMap[$name] ?? null) : null;
            }

            if (!is_string($row)) {
                continue;
            }

            $rows[$name] = $row;

            if ($spec['as'] === 'results') {
                $typed = $typedRows[$name] ?? null;
                $decoded = is_string($typed) ? json_decode($typed, true) : null;
                $members[$name] = is_array($decoded) ? $decoded : $row;
            }
        }

        if ($rows === []) {
            if ($spec['as'] !== 'file-list') {
                throw new \RuntimeException(
                    'input join: every source is missing (' . implode(', ', $spec['from'])
                    . ') - joins read prior stages; a stage skipped by when: records nothing'
                );
            }

            $text = '[input: no sources joined]';
        } else {
            $text = match ($spec['as']) {
                'file-list' => implode("\n", $rows),
                'first' => $rows[array_key_first($rows)],
                'all' => implode("\n", array_map(
                    static fn (string $name, string $row): string => "=== {$name} ===\n{$row}",
                    array_keys($rows),
                    $rows
                )),
                default => (string) json_encode($members, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
        }

        $context['input'] = $text;

        $textMap = $context[self::RESULTS_CONTEXT_KEY] ?? null;
        if (!is_array($textMap)) {
            $textMap = [];
        }

        $textMap['input'] = $text;
        $context[self::RESULTS_CONTEXT_KEY] = $textMap;

        if ($spec['as'] === 'results') {
            $typedRows['input'] = $text;
            $context[self::TYPED_RESULTS_CONTEXT_KEY] = $typedRows;
        }

        return $context;
    }

    /**
     * C2: a `when:` clause that answers unsatisfied settles the stage as
     * Skipped WITHOUT dispatch — the row is a recorded fact, neither success
     * nor failure. Nothing is billed, no output key is written (downstream
     * reads see the stage as absent, which is the truth), no input hash is
     * recorded (a skip re-decides nothing), and the clause gate stands ahead
     * of the cache door: an unrun stage has nothing to replay.
     *
     * Extracted verbatim from {@see runGuardedFromWorkflow()} by C4, whose
     * loop do-head needs the window bytes; behavior is the moved code's.
     *
     * @param array<string, mixed> $stage
     * @param array<string, mixed> $context
     */
    private function skippedWhenRow(array $stage, array $context, \DateTimeImmutable $stageStartedAt): ?StageResult
    {
        $when = $stage['when'] ?? null;
        if (is_array($when) && !StageCondition::evaluate($when, $context)) {
            return new StageResult(
                stageName: (string) ($stage['name'] ?? ''),
                status: WorkflowStatus::Skipped,
                startedAt: $stageStartedAt,
                completedAt: $stageStartedAt,
            );
        }

        return null;
    }

    /**
     * The whole-workflow pre-flight, extracted from
     * {@see runGuardedFromWorkflow()}'s head: a stage that declares a refused
     * tool must not cost four stages of real agent work first. See
     * firstDeclarationRefusal() for why the per-stage checks stay as well.
     * firstRejectMisuse() rides the same channel but is gate-independent:
     * a mis-anchored reject() is a placement bug, not a permission call, and
     * it must refuse under every configuration (firstDeclarationRefusal
     * abstains when there is no permission gate).
     *
     * Reported as that stage's failure rather than thrown, so the shape a
     * caller sees is the same one a refusal discovered inside the stage
     * produces -- a Failed WorkflowResult carrying the message on the
     * stage it belongs to. Nothing ran, so there are no agents on it and
     * no tokens or cost.
     *
     * @param list<StageResult> $stageResults
     * @param array<string, mixed> $context
     */
    private function preflightRefusal(
        Workflow $workflow,
        string $resolvedWorkflowId,
        array $stageResults,
        array $context,
        int $totalTokens,
        float $totalCost,
        \DateTimeImmutable $startedAt,
    ): ?WorkflowResult {
        $refusal = $this->firstDeclarationRefusal($workflow) ?? $this->firstRejectMisuse($workflow);

        if ($refusal === null) {
            return null;
        }

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

    /**
     * C11 include-compose: run a named workflow as this stage's body and
     * settle the WHOLE inner run into exactly one outer StageResult.
     *
     * The door order is the refusal order: cycle first (the same name on the
     * chain being walked), then depth (one node past {@see MAX_USES_DEPTH}
     * would start the inner run), then the load itself. Every refusal is a
     * raw-English throw — the stage loop's existing catch turns it into this
     * stage's Failed row carrying the message verbatim, which is the shape
     * the linter aims to pre-empt at lint time. A chain refusal names the
     * full attempted path (`a -> b -> c -> d`) because a two-name message
     * cannot locate the hop a long plan went wrong at.
     *
     * The inner run is NESTED on this fiber deliberately: null pauseId and
     * loadPath keep its live frame addressable only by its generated id, so
     * a user pause() can never select it and it writes no pause file of its
     * own; interruptible false means one signal-handler frame, the outer's.
     * Cancellation is shared state, not a parameter — $this->runCancellation
     * stops inner and outer alike (see its property note).
     *
     * THE v1 LIMIT, stated here so the fold's honesty is local: inner stage
     * results enter the OUTER run's live state only when this method returns,
     * so a pause landing mid-inner records the outer snapshot WITHOUT any of
     * the inner's finished stages, and resume replays the WHOLE inner stage.
     *
     * @param array<string, mixed> $stage        The uses stage ('uses' string, 'with' string map).
     * @param array<string, mixed> $context      The OUTER run context — `with:` values interpolate here.
     * @param Workflow             $workflow     The workflow this stage belongs to (chain member).
     * @return StageResult One row: outer stage name, inner agents for the
     *         totals, one `name: status` line per inner stage as output.
     */
    private function executeIncludedWorkflow(
        array $stage,
        array $context,
        Workflow $workflow,
        \DateTimeImmutable $stageStartedAt,
    ): StageResult {
        /** @var string $includedName */
        $includedName = $stage['uses'];
        $chain = [...$this->usesChain, $workflow->name];

        if (in_array($includedName, $chain, true)) {
            throw new \RuntimeException(sprintf(
                'Workflow "%s" is already in this run\'s uses chain (%s); includes must not cycle.',
                $includedName,
                implode(' -> ', [...$chain, $includedName]),
            ));
        }

        if (count($chain) + 1 > self::MAX_USES_DEPTH) {
            throw new \RuntimeException(sprintf(
                'Include chain too deep: %s (maximum depth is %d).',
                implode(' -> ', [...$chain, $includedName]),
                self::MAX_USES_DEPTH,
            ));
        }

        $inner = $this->registry->load($includedName);

        // with: is the inner run's START context, interpolated in the OUTER
        // scope — {{b.output}} in a `with:` value reads the outer's settled
        // stages. Nothing flows back out: inner stage names never enter the
        // outer context (v1; this stage's own capture carries the folded
        // "name: status" rows only, never the inner agents' answer text).
        $innerContext = [];

        foreach (($stage['with'] ?? []) as $key => $value) {
            $innerContext[$key] = $this->interpolateContext((string) $value, $context);
        }

        $previousChain = $this->usesChain;
        $this->usesChain = $chain;
        $innerPauseRequested = false;

        try {
            $innerResult = $this->runFromWorkflow(
                $inner,
                $innerContext,
                0,
                null,
                null,
                null,
                [],
                $innerPauseRequested,
                false,
            );
        } finally {
            $this->usesChain = $previousChain;
        }

        // Fold: every agent the inner run spent rides this one row, because
        // sumTokens()/sumCost() read the row's agents — dropping them would
        // silently under-bill the outer totals.
        $agents = [];
        $lines = [];
        $failedRow = null;

        foreach ($innerResult->stageResults as $innerStage) {
            foreach ($innerStage->agents as $agent) {
                $agents[] = $agent;
            }

            $lines[] = $innerStage->stageName . ': ' . $innerStage->status->value;

            if ($failedRow === null && $innerStage->isFailure()) {
                $failedRow = $innerStage;
            }
        }

        $error = match ($innerResult->status) {
            WorkflowStatus::Completed => null,
            WorkflowStatus::Cancelled => sprintf('included workflow "%s" was cancelled', $includedName),
            // A nested frame the user cannot name cannot be paused; if one
            // ever comes back Paused, say so rather than folding it silently
            // into a generic failure.
            WorkflowStatus::Paused => sprintf('included workflow "%s" paused, which an inner run cannot do', $includedName),
            default => $failedRow !== null && $failedRow->error !== null
                ? sprintf('included workflow "%s" failed: %s', $includedName, $failedRow->error)
                : sprintf('included workflow "%s" failed', $includedName),
        };

        return new StageResult(
            stageName: $stage['name'] ?? 'unknown',
            status: $error === null ? WorkflowStatus::Completed : WorkflowStatus::Failed,
            output: implode("\n", $lines),
            error: $error,
            agents: $agents,
            startedAt: $stageStartedAt,
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * C8: materialise a "from:" glob against the run's environment root.
     *
     * The glob is matched with the same {@see PathGlob} engine the permission
     * globs use, over paths RELATIVE to the environment root ("/" separators)
     * — a pattern like src/*.{php,js} fans out the session project's files,
     * never the directory the workflow YAML was loaded from. Stars keep the
     * matcher's fnmatch answer, which includes crossing "/" for a lone `*`:
     * `*.txt` sweeps the whole tree, so a wave that means one directory
     * spells the prefix (`src/*.txt`). Every match is a regular file, so an
     * empty result means nothing matched, not "a directory did".
     *
     * The list is sorted for determinism: the pause record names files in
     * this exact order, so a replayed stage builds the same fan-out. Patterns
     * that try to leave the root (absolute, or carrying a ".." segment) are
     * refused here rather than expanded into a surprising walk; a run with no
     * environment root, or a root that no longer exists, is refused too —
     * there is nothing to expand against. Symlinks are never followed: a link
     * is neither descended nor collected, and every match is re-checked
     * against the root's realpath before it fans out, so a name that would
     * resolve outside the root fails the expansion loudly.
     * Refusals throw: the engine loop's catch turns them into the loudly-
     * failed stage, never a silently-empty fan-out.
     *
     * @return list<string> relative paths of every matching file
     */
    private function expandFromGlob(string $pattern): array
    {
        $root = $this->environmentRoot;
        if ($root === null || $root === '') {
            throw new WorkflowLoadException('a from: stage needs an environment root to expand');
        }

        if ($pattern === '' || str_starts_with($pattern, '/')) {
            throw new WorkflowLoadException(
                "a from: glob must be a relative pattern, got \"{$pattern}\""
            );
        }

        if (preg_match('~(?:^|/)\.\.(?:/|$)~', $pattern) === 1) {
            throw new WorkflowLoadException(
                "a from: glob must not traverse above the environment root, got \"{$pattern}\""
            );
        }

        $compiled = PathGlob::compile($pattern);
        $root = rtrim($root, '/');
        $realRoot = realpath($root);

        if ($realRoot === false) {
            throw new WorkflowLoadException(
                "a from: stage needs an environment root that exists, got \"{$root}\""
            );
        }

        $matches = [];

        // Iterative walk, newest directory first off the stack — order does
        // not matter here because the result is sorted before it is returned.
        // An unreadable directory is skipped: the run either matches what it
        // can read or fails the "expanded no files" door loudly.
        $stack = [''];

        while ($stack !== []) {
            $dir = array_pop($stack);
            $entries = @scandir($root . ($dir === '' ? '' : '/' . $dir));

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $relative = $dir === '' ? $entry : $dir . '/' . $entry;

                // A link is never followed: a directory symlink would let the
                // walk leave the root laterally (or storm it on a cycle), and
                // a file link is not the file its name claims to be.
                if (is_link($root . '/' . $relative)) {
                    continue;
                }

                if (is_dir($root . '/' . $relative)) {
                    $stack[] = $relative;
                    continue;
                }

                if (is_file($root . '/' . $relative) && PathGlob::matchCompiled($compiled, $relative) === true) {
                    $resolved = realpath($root . '/' . $relative);

                    if ($resolved === false || !str_starts_with($resolved, $realRoot . DIRECTORY_SEPARATOR)) {
                        throw new WorkflowLoadException(
                            "a from: expansion reached a path outside the environment root, got \"{$relative}\""
                        );
                    }

                    $matches[] = $relative;
                }
            }
        }

        sort($matches);

        return $matches;
    }

    /**
     * C13 opt-in carry: whether this stage's pause record already holds the
     * answer the declaration asks for (docs/WORKFLOWS.md `cache:` section).
     *
     * Three conditions, all required: the stage opted in (absent `cache:` is
     * the default OFF); the record carries a SUCCESSFUL row for the stage name
     * (the successful-prefix law in priorProgress keeps post-failure rows out);
     * and the identity hash recorded when that row settled still matches this
     * declaration's hash — same recipe, same single site (pre-interpolation
     * type/prompt/model/schema; the capture/cache keys are NOT material, so
     * flipping the opt-in alone never invalidates). hash_equals, and an absent
     * hash is never equal: forged, stale or missing means cache miss and a
     * plain re-dispatch. The caller carries the row WITHOUT dispatch, so no
     * spend is invented; whether to re-add the row's recorded spend is the
     * totalsCountRecordedRows decision at the call site.
     *
     * @param array<string, mixed> $stage
     * @param array<string, mixed> $prior
     */
    private function cachedStageResult(array $stage, string $stageType, array $prior): ?StageResult
    {
        if (($stage['cache'] ?? false) !== true) {
            return null;
        }

        $name = (string) ($stage['name'] ?? '');
        $cached = $prior['recordedResults'][$name] ?? null;
        $recordedHash = (string) ($prior['stageInputHashes'][$name] ?? '');
        if (!$cached instanceof StageResult || !$cached->isSuccess() || $recordedHash === '') {
            return null;
        }

        return hash_equals($recordedHash, self::stageInputHash($stage, $stageType)) ? $cached : null;
    }

    /**
     * The O3 host-resource lock store. The directory lives BESIDE the
     * registry's workflows directory — production `~/.candy-code/locks`,
     * wherever a deliberately-trusted registry points, and the suite's temp
     * dir in tests. Never a per-lib path: these locks serialize the HOST,
     * across registries, engines and processes.
     */
    private function stageResourceLocks(): StageResourceLocks
    {
        return $this->stageLocks ??= new StageResourceLocks(
            \dirname($this->registry->workflowsPath()) . '/locks',
        );
    }

    /**
     * Every WorkflowTask whose dispatch belongs to this stage: the task list
     * for stage/parallel, both slots of a verification stage, and the nested
     * task lists of a pipeline. Entries that are not tasks are skipped — they
     * fail their own stage's validation a moment later.
     *
     * @param array<string, mixed> $stage
     * @return list<WorkflowTask>
     */
    private function collectStageTasks(array $stage, string $stageType): array
    {
        $candidates = [];

        if ($stageType === 'verification') {
            $candidates = [$stage['task'] ?? null, $stage['verifier'] ?? null];
        } elseif ($stageType === 'pipeline') {
            foreach ($stage['stages'] ?? [] as $nested) {
                if (is_array($nested)) {
                    foreach ($nested['tasks'] ?? [] as $task) {
                        $candidates[] = $task;
                    }
                }
            }
        } elseif (is_array($stage['tasks'] ?? null)) {
            $candidates = $stage['tasks'];
        }

        $tasks = [];
        foreach ($candidates as $task) {
            if ($task instanceof WorkflowTask) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    /**
     * Acquire every distinct resource a stage declares. Each resource waits
     * the LONGEST bound its declaring tasks asked for (absent bounds fall to
     * {@see DEFAULT_STAGE_LOCK_WAIT_SECONDS}), and names are taken in sorted
     * order so concurrent engines converge instead of deadlocking. A timeout
     * releases what was already held and rethrows — the engine loop's catch
     * turns it into the loudly-failed stage. A stage that declares nothing
     * opens nothing: the pre-O3 path, byte-identical.
     *
     * @param array<string, mixed> $stage
     * @return list<resource>
     */
    private function acquireStageResources(array $stage, string $stageType): array
    {
        $waits = [];
        foreach ($this->collectStageTasks($stage, $stageType) as $task) {
            if ($task->resource === null) {
                continue;
            }

            $asked = $task->lockWaitSeconds ?? self::DEFAULT_STAGE_LOCK_WAIT_SECONDS;
            $waits[$task->resource] = max($asked, $waits[$task->resource] ?? 0);
        }

        if ($waits === []) {
            return [];
        }

        ksort($waits);

        $locks = $this->stageResourceLocks();
        $held = [];
        foreach ($waits as $resource => $waitSeconds) {
            try {
                $held[] = $locks->acquire($resource, (float) $waitSeconds);
            } catch (\Throwable $e) {
                $this->releaseStageResources($held);

                throw $e;
            }
        }

        return $held;
    }

    /**
     * Give back every lock a stage took. Success, failure, throw and the
     * cooperative-stop return all pass through here; a dead process's locks
     * go with its descriptors, which is what makes a crashed run self-clear.
     *
     * @param list<resource> $held
     */
    private function releaseStageResources(array $held): void
    {
        if ($held === []) {
            return;
        }

        $locks = $this->stageResourceLocks();
        foreach ($held as $handle) {
            $locks->release($handle);
        }
    }

    /**
     * S3: write a captured stage's FULL output to the run-scoped capture
     * file, and return the text downstream stages see in its place: the
     * file's path, up to {@see CAPTURE_PREVIEW_BYTES} bytes of the output,
     * and — when the output was longer — a marked truncation naming the
     * file's size. The write publishes by rename, the same discipline as
     * the pause file: a reader never sees half a capture.
     *
     * A failed capture write degrades LOUDLY — the returned context carries
     * the full dump plus the failure, because the stage's work must survive
     * the loss of the file the author asked for. 0600: the capture is the
     * agent's raw work product, the same trust class as the pause record.
     */
    public function captureStageOutput(string $interruptId, int $stageIndex, StageResult $stageResult): string
    {
        $output = $stageResult->output ?? '';
        $file = self::stageCapturePath(
            \dirname($this->getPauseFilePath($interruptId)),
            $interruptId,
            $stageIndex,
            $stageResult->stageName,
        );

        try {
            PrivateDir::ensure(\dirname($file), 'workflow stage captures');

            $partial = $file . '.partial';
            if (@file_put_contents($partial, $output) === false) {
                throw new \RuntimeException("could not write the capture for stage '{$stageResult->stageName}'");
            }

            @chmod($partial, 0600);

            if (!rename($partial, $file)) {
                @unlink($partial);

                throw new \RuntimeException("could not publish the capture file for stage '{$stageResult->stageName}'");
            }
        } catch (\Throwable $e) {
            return $output . "\n[capture FAILED: {$e->getMessage()} — full output inline above instead]";
        }

        $preview = self::previewOf($output);
        if ($preview === $output) {
            return "[output captured to {$file}]\n{$output}";
        }

        return "[output captured to {$file}]\n{$preview}\n"
            . '[…prompt preview only: the full ' . strlen($output) . ' bytes are in the file]';
    }

    /**
     * Where a captured stage's output lands: a run-scoped directory beside
     * the pause record — `<pause-dir>/<id>.stages/<index>-<stage-slug>.txt`.
     * Public so a reader (a test, a follow-up agent told the path) can
     * resolve the layout from the run's own facts, not from a second copy
     * of the naming rule. Capture files OUTLIVE the run's clean terminal:
     * withdrawal removes the pause record, not the work products — see
     * WORKFLOWS.md.
     */
    public static function stageCapturePath(string $pauseDir, string $interruptId, int $stageIndex, string $stageName): string
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $stageName) ?? $stageName;

        return $pauseDir . '/' . $interruptId . '.stages/' . $stageIndex . '-' . trim($slug, '-.') . '.txt';
    }

    /**
     * The bounded preview: a byte prefix of at most the limit, cut back to
     * the last whole UTF-8 character — the slice of a valid string can only
     * break at its tail.
     */
    private static function previewOf(string $output): string
    {
        if (strlen($output) <= self::CAPTURE_PREVIEW_BYTES) {
            return $output;
        }

        $bytes = substr($output, 0, self::CAPTURE_PREVIEW_BYTES);
        while ($bytes !== '' && preg_match('//u', $bytes) !== 1) {
            $bytes = substr($bytes, 0, -1);
        }

        return $bytes;
    }

    /**
     * C13 substrate: the stable identity of what a stage WAS ASKED to run —
     * its pre-interpolation prompt templates plus each task's model ask and
     * schema identity, hashed sha256 over a canonical array. Every
     * successful settle records it on the pause record
     * (`stageInputHashes`), and the opt-in `cache: true` door
     * ({@see cachedStageResult()}) compares it on resume: a recorded
     * success whose hash still matches the stage's declaration is carried
     * without re-dispatch. Stages without `cache: true` only record it.
     *
     * The recipe is the point: consumers pin its shape, so it lives here,
     * once, and widening it (tools, agent type, `from:`/`loop:` specs, …)
     * is a deliberate single-site decision, not an accident — it does not
     * cover them today, so `cache: true` on such a stage is the author's
     * stability assertion about its inputs.
     *
     * @param array<string, mixed> $stage
     */
    private static function stageInputHash(array $stage, string $stageType): string
    {
        $material = ['type' => $stageType, 'tasks' => []];

        // C11: an include-compose stage's identity is its SUBJECT NAME and
        // its with: templates (pre-interpolation, same policy as task
        // prompts). The inner workflow's own content is deliberately NOT in
        // the material — `cache: true` beside `uses:` is the author's
        // stability assertion about the include, and inner drift on disk is
        // invisible to this hash until the declaration itself changes.
        if (array_key_exists('uses', $stage)) {
            $material['uses'] = $stage['uses'];
            $material['with'] = $stage['with'] ?? [];
        }

        $push = static function (mixed $task) use (&$material): void {
            if ($task instanceof WorkflowTask) {
                $material['tasks'][] = [
                    'prompt' => $task->prompt,
                    'model' => $task->model,
                    'schema' => $task->outputSchema,
                ];
            }
        };

        if ($stageType === 'verification') {
            $push($stage['task'] ?? null);
            $push($stage['verifier'] ?? null);
        } elseif ($stageType === 'pipeline') {
            foreach ($stage['stages'] ?? [] as $nested) {
                if (is_array($nested)) {
                    foreach ($nested['tasks'] ?? [] as $task) {
                        $push($task);
                    }
                }
            }
        } else {
            foreach ($stage['tasks'] ?? [] as $task) {
                $push($task);
            }
        }

        return hash('sha256', json_encode($material, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * C9L: the join DECLARATIONS of one stage — one row per task that carries
     * an `input:` spec, in task declaration order, each row the exact shape
     * {@see resolveStageInput()} consumes ({from: list<string>, as: string}).
     * A join-free stage yields the empty list, so it earns no ledger row.
     *
     * Only the declarations are ever stored: the JOIN PAYLOADS stay derived
     * at run time (the byte-stability law of resolveStageInput's doc-block —
     * pause files still carry no `input` row, and this ledger changes that
     * claim not at all: it says WHICH joins were decided, never WHAT they
     * joined to).
     *
     * @param array<string, mixed> $stage
     * @return list<array{from: list<string>, as: string}>
     */
    private function stageJoinDeclarations(array $stage, string $stageType): array
    {
        $rows = [];
        foreach ($this->collectStageTasks($stage, $stageType) as $task) {
            if ($task->input === null) {
                continue;
            }
            $rows[] = [
                'from' => array_values($task->input['from']),
                'as' => $task->input['as'],
            ];
        }

        return $rows;
    }

    /**
     * C9L: refuse a resume whose workflow file was EDITED mid-pause in a way
     * that changes a recorded join stage's `input:` declaration.
     *
     * The hazard (C9-FIX, of record): the C13 replay hash covers task/model/
     * schema material but NOT the join list, so a stage whose settled bytes
     * were joined from [a, b] could resume against an edited declaration
     * joining [a, c] — silently re-deciding a decision already paid for.
     * The pause record therefore carries an `inputJoins` ledger of every
     * settled join stage's declarations, and this guard compares the CURRENT
     * definition against it for every successful recorded stage.
     *
     * Rulings (all pinned by WorkflowPauseResumeTest):
     * - ABSENT ledger = pre-C9L record or join-free run: PROCEED. Absence is
     *   "no claim made", not a broken one; refusing old pauses would break
     *   live users' runs for zero safety gain (the hazard predates the ledger;
     *   protection begins at the first write).
     * - REORDER refuses: from-order is byte-significant in all four shapes
     *   (file-list joins in list order, `first` takes the first present,
     *   `all` banners in declaration order, `results` keys in list order).
     * - A removed join, an added join, an `as` spelling change, and a
     *   recorded stage VANISHING from the definition all refuse (the current
     *   declaration is the empty list, which mismatches a join-bearing row).
     * - Ledger rows for stages that are NOT recorded (pruned from the file)
     *   are tolerated: only recorded decisions are defended.
     * - The refusal is the resume-level WorkflowNotRunningException — loud,
     *   raw English, before any stage re-decides — mirroring the C8
     *   cannot-replay law for dynamic waves. A hand-edited ledger row cannot
     *   forge consent: any attacker who can rewrite the pause file can as
     *   well delete the key, which is the documented legacy pass; the guard
     *   defends YAML edits, not a compromised record.
     *
     * @param array{inputJoins: array<string, mixed>, recordedResults: array<string, StageResult>} $prior
     */
    private function refuseEditedInputJoins(string $workflowId, Workflow $workflow, array $prior): void
    {
        $ledger = $prior['inputJoins'];
        if ($ledger === []) {
            return;
        }

        $current = [];
        foreach ($workflow->stages as $stage) {
            $name = (string) ($stage['name'] ?? 'unknown');
            $rows = $this->stageJoinDeclarations($stage, (string) ($stage['type'] ?? ''));
            if ($rows !== []) {
                $current[$name] = $rows;
            }
        }

        foreach ($prior['recordedResults'] as $name => $row) {
            if (!$row->isSuccess()) {
                continue;
            }
            $expected = self::canonicalJoinLedgerRow($ledger[$name] ?? null);
            $declared = $current[$name] ?? [];
            if ($expected === [] && $declared === []) {
                continue;
            }
            if ($expected !== $declared) {
                throw new WorkflowNotRunningException(
                    "Workflow '{$workflowId}' cannot be resumed: stage '{$name}' has a recorded"
                    . " input join whose declaration no longer matches the pause record's ledger"
                    . ' — refusing to re-decide a recorded join stage against an edited workflow'
                );
            }
        }
    }

    /**
     * C9L: re-shape one ledger row (from the JSON-decoded file) into exactly
     * what stageJoinDeclarations() produces, so a strict !== comparison is
     * fair. Anything malformed canonicalises to the empty list — which
     * mismatches any join-bearing current declaration (fail-closed toward
     * refusal) and matches a stage that has no joins left (nothing to
     * re-decide).
     *
     * @return list<array{from: list<string>, as: string}>
     */
    private static function canonicalJoinLedgerRow(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $rows = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !is_array($entry['from'] ?? null) || !is_string($entry['as'] ?? null)) {
                return [];
            }
            $from = [];
            foreach ($entry['from'] as $source) {
                if (!is_string($source)) {
                    return [];
                }
                $from[] = $source;
            }
            $rows[] = ['from' => $from, 'as' => $entry['as']];
        }

        return $rows;
    }

    /**
     * Execute a 'stage' type stage and return its StageResult.
     *
     * Builds a SubAgent from each of the stage's tasks and dispatches it
     * through {@see dispatchOne()}.
     *
     * A stage with several tasks (roadmap 4.10-1) runs them ONE AFTER ANOTHER,
     * in declaration order: each task after the first sees the previous one's
     * output as `{{prevResult}}` and every earlier task's as
     * `{{<name>.results}}`, all tasks share the stage's one wall-clock budget
     * (each gets what the ones before it left, as a pipeline's steps do), and
     * the first task that does not complete fails the stage without starting
     * the rest. Every task's declared tools are permission-checked before the
     * first one is dispatched. The stage's output is the tasks' outputs, one
     * per line, and it carries every task's agent so tokens and cost add up.
     * (This used to run `$tasks[0]` and silently drop the rest.)
     *
     * The agent's result is named by its task name, falling back to the STAGE
     * name — never to the agent type: two stages on the default `coder` agent
     * used to overwrite each other's `{{coder.results}}` (AUDIT WF-3). In a
     * multi-task stage an unnamed task falls back to `<stage>_<n>` (1-based),
     * as a parallel stage's agents do, so its tasks cannot overwrite each
     * other either.
     *
     * @param array $stage        Stage array from Workflow::$stages.
     * @param array $context      Current workflow context for interpolation.
     * @param list<array{key: string, result: AgentResult, schema: bool}> $dispatched Out: each agent this stage ran, as it settles.
     * @param int   $stageTimeout Workflow::$timeout — this stage's wall-clock budget, in seconds.
     * @return StageResult
     */
    private function executeStage(array $stage, array $context, array &$dispatched, int $stageTimeout): StageResult
    {
        $stageName = $stage['name'] ?? 'unknown';
        $stageStartedAt = new \DateTimeImmutable();
        $stageClock = hrtime(true);

        $tasks = array_values($stage['tasks'] ?? []);
        if (empty($tasks)) {
            return new StageResult(
                stageName: $stageName,
                status: WorkflowStatus::Failed,
                error: "Stage '{$stageName}' has no tasks",
                startedAt: $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }

        $multi = count($tasks) > 1;
        $where = static fn (int $i): string => $multi
            ? "Stage '{$stageName}' task #" . ($i + 1)
            : "Stage '{$stageName}'";

        // Every task's declaration up front, for executePipelineStage()'s
        // reason: a refusal is knowable from the definition alone, so finding
        // it at task 3 would mean two tasks' worth of real work done first.
        foreach ($tasks as $i => $task) {
            if (!$task instanceof WorkflowTask) {
                return new StageResult(
                    stageName: $stageName,
                    status: WorkflowStatus::Failed,
                    error: $where($i) . ' is not a WorkflowTask',
                    startedAt: $stageStartedAt,
                    completedAt: new \DateTimeImmutable(),
                );
            }
            $this->refuseDeniedTools($task, $where($i));
        }

        $taskContext = $context;
        $outputs = [];
        $agents = [];
        $failed = null;

        /** @var WorkflowTask $task */
        foreach ($tasks as $i => $task) {
            if ($i > 0) {
                $taskContext['prevResult'] = $outputs[$i - 1];
            }

            // Interpolate prompt with context
            $interpolatedPrompt = $this->interpolateContext($task->prompt, self::resolveStageInput($taskContext, $task->input));

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
                id: $stageName . ($multi ? '-' . ($i + 1) : '') . '-' . uniqid(getmypid() . '_', true),
                agent: $agent,
                task: $interpolatedPrompt,
                timeout: $task->timeout ?? $stageTimeout,
                maxRetries: $task->retries ?? 0,
                stallTimeout: $task->stallTimeout ?? 0,
                stallRestarts: $task->restarts,
                isolation: $task->isolation ?? \SugarCraft\Code\Agents\Isolation::None,
                outputSchema: $task->outputSchema,
                // S2: the per-stage model ask; null keeps today's session-default dial.
                model: $task->model,
                permissionGate: $this->permissionGate,
            );

            // Build CompleteRequest
            $request = new CompleteRequest(
                model: $agent->model,
                messages: [
                    ['role' => 'user', 'content' => $interpolatedPrompt],
                ],
                tools: $this->resolveRequestTools($task->tools),
                systemPrompt: $this->renderStagePrompt($agent),
            );

            // Execute via pool, on what is left of the stage's budget
            $agentResult = $this->dispatchOne($subAgent, $request, $this->stagePool($stageTimeout, $stageClock));
            $record = [
                'key' => self::resultKey($task->name, $multi ? $stageName . '_' . ($i + 1) : $stageName),
                'result' => $agentResult,
                'schema' => $task->outputSchema !== null,
            ];
            $dispatched[] = $record;
            // M-11 (cc-fix-1111): fold this result's self-report before the
            // next render on this path pays for it.
            $this->foldStageWriteSignal($agentResult);

            if (!$multi) {
                return $this->buildStageResult($stageName, $agentResult, $stageStartedAt);
            }

            $taskContext = self::withResults($taskContext, [$record]);
            $outputs[] = $agentResult->output ?? '';
            $agents[] = $agentResult;

            // Fail fast: a task that did not complete stops the stage.
            if ($agentResult->status !== AgentStatus::Completed) {
                $failed = $agentResult;
                break;
            }
        }

        return new StageResult(
            stageName: $stageName,
            status: $failed === null ? WorkflowStatus::Completed : WorkflowStatus::Failed,
            output: implode("\n", $outputs),
            error: $failed === null
                ? null
                : ($failed->error?->getMessage() ?? $where(count($agents) - 1) . ' did not complete'),
            agents: $agents,
            startedAt: $agents[0]->startedAt ?? $stageStartedAt,
            completedAt: $agents[count($agents) - 1]->completedAt ?? new \DateTimeImmutable(),
        );
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
     * @param list<array{key: string, result: AgentResult, schema: bool}> $dispatched Out: each step's agent, as it settles.
     * @param ?int  $maxConcurrent The workflow's concurrency width, carried for
     *                             call-site symmetry: a pipeline's steps run one
     *                             at a time by definition, so nothing here reads
     *                             it (the parallel executor resolves the
     *                             width through {@see concurrencyFor()}).
     * @param int   $stageTimeout  Workflow::$timeout — the budget for the whole
     *                             pipeline: each step gets what the steps
     *                             before it left, not a fresh allowance.
     * @return StageResult
     */
    private function executePipelineStage(array $stage, array $context, array &$dispatched, ?int $maxConcurrent, int $stageTimeout): StageResult
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
            $interpolatedPrompt = $this->interpolateContext($task->prompt, self::resolveStageInput($pipelineContext, $task->input));

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
                stallTimeout: $task->stallTimeout ?? 0,
                stallRestarts: $task->restarts,
                isolation: $task->isolation ?? \SugarCraft\Code\Agents\Isolation::None,
                outputSchema: $task->outputSchema,
                // S2: the per-stage model ask; null keeps today's session-default dial.
                model: $task->model,
                permissionGate: $this->permissionGate,
            );

            $request = new CompleteRequest(
                model: $agent->model,
                messages: [
                    ['role' => 'user', 'content' => $interpolatedPrompt],
                ],
                tools: $this->resolveRequestTools($task->tools),
                systemPrompt: $this->renderStagePrompt($agent),
            );

            $agentResult = $this->dispatchOne($subAgent, $request, $this->stagePool($stageTimeout, $stageClock));
            $record = [
                'key' => self::resultKey($task->name, $nestedStage['name'] ?? $stageName . '_' . ($stepIndex + 1)),
                'result' => $agentResult,
                'schema' => $task->outputSchema !== null,
            ];
            $dispatched[] = $record;
            // M-11 (cc-fix-1111): nested pipeline steps repeat the render —
            // fold first, then let the next step's prompt see the report.
            $this->foldStageWriteSignal($agentResult);
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
     * @param list<array{key: string, result: AgentResult, schema: bool}> $dispatched Out: the task's and the verifier's agents, as each settles.
     * @param int   $stageTimeout Workflow::$timeout — one budget shared by the
     *                            task and its verifier.
     * @return StageResult
     */
    private function executeVerificationStage(array $stage, array $context, array &$dispatched, int $stageTimeout, ?int $stageClock = null, ?array &$votesOut = null): StageResult
    {
        $stageName = $stage['name'] ?? 'unknown';
        $stageStartedAt = new \DateTimeImmutable();
        // P1-2: a map step shares its map stage's clock (one budget for the
        // whole stage); a top-level verification stage starts its own.
        $stageClock ??= hrtime(true);

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

        // Belt past the whole-workflow pre-flight: the task half produces work,
        // not a verdict, so a reject() anchored there is a placement bug that
        // must never cost a dispatch.
        if ($task->reject !== null) {
            return new StageResult(
                stageName: $stageName,
                status: WorkflowStatus::Failed,
                error: self::rejectMisuseMessage("Verification stage '{$stageName}' task slot"),
                startedAt: $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }
        // P1-4: the same belt for a panel declared on the task slot.
        if ($task->declaresPanel()) {
            return new StageResult(
                stageName: $stageName,
                status: WorkflowStatus::Failed,
                error: self::panelMisuseMessage("Verification stage '{$stageName}' task slot"),
                startedAt: $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }

        // --- Run the task ---
        // C9: the join reads this task's own input: spec against the run context;
        // the helper returns a copy, so the persisted $context never carries it.
        $taskPrompt = $this->interpolateContext($task->prompt, self::resolveStageInput($context, $task->input));

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
            stallTimeout: $task->stallTimeout ?? 0,
            stallRestarts: $task->restarts,
            isolation: $task->isolation ?? \SugarCraft\Code\Agents\Isolation::None,
            outputSchema: $task->outputSchema,
            // S2: the per-stage model ask; null keeps today's session-default dial.
            model: $task->model,
            permissionGate: $this->permissionGate,
        );

        $taskRequest = new CompleteRequest(
            model: $taskAgent->model,
            messages: [['role' => 'user', 'content' => $taskPrompt]],
            tools: $this->resolveRequestTools($task->tools),
            systemPrompt: $this->renderStagePrompt($taskAgent),
        );

        $taskResult = $this->dispatchOne($taskSubAgent, $taskRequest, $this->stagePool($stageTimeout, $stageClock));
        $taskRecord = ['key' => self::resultKey($task->name, $stageName), 'result' => $taskResult, 'schema' => $task->outputSchema !== null];
        $dispatched[] = $taskRecord;
        // M-11 (cc-fix-1111): the verifier renders after this dispatch —
        // fold the task's report before that prompt is paid for.
        $this->foldStageWriteSignal($taskResult);

        // If task itself fails, the whole stage fails immediately
        if ($taskResult->status === AgentStatus::Failed || $taskResult->status === AgentStatus::TimedOut) {
            return $this->buildStageResult($stageName, $taskResult, $stageStartedAt);
        }

        // --- Run the verifier, injecting task output as {{prevResult}} ---
        $verifierContext = self::withResults($context, [$taskRecord]);
        $verifierContext['prevResult'] = $taskResult->output ?? '';

        // P1-4: a declared panel judges by quorum over N concurrent votes;
        // an undeclared verifier takes the single-verifier path below,
        // byte-identical to every pre-P1-4 stage.
        if ($verifier->declaresPanel()) {
            return $this->runVerifierPanel($stageName, $taskResult, $verifier, $verifierContext, $dispatched, $stageTimeout, $stageClock, $stageStartedAt, $votesOut);
        }

        $verifierPrompt = $this->interpolateContext($verifier->prompt, self::resolveStageInput($verifierContext, $verifier->input));

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

        $verifierRequest = new CompleteRequest(
            model: $verifierAgent->model,
            messages: [['role' => 'user', 'content' => $verifierPrompt]],
            tools: $this->resolveRequestTools($verifier->tools),
            systemPrompt: $this->renderStagePrompt($verifierAgent),
        );

        // --- Verdict-aware verifier loop ---
        //
        // A verifier's reject() pattern makes its own words actionable: after
        // a COMPLETED dispatch the output is scanned, and a MATCH rejects the
        // verdict — the stage then fails through the exact resume/retry
        // machinery a mechanical failure uses. The retry budget is shared:
        // every attempt counts against retries: N once, whether the pool
        // re-ran it mechanically or the engine re-dispatched after a rejected
        // verdict. A non-completed dispatch has no verdict to read (a dead
        // verifier speaks nothing), and with no reject pattern and no
        // ESCALATE: marker the scan reads CLEAN and the result feeds the
        // pre-verdict branches with a byte-identical outcome.
        $maxAttempts = 1 + ($verifier->retries ?? 0);
        $verifierAttempts = [];
        $attemptsUsed = 0;
        // First round carries the legacy `$verifier->retries ?? 0` verbatim;
        // later rounds may only spend what no attempt has spent yet, so
        // 1 + retries stays the ceiling across verdict and mechanical rounds.
        $maxRetriesForAttempt = $verifier->retries ?? 0;
        /** @var array{kind: string, reason: string}|null $verdict last scan */
        $verdict = null;

        do {
            // One construction site for every round: a fresh SubAgent id per
            // dispatch (attempt state is keyed by id), same task and gate.
            $verifierSubAgent = new SubAgent(
                id: $stageName . '-verifier-' . uniqid(getmypid() . '_', true),
                agent: $verifierAgent,
                task: $verifierPrompt,
                timeout: $verifier->timeout ?? $stageTimeout,
                maxRetries: $maxRetriesForAttempt,
                stallTimeout: $verifier->stallTimeout ?? 0,
                stallRestarts: $verifier->restarts,
                isolation: $verifier->isolation ?? \SugarCraft\Code\Agents\Isolation::None,
                outputSchema: $verifier->outputSchema,
                // S2: the per-stage model ask; null keeps today's session-default dial.
                model: $verifier->model,
                permissionGate: $this->permissionGate,
            );
            $maxRetriesForAttempt = max(0, $maxAttempts - 1 - $attemptsUsed);

            $verifierResult = $this->dispatchOne($verifierSubAgent, $verifierRequest, $this->stagePool($stageTimeout, $stageClock));
            $attemptsUsed += $verifierResult->attempts;
            $verifierAttempts[] = $verifierResult;
            // Same key per attempt: the last settles into the results context,
            // the documented law for pool retries carried to verdict rounds.
            $dispatched[] = ['key' => self::resultKey($verifier->name, $stageName . '_verifier'), 'result' => $verifierResult, 'schema' => $verifier->outputSchema !== null];
            // M-11 (cc-fix-1111): verdict-retry rounds re-dispatch on the
            // same rendered request today; fold anyway so a future round
            // that re-renders sees the honest report (idempotent per round).
            $this->foldStageWriteSignal($verifierResult);

            if ($verifierResult->status !== AgentStatus::Completed) {
                // A dead verifier speaks nothing: no verdict, no question —
                // the mechanical branches below handle it verbatim.
                $verdict = null;
                break;
            }

            $verdict = self::scanVerdict($verifier->reject, $verifierResult->output, $attemptsUsed, $verifier->rejectMinOccurrences);
        } while ($verdict['kind'] === self::VERDICT_REJECTED && $attemptsUsed < $maxAttempts);

        // A pattern that reaches the scan unable to compile (only possible
        // through a hand-built WorkflowTask — TaskBuilder::reject() refuses
        // such patterns at set time) fails CLOSED: a rule that never ran must
        // not read as a clean verdict.
        if ($verdict !== null && $verdict['kind'] === self::VERDICT_INVALID) {
            return new StageResult(
                stageName: $stageName,
                status: WorkflowStatus::Failed,
                output: $verifierResult->output,
                error: $verdict['reason'],
                agents: [$taskResult, ...$verifierAttempts],
                startedAt: $taskResult->startedAt ?? $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }

        // The verifier asked a human. Same failure channel, one deliberate
        // mechanical difference: the verdict loop exits after THIS dispatch
        // (the while-condition continues only on VERDICT_REJECTED), so no
        // retry budget is consumed and nothing re-dispatches. The question
        // text rides StageResult->output, which the run records into its
        // context before checking for failure — so an escalated question
        // survives verbatim into the pause record a human will read.
        if ($verdict !== null && $verdict['kind'] === self::VERDICT_ESCALATED) {
            return new StageResult(
                stageName: $stageName,
                status: WorkflowStatus::Failed,
                output: $verifierResult->output,
                error: $verdict['reason'],
                agents: [$taskResult, ...$verifierAttempts],
                startedAt: $taskResult->startedAt ?? $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }

        // Verifier mechanically failed or timed out — the existing path, with
        // the task's agent kept on the result (it ran, its spend is real) and
        // now every verifier attempt's spend kept too.
        if ($verifierResult->status === AgentStatus::Failed || $verifierResult->status === AgentStatus::TimedOut) {
            $failed = $this->buildStageResult($stageName, $verifierResult, $stageStartedAt);

            return new StageResult(
                stageName: $failed->stageName,
                status: $failed->status,
                output: $failed->output,
                error: $failed->error,
                agents: [$taskResult, ...$verifierAttempts],
                startedAt: $taskResult->startedAt ?? $stageStartedAt,
                completedAt: $failed->completedAt,
            );
        }

        // The last scan rejected the verdict and no attempt budget is left —
        // a failure like any other: the run stops, /workflow resume re-runs
        // this stage from scratch.
        if ($verdict !== null && $verdict['kind'] === self::VERDICT_REJECTED) {
            return new StageResult(
                stageName: $stageName,
                status: WorkflowStatus::Failed,
                output: $verifierResult->output,
                error: $verdict['reason'],
                agents: [$taskResult, ...$verifierAttempts],
                startedAt: $taskResult->startedAt ?? $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );
        }

        // Both succeeded — return combined output
        $combinedOutput = ($taskResult->output ?? '') . "\n" . ($verifierResult->output ?? '');
        $allAgents = [$taskResult, ...$verifierAttempts];

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
     * P1-4: judge a verification task by a PANEL of independent verifier
     * votes instead of one verifier.
     *
     * All `votes` dispatches run concurrently on one pool, on what is left
     * of the stage budget; they are reserved against `config.maxAgents` as
     * one wave before any of them forks. Vote k interpolates `{{lens}}` as
     * `lenses[k]` when lenses are declared (otherwise the token stays
     * verbatim). Each vote is scanned like a single verifier
     * ({@see scanVerdict()}), and then:
     *
     * - an `ESCALATE:` line in ANY vote outranks everything — the stage
     *   fails for a human, the quorum is not consulted and no retry runs;
     * - a reject pattern that cannot compile fails the stage CLOSED;
     * - a vote REJECTS when its output matches `reject`, and also when it
     *   failed, timed out or never produced a result: a vote that could
     *   not attest the claim never counts toward the quorum;
     * - the verdict passes iff at least `quorum` votes did not reject.
     *
     * `retries` re-runs the WHOLE panel after a missed quorum (each vote
     * itself is dispatched with no retries of its own); the rejection reason
     * quotes every rejecting vote. `$votesOut` receives the last round's
     * `[{lens, rejected, output|data}]` for `{{<stage>.votes}}` — `data`
     * (the decoded document) when the verifier declares an outputSchema and
     * the vote's output decodes, `output` (its text) otherwise.
     *
     * @param array<array-key, mixed> $verifierContext the task-folded context, `prevResult` set
     * @param list<array{key: string, result: AgentResult, schema: bool}> $dispatched
     * @param ?list<array<string, mixed>> $votesOut
     */
    private function runVerifierPanel(
        string $stageName,
        AgentResult $taskResult,
        WorkflowTask $verifier,
        array $verifierContext,
        array &$dispatched,
        int $stageTimeout,
        int $stageClock,
        \DateTimeImmutable $stageStartedAt,
        ?array &$votesOut,
    ): StageResult {
        $votes = $verifier->votes;
        $quorum = $verifier->effectiveQuorum();
        $rounds = 1 + ($verifier->retries ?? 0);
        $baseKey = self::resultKey($verifier->name, $stageName . '_verifier');
        $label = static fn (int $k): string => 'vote ' . ($k + 1) . ($verifier->lenses !== null ? ' (' . $verifier->lenses[$k] . ')' : '');
        $allAttempts = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $subAgents = [];
            $resultKeys = [];
            $defaultRequest = null;
            for ($k = 0; $k < $votes; $k++) {
                $voteContext = $verifierContext;
                if ($verifier->lenses !== null) {
                    $voteContext['lens'] = $verifier->lenses[$k];
                }
                $prompt = $this->interpolateContext($verifier->prompt, self::resolveStageInput($voteContext, $verifier->input));
                $agent = new Agent(
                    name: $verifier->name ?? $verifier->agentType,
                    description: $prompt,
                    prompt: '',
                    model: $this->model,
                    provider: $this->provider,
                    tools: $verifier->tools,
                    skillNames: [],
                    hooks: [],
                    isActive: true,
                    environmentRoot: $this->environmentRoot,
                );
                $defaultRequest ??= new CompleteRequest(
                    model: $agent->model,
                    messages: [['role' => 'user', 'content' => $prompt]],
                    tools: $this->resolveRequestTools($verifier->tools),
                    systemPrompt: $this->renderStagePrompt($agent),
                );
                $subAgent = new SubAgent(
                    id: $stageName . '-vote' . ($k + 1) . '-' . uniqid(getmypid() . '_', true),
                    agent: $agent,
                    task: $prompt,
                    timeout: $verifier->timeout ?? $stageTimeout,
                    // The panel is the retry unit: `retries` re-runs all of it.
                    maxRetries: 0,
                    stallTimeout: $verifier->stallTimeout ?? 0,
                    stallRestarts: $verifier->restarts,
                    isolation: $verifier->isolation ?? \SugarCraft\Code\Agents\Isolation::None,
                    outputSchema: $verifier->outputSchema,
                    model: $verifier->model,
                    permissionGate: $this->permissionGate,
                );
                $subAgents[] = $subAgent;
                $resultKeys[$subAgent->id] = ['key' => $baseKey . '_' . ($k + 1), 'schema' => $verifier->outputSchema !== null];
            }

            $outcomes = $this->dispatchWave($subAgents, $resultKeys, $defaultRequest, $votes, $stageTimeout, $stageClock, $dispatched);

            $passes = 0;
            $rejections = [];
            $escalation = null;
            $invalid = null;
            $voteRows = [];
            $outputs = [];
            foreach ($outcomes as $k => [$key, $result]) {
                if ($result !== null) {
                    $allAttempts[] = $result;
                }
                $text = $result?->output ?? '';
                $outputs[] = $text;
                $rejected = true;
                if ($result === null) {
                    $rejections[] = $label($k) . ' was cancelled before it produced a result';
                } elseif ($result->status !== AgentStatus::Completed) {
                    $rejections[] = $label($k) . ' ' . ($result->status === AgentStatus::TimedOut ? 'timed out' : 'failed')
                        . ($result->error !== null ? ': ' . self::oneLine($result->error->getMessage()) : '');
                } else {
                    $scan = self::scanVerdict($verifier->reject, $text, 1, $verifier->rejectMinOccurrences);
                    if ($scan['kind'] === self::VERDICT_ESCALATED) {
                        $escalation ??= str_replace('verifier asked', 'verifier ' . $label($k) . ' asked', $scan['reason']);
                        $rejected = false;
                    } elseif ($scan['kind'] === self::VERDICT_INVALID) {
                        $invalid ??= $scan['reason'];
                    } elseif ($scan['kind'] === self::VERDICT_REJECTED) {
                        $rejections[] = $label($k) . ' ' . preg_replace('/^verdict rejected: verifier output /', '', $scan['reason']);
                    } else {
                        $rejected = false;
                    }
                }
                if (!$rejected) {
                    $passes++;
                }
                $row = ['lens' => $verifier->lenses[$k] ?? null, 'rejected' => $rejected];
                $decoded = $verifier->outputSchema !== null ? json_decode($text, true) : null;
                $voteRows[] = is_array($decoded) ? $row + ['data' => $decoded] : $row + ['output' => $text];
            }
            $votesOut = $voteRows;
            $joined = implode("\n", $outputs);
            $settle = fn (WorkflowStatus $status, ?string $error, string $output): StageResult => new StageResult(
                stageName: $stageName,
                status: $status,
                output: $output,
                error: $error,
                agents: [$taskResult, ...$allAttempts],
                startedAt: $taskResult->startedAt ?? $stageStartedAt,
                completedAt: new \DateTimeImmutable(),
            );

            // A question outranks every verdict and is never retried.
            if ($escalation !== null) {
                return $settle(WorkflowStatus::Failed, $escalation . ($round > 1 ? " [panel round {$round}]" : ''), $joined);
            }
            if ($invalid !== null) {
                return $settle(WorkflowStatus::Failed, $invalid, $joined);
            }
            if ($passes >= $quorum) {
                return $settle(WorkflowStatus::Completed, null, trim(($taskResult->output ?? '') . "\n" . $joined));
            }
            if ($round === $rounds) {
                return $settle(WorkflowStatus::Failed, sprintf(
                    'verdict rejected by the panel: %d of %d votes passed and the quorum is %d; %s%s',
                    $passes,
                    $votes,
                    $quorum,
                    implode('; ', $rejections),
                    $rounds > 1 ? " [failed on all {$rounds} panel rounds]" : '',
                ), $joined);
            }
        }

        // Unreachable: the last round always returns.
        throw new \LogicException('verifier panel settled no round');
    }

    /** A message as one control-free line, clipped like a matched verdict line. */
    private static function oneLine(string $text): string
    {
        $line = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));

        return mb_strlen($line) > 160 ? mb_substr($line, 0, 157) . '...' : $line;
    }

    /**
     * One concurrent wave of agents on a fresh pool sized $width, bounded by
     * what is left of the stage budget — the shape a parallel stage
     * dispatches with (stage pool, the shared pool's executor, worker
     * provider, forked executor and retry floor), reserved against
     * `config.maxAgents` as one block before anything forks. Each settled
     * agent is appended to $dispatched in DECLARATION order; the return
     * pairs every declared agent with its result (null: never produced one).
     *
     * @param list<SubAgent> $subAgents
     * @param array<string, array{key: string, schema: bool}> $resultKeys
     * @param list<array{key: string, result: AgentResult, schema: bool}> $dispatched
     * @return list<array{0: string, 1: ?AgentResult}>
     */
    private function dispatchWave(array $subAgents, array $resultKeys, CompleteRequest $defaultRequest, ?int $width, int $stageTimeout, int $stageClock, array &$dispatched): array
    {
        $pool = new AgentWorkerPool(
            maxConcurrent: $width,
            executor: $this->pool->executor(),
            workerProvider: $this->pool->workerProvider(),
            forkedExecutor: $this->pool->forkedExecutor(),
        );
        $pool = $pool->withMaxRetries($this->pool->maxRetries());
        $pool = $this->dispatching($pool->withTimeBudget(self::remainingBudget($stageTimeout, $stageClock)));

        $this->reserveAgents(count($subAgents));

        $results = $this->agentManager !== null
            ? $this->agentManager->executeAll($subAgents, $defaultRequest, $pool)
            : $pool->executeAll($subAgents, $defaultRequest);

        $agentResults = [];
        foreach ($results as $agentResult) {
            $agentResults[] = $agentResult;
            $this->foldStageWriteSignal($agentResult);
        }
        $dispatched = [...$dispatched, ...self::recordsInDeclarationOrder($resultKeys, $agentResults)];

        return self::declaredOutcomes($resultKeys, $agentResults);
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
        $this->reserveAgents(1);

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
     * P0-3 `config.maxAgents`: count $n dispatches against the run-wide
     * ceiling BEFORE any of them forks, or refuse them all. The throw lands
     * through the stage loop's catch as that stage's failure, so a stage
     * past the backstop dispatches nothing and the run stops there.
     *
     * @throws \RuntimeException "agent ceiling reached (N)" when $n more would cross it.
     */
    private function reserveAgents(int $n): void
    {
        if ($this->agentCeiling !== null && $this->agentsDispatched + $n > $this->agentCeiling) {
            throw new \RuntimeException(sprintf(
                'agent ceiling reached (%d): this run has dispatched %d agent%s and this stage needs %d more; '
                . 'raise config.maxAgents or narrow the fan-out',
                $this->agentCeiling,
                $this->agentsDispatched,
                $this->agentsDispatched === 1 ? '' : 's',
                $n,
            ));
        }

        $this->agentsDispatched += $n;
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
        return $this->dispatching($this->pool->withTimeBudget(self::remainingBudget($stageTimeout, $stageClock)));
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
     * via AgentWorkerPool::executeAll(). The workflow's maxConcurrent setting
     * controls how many agents run at once ({@see concurrencyFor()}): unset
     * runs the CPU-derived default width, an explicit 0 runs them all (P0-3).
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
     * @param list<array{key: string, result: AgentResult, schema: bool}> $dispatched Out: one entry per agent that settled.
     * @param ?array<array-key, mixed> $waveLedger C8 in/out: the live run's
     *     dynamic-wave record, keyed by stage name (a numeric-string stage name
     *     takes its native int key — PHP casts array keys, so the map is
     *     array-key-keyed by construction). A first expansion writes
     *     its MATERIALIZED list here (so pause writers persist it); a resume
     *     reads the recorded list back. Null means the caller keeps no
     *     ledger — expansion then happens fresh on every call.
     * @param ?list<string> $failedNames P1-3 out: on a `minSuccess:` stage,
     *     the result names of every casualty in declaration order (empty when
     *     none failed); untouched on a stage without the key.
     * @return StageResult
     */
    private function executeParallelStage(array $stage, array $context, Workflow $workflow, array &$dispatched, ?array &$waveLedger = null, ?array &$failedNames = null): StageResult
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

        // C8: a "from:" parallel stage swaps its single template task for a
        // runtime fan-out, expanded ONCE here at stage entry — a glob against
        // the workflow's environment root (C8), or a list read from a prior
        // stage's typed row or the run context (P1-1). The pause record keeps
        // the MATERIALIZED list — a resume replays that recorded list and
        // never re-derives it, because files or rows that changed mid-run
        // must fail the resume loudly rather than silently fork different
        // work from what was paused.
        $dynamic = isset($stage['from']);
        $chunk = null;
        // P0-3: the `[from: kept N of M …]` record an explicit `max:` leaves
        // on the stage output — a header line above it and the dropped list
        // below it; both empty when nothing was truncated.
        $fromNotice = ['', ''];
        // P1-1: the item each fanned-out task interpolates as {{item}},
        // parallel to $tasks (a glob item is its path).
        $itemValues = [];
        $isGlob = false;
        if ($dynamic) {
            $template = $tasks[0] ?? null;
            if (count($tasks) !== 1 || !$template instanceof WorkflowTask) {
                return new StageResult(
                    stageName: $stageName,
                    status: WorkflowStatus::Failed,
                    error: "Stage '{$stageName}' carries a from: fan-out without its single template task",
                    startedAt: $stageStartedAt,
                    completedAt: new \DateTimeImmutable(),
                );
            }

            $from = $stage['from'];
            $isGlob = isset($from['glob']);
            if ($isGlob) {
                $recorded = $waveLedger[$stageName] ?? null;
                if ($recorded === null) {
                    $paths = $this->expandFromGlob((string) $from['glob']);
                    $chunk = $from['chunk'];
                    $max = $from['max'] ?? null;
                    $matched = count($paths);
                    $dropped = [];
                    // P0-3: `max:` is the ONE sanctioned truncation — of the
                    // sorted expansion, so the kept prefix is deterministic — and
                    // it is recorded, never silent.
                    if (is_int($max) && $matched > $max) {
                        $dropped = array_slice($paths, $max);
                        $paths = array_slice($paths, 0, $max);
                    }

                    // Judged BEFORE the ledger records anything: a resume of a
                    // refused wave must re-glob the author's narrowed pattern,
                    // not replay the list that was too wide.
                    $ceilingRefusal = self::fanOutCeilingRefusal($stageName, count($paths), (string) $from['glob'], $stageStartedAt);
                    if ($ceilingRefusal !== null) {
                        return $ceilingRefusal;
                    }

                    if ($waveLedger !== null) {
                        $waveLedger[$stageName] = ['paths' => $paths, 'chunk' => $chunk]
                            + (is_int($max) ? ['max' => $max, 'matched' => $matched, 'dropped' => $dropped] : []);
                    }
                    $fromNotice = self::fromTruncationNotice(count($paths), $matched, $max, $dropped);
                } else {
                    $replayable = false;
                    if (is_array($recorded)) {
                        $replayable = isset($recorded['paths'])
                            && is_array($recorded['paths']) && array_is_list($recorded['paths'])
                            && (!array_key_exists('chunk', $recorded)
                                || $recorded['chunk'] === null || is_int($recorded['chunk']))
                            // P0-3: a truncation record is optional, but when
                            // present it is a whole one — or the replay refuses.
                            && (!array_key_exists('max', $recorded)
                                || (is_int($recorded['max'])
                                    && is_int($recorded['matched'] ?? null)
                                    && is_array($recorded['dropped'] ?? null)
                                    && array_is_list($recorded['dropped'])
                                    && array_filter($recorded['dropped'], static fn (mixed $d): bool => !is_string($d)) === []));
                        if ($replayable) {
                            foreach ($recorded['paths'] as $candidate) {
                                if (!is_string($candidate) || $candidate === '') {
                                    $replayable = false;
                                    break;
                                }
                            }
                        }
                    }

                    if (!$replayable) {
                        return new StageResult(
                            stageName: $stageName,
                            status: WorkflowStatus::Failed,
                            error: "Stage '{$stageName}' resumed with a dynamic-wave record it cannot replay — refusing to re-glob over files that may have changed",
                            startedAt: $stageStartedAt,
                            completedAt: new \DateTimeImmutable(),
                        );
                    }

                    $paths = $recorded['paths'];
                    $chunk = $recorded['chunk'] ?? $from['chunk'];

                    $ceilingRefusal = self::fanOutCeilingRefusal($stageName, count($paths), (string) $from['glob'], $stageStartedAt);
                    if ($ceilingRefusal !== null) {
                        return $ceilingRefusal;
                    }
                    if (array_key_exists('max', $recorded)) {
                        $fromNotice = self::fromTruncationNotice(count($paths), $recorded['matched'], $recorded['max'], $recorded['dropped']);
                    }
                }

                if ($paths === []) {
                    return new StageResult(
                        stageName: $stageName,
                        status: WorkflowStatus::Failed,
                        error: "Stage '{$stageName}' expanded no files from \"{$from['glob']}\"",
                        startedAt: $stageStartedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                }

                $itemValues = $paths;
                $names = $paths;
            } else {
                $expansion = $this->expandFromData($stageName, $from, $context, $waveLedger, $stageStartedAt);
                if ($expansion instanceof StageResult) {
                    return $expansion;
                }
                ['items' => $itemValues, 'names' => $names, 'chunk' => $chunk, 'notice' => $fromNotice] = $expansion;

                // P1-1: an empty data list is "nothing to do", not a typo the
                // way an empty glob is — the stage completes with no agents
                // and an empty output, so a find → verify chain whose finder
                // found nothing flows on.
                if ($itemValues === []) {
                    if (is_array($stage['minSuccess'] ?? null)) {
                        $failedNames = [];
                    }

                    return new StageResult(
                        stageName: $stageName,
                        status: WorkflowStatus::Completed,
                        output: $fromNotice[0] . $fromNotice[1],
                        startedAt: $stageStartedAt,
                        completedAt: new \DateTimeImmutable(),
                    );
                }
            }

            // Every template field rides each item's task — the fan-out
            // used to drop stallTimeout, restarts and input on the floor.
            $tasks = array_map(static fn (?string $name): WorkflowTask => new WorkflowTask(
                agentType: $template->agentType,
                prompt: $template->prompt,
                tools: $template->tools,
                timeout: $template->timeout,
                retries: $template->retries,
                isolation: $template->isolation,
                name: $name,
                outputSchema: $template->outputSchema,
                model: $template->model,
                reject: $template->reject,
                rejectMinOccurrences: $template->rejectMinOccurrences,
                resource: $template->resource,
                lockWaitSeconds: $template->lockWaitSeconds,
                stallTimeout: $template->stallTimeout,
                restarts: $template->restarts,
                input: $template->input,
            ), $names);
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
        // C8: a fanned-out item interpolates against its own {{path}}; the
        // static spelling keeps the workflow context exactly as it was. Note
        // the array-plus direction: a context that already defines 'path'
        // wins, so an author-set path variable shadows the item's own path.
        $firstContext = $dynamic ? self::fanOutItemContext($context, $isGlob, $firstTask->name, $itemValues, 0) : $context;
        $firstInterpolated = $this->interpolateContext($firstTask->prompt, self::resolveStageInput($firstContext, $firstTask->input));

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
            systemPrompt: $this->renderStagePrompt($firstAgent),
        );

        $subAgents = [];
        /** @var array<string, array{key: string, schema: bool}> $resultKeys SubAgent id => result name + schema flag, in declaration order */
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

            // C8: on a glob fan-out, {{path}} names the item; P1-1: every
            // fan-out item also carries {{item}}/{{index}}. Static stages
            // keep the workflow context exactly as it was.
            $itemContext = $dynamic
                ? self::fanOutItemContext($context, $isGlob, $task->name, $itemValues, $agentIndex - 1)
                : $context;

            $interpolatedPrompt = $this->interpolateContext($task->prompt, self::resolveStageInput($itemContext, $task->input));

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
                stallTimeout: $task->stallTimeout ?? 0,
                stallRestarts: $task->restarts,
                isolation: $task->isolation ?? \SugarCraft\Code\Agents\Isolation::None,
                outputSchema: $task->outputSchema,
                // S2: the per-stage model ask; null keeps today's session-default dial.
                model: $task->model,
                permissionGate: $this->permissionGate,
            );
            $subAgents[] = $subAgent;
            $resultKeys[$subAgent->id] = [
                'key' => self::resultKey($task->name, $stageName . '_' . $agentIndex),
                'schema' => $task->outputSchema !== null,
            ];
        }

        // Create a fresh pool scoped to this parallel stage so that workflow-level
        // settings (maxConcurrent, stopOnFirstFailure) do not mutate the shared
        // $this->pool instance. The pool's executor is preserved from $this->pool
        // via executor() so that custom executors (e.g., test mocks) are honoured.
        //
        // workerProvider() is carried across for the same reason and was NOT,
        // until this line existed. executor() is null for a pool that builds
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
        // C8: "chunk" caps this stage's in-flight window — a ceiling, never
        // a floor: it can narrow the workflow width, never widen it.
        // P0-3: the workflow width is resolved once — explicit, explicit 0
        // (no cap, null here), or the CPU-derived default when unset.
        $width = $this->concurrencyFor($workflow);
        $concurrency = $dynamic && $chunk !== null
            ? ($width === null ? $chunk : min($width, $chunk))
            : $width;

        $pool = new AgentWorkerPool(
            maxConcurrent: $concurrency,
            executor: $this->pool->executor(),
            workerProvider: $this->pool->workerProvider(),
            forkedExecutor: $this->pool->forkedExecutor(),
        );
        // P1-3: a `minSuccess:` stage is exempt from stopOnFirstFailure —
        // cancelling every sibling on the first casualty would defeat the
        // tolerance the stage itself declares.
        $minSuccess = is_array($stage['minSuccess'] ?? null) ? $stage['minSuccess'] : null;
        if ($workflow->stopOnFirstFailure && $minSuccess === null) {
            $pool = $pool->withStopOnFirstFailure(true);
        }
        // The retry floor is pool state the constructor cannot carry, like
        // the two accessors above (audit WF-1(b)); each task's own `retries`
        // rides on its SubAgent.
        $pool = $pool->withMaxRetries($this->pool->maxRetries());
        // WF-1: the stage's budget bounds the whole fan-out, queue time
        // included — see stagePool() for why it is the remainder.
        $pool = $this->dispatching($pool->withTimeBudget(self::remainingBudget($workflow->timeout, $stageClock)));

        // Route the stage pool through the AgentManager when one is attached:
        // the manager registers each SubAgent and mirrors per-result usage back
        // onto it, which is what makes its elapsed/token/cost accessors (and so
        // the live agent status line) report real work for workflow-spawned
        // agents instead of zeros (section 5 E6). Exactly one of
        // the two paths runs -- the manager accumulates with `+=`, so iterating
        // the pool as well would double-count this stage's usage.
        // P0-3: the whole wave is reserved against config.maxAgents before
        // any of it forks — a wave that would cross the ceiling forks none.
        $this->reserveAgents(count($subAgents));

        $results = $this->agentManager !== null
            ? $this->agentManager->executeAll($subAgents, $defaultRequest, $pool)
            : $pool->executeAll($subAgents, $defaultRequest);

        // Collect all results from the generator
        $agentResults = [];
        foreach ($results as $agentResult) {
            $agentResults[] = $agentResult;
            // M-11 (cc-fix-1111): fold each wave member's self-report so the
            // NEXT stage's render sees whether anything in this wave wrote.
            $this->foldStageWriteSignal($agentResult);
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
        $error = $anyFailure ? ($firstResult?->error?->getMessage() ?? 'One or more parallel tasks failed') : null;
        $casualtyLine = '';

        // P1-3: judge the stage by its declared tolerance instead of "any
        // failure fails it". Zero casualties leaves every byte as it was.
        if ($minSuccess !== null) {
            $casualties = [];
            foreach (self::declaredOutcomes($resultKeys, $agentResults) as [$key, $outcome]) {
                if ($outcome === null) {
                    $casualties[] = [$key, 'cancelled'];
                } elseif ($outcome->status === AgentStatus::Failed || $outcome->status === AgentStatus::TimedOut) {
                    $casualties[] = [$key, $outcome->status->value];
                }
            }
            $failedNames = array_column($casualties, 0);

            if ($casualties !== []) {
                $total = count($subAgents);
                $required = WorkflowBuilder::requiredSuccesses($minSuccess, $total);
                $succeeded = $total - count($casualties);
                $listed = implode(', ', array_map(static fn (array $c): string => "{$c[0]} ({$c[1]})", $casualties));
                $casualtyLine = sprintf("\n[%d of %d agents failed: %s]", count($casualties), $total, $listed);

                if ($succeeded >= $required) {
                    $status = WorkflowStatus::Completed;
                    $error = null;
                } else {
                    $status = WorkflowStatus::Failed;
                    $error = sprintf(
                        'minSuccess %s not met: %d of %d agents succeeded and %d %s needed; failed: %s',
                        WorkflowBuilder::minSuccessLabel($minSuccess),
                        $succeeded,
                        $total,
                        $required,
                        $required === 1 ? 'was' : 'were',
                        $listed,
                    );
                }
            }
        }

        return new StageResult(
            stageName: $stageName,
            status: $status,
            output: $fromNotice[0] . implode("\n", $allOutputs) . $casualtyLine . $fromNotice[1],
            error: $error,
            agents: $agentResults,
            startedAt: $firstResult?->startedAt ?? $stageStartedAt,
            completedAt: $firstResult?->completedAt ?? new \DateTimeImmutable(),
        );
    }

    /**
     * P1-5: fold one settled round of a `loop.accumulate` stage into the
     * accumulator, returning the round's result (or a FAILED copy of it when
     * its rows cannot be folded).
     *
     * Contributing rows: every completed, schema'd agent of the round — all
     * of a parallel stage's (their arrays are unioned in declaration order),
     * a `tasks:` stage's, and only the TASK of a verification stage (the
     * verifier's verdict is not the work). Each must decode to JSON whose
     * `pointer` is an array; anything else fails the round loudly, as does a
     * round with no contributing row at all. A failed round folds nothing
     * and leaves the dry count where it was.
     *
     * Dedup is against everything SEEN (the reference's convergence rule):
     * an item's identity is its `key` leaf (string, number or bool) or, with
     * no `key`, the item itself (a scalar's text, else its compact JSON). A
     * round that adds no unseen identity increments the dry count; one that
     * adds anything resets it to 0.
     *
     * @param array<array-key, mixed> $stage
     * @param list<array{key: string, result: AgentResult, schema: bool}> $records
     * @param array{pointer: string, key?: string} $accumulate
     * @param array{items: list<mixed>, keys: list<string>, index: array<string, true>, fresh: list<mixed>, dry: int} $accumulator
     */
    private static function accumulateRound(array $stage, string $stageType, StageResult $round, array $records, array $accumulate, array &$accumulator): StageResult
    {
        if (!$round->isSuccess()) {
            return $round;
        }

        $stageName = $round->stageName;
        $fail = static fn (string $error): StageResult => new StageResult(
            stageName: $stageName,
            status: WorkflowStatus::Failed,
            output: $round->output,
            error: $error,
            agents: $round->agents,
            startedAt: $round->startedAt,
            completedAt: $round->completedAt,
        );

        $taskKey = null;
        if ($stageType === 'verification' && ($stage['task'] ?? null) instanceof WorkflowTask) {
            $taskKey = self::resultKey($stage['task']->name, $stageName);
        }

        $pointer = $accumulate['pointer'];
        $keyPointer = $accumulate['key'] ?? null;
        $roundItems = [];
        $contributors = 0;
        foreach ($records as $record) {
            if (!$record['schema'] || $record['result']->status !== AgentStatus::Completed) {
                continue;
            }
            if ($taskKey !== null && $record['key'] !== $taskKey) {
                continue;
            }
            $contributors++;
            $hit = JsonPointer::resolveJson($record['result']->output ?? '', $pointer);
            if (!$hit['found'] || !is_array($hit['value']) || !array_is_list($hit['value'])) {
                return $fail(
                    "loop.accumulate: agent '{$record['key']}' of stage '{$stageName}' answered "
                    . ($hit['found'] ? self::jsonKind($hit['value']) : 'nothing')
                    . " at \"{$pointer}\", not an array"
                );
            }
            foreach ($hit['value'] as $item) {
                $roundItems[] = [$record['key'], $item];
            }
        }
        if ($contributors === 0) {
            return $fail(
                "loop.accumulate: stage '{$stageName}' settled a round with no schema'd agent result to read \"{$pointer}\" from; "
                . 'give its agents an outputSchema'
            );
        }

        $fresh = [];
        foreach ($roundItems as $position => [$agent, $item]) {
            if ($keyPointer !== null) {
                $leaf = JsonPointer::resolve($item, $keyPointer);
                $identity = $leaf['found'] ? self::scalarKey($leaf['value']) : null;
                if ($identity === null) {
                    return $fail(
                        "loop.accumulate: an item from agent '{$agent}' of stage '{$stageName}' has no string, number or bool key at \"{$keyPointer}\""
                    );
                }
            } else {
                $identity = self::scalarKey($item) ?? self::compactJson($item);
            }
            if (isset($accumulator['index'][$identity])) {
                continue;
            }
            $accumulator['index'][$identity] = true;
            $accumulator['keys'][] = $identity;
            $accumulator['items'][] = $item;
            $fresh[] = $item;
        }

        $accumulator['fresh'] = $fresh;
        $accumulator['dry'] = $fresh === [] ? $accumulator['dry'] + 1 : 0;

        return $round;
    }

    /** Compact JSON (unescaped slashes and unicode, zero fractions kept) — the engine's one spelling of a JSON value in a prompt or a row. */
    private static function compactJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return is_string($json) ? $json : 'null';
    }

    /**
     * C8 + P1-1: one fan-out item's interpolation context. A glob item keeps
     * C8's `{{path}}` with its left-wins array-plus (a context `path`
     * variable shadows the item path, as it always has); every item —
     * glob or data — carries {@see FAN_OUT_ITEM_CONTEXT_KEY} for `{{item}}`,
     * `{{item.<pointer>}}` and `{{index}}`, which no context key can shadow.
     *
     * @param array<array-key, mixed> $context
     * @param list<mixed> $itemValues
     * @return array<array-key, mixed>
     */
    private static function fanOutItemContext(array $context, bool $isGlob, ?string $taskName, array $itemValues, int $index): array
    {
        if ($isGlob && $taskName !== null) {
            $context += ['path' => $taskName];
        }
        if (array_key_exists($index, $itemValues)) {
            $context[self::FAN_OUT_ITEM_CONTEXT_KEY] = ['item' => $itemValues[$index], 'index' => $index];
        }

        return $context;
    }

    /**
     * P1-1: materialise a DATA fan-out (`from: {stage, pointer}` or
     * `from: {context}`) — or replay the list a pause recorded for it.
     *
     * Fresh: the source must resolve to a JSON array (a list) — anything else
     * fails the stage loudly, naming what it found. With `key:` each item's
     * key leaf (a string, number or bool) dedups the list (first occurrence
     * wins, order kept) and names its task `<stage>_<key>`, sanitised to the
     * `[a-zA-Z_][a-zA-Z0-9_]*` result-name grammar (a collision after
     * sanitising takes `_2`, `_3`, …); an item without a scalar key leaf
     * fails the stage. Without `key:` items are neither deduped nor named
     * (their results fall back to `<stage>_<n>`). `max:` keeps the first N
     * (source order) and records the rest; the 4096 ceiling refuses. The
     * materialised list is written to $waveLedger BEFORE dispatch, exactly as
     * a glob expansion is, so a resume replays it and never re-reads the
     * source.
     *
     * @param array<string, mixed> $from
     * @param array<array-key, mixed> $context
     * @param ?array<array-key, mixed> $waveLedger
     * @return StageResult|array{items: list<mixed>, names: list<?string>, chunk: ?int, notice: array{0: string, 1: string}}
     */
    private function expandFromData(string $stageName, array $from, array $context, ?array &$waveLedger, \DateTimeImmutable $startedAt): StageResult|array
    {
        $fail = static fn (string $error): StageResult => new StageResult(
            stageName: $stageName,
            status: WorkflowStatus::Failed,
            error: $error,
            startedAt: $startedAt,
            completedAt: new \DateTimeImmutable(),
        );
        $sourceLabel = isset($from['stage'])
            ? "stage '{$from['stage']}' at \"{$from['pointer']}\""
            : "context '{$from['context']}'";

        $recorded = $waveLedger[$stageName] ?? null;
        if ($recorded !== null) {
            $replayable = is_array($recorded)
                && is_array($recorded['items'] ?? null) && array_is_list($recorded['items'])
                && is_array($recorded['names'] ?? null) && array_is_list($recorded['names'])
                && count($recorded['names']) === count($recorded['items'])
                && array_filter($recorded['names'], static fn (mixed $n): bool => $n !== null && (!is_string($n) || $n === '')) === []
                && (!array_key_exists('chunk', $recorded) || $recorded['chunk'] === null || is_int($recorded['chunk']))
                && (!array_key_exists('max', $recorded)
                    || (is_int($recorded['max'])
                        && is_int($recorded['matched'] ?? null)
                        && is_array($recorded['dropped'] ?? null)
                        && array_is_list($recorded['dropped'])
                        && array_filter($recorded['dropped'], static fn (mixed $d): bool => !is_string($d)) === []));
            if (!$replayable) {
                return $fail("Stage '{$stageName}' resumed with a dynamic-wave record it cannot replay — refusing to re-read {$sourceLabel}, which may have changed");
            }
            $ceiling = self::fanOutCeilingRefusal($stageName, count($recorded['items']), $sourceLabel, $startedAt, 'items');
            if ($ceiling !== null) {
                return $ceiling;
            }

            return [
                'items' => $recorded['items'],
                'names' => $recorded['names'],
                'chunk' => $recorded['chunk'] ?? $from['chunk'] ?? null,
                'notice' => array_key_exists('max', $recorded)
                    ? self::fromTruncationNotice(count($recorded['items']), $recorded['matched'], $recorded['max'], $recorded['dropped'])
                    : ['', ''],
            ];
        }

        if (isset($from['stage'])) {
            $typedRows = $context[self::TYPED_RESULTS_CONTEXT_KEY] ?? [];
            $row = is_array($typedRows) ? ($typedRows[$from['stage']] ?? null) : null;
            if (!is_string($row)) {
                return $fail(
                    "Stage '{$stageName}' fans out from stage '{$from['stage']}', which left no typed row to point into "
                    . "(a typed row is written under the result name '{$from['stage']}' by a schema'd task of that name, "
                    . 'or by a loop.accumulate stage)'
                );
            }
            $hit = JsonPointer::resolveJson($row, (string) $from['pointer']);
            $list = $hit['found'] ? $hit['value'] : null;
            $what = $hit['found'] ? self::jsonKind($hit['value']) : 'nothing';
        } else {
            $present = array_key_exists($from['context'], $context);
            $list = $present ? $context[$from['context']] : null;
            $what = $present ? self::jsonKind($list) : 'nothing (the run was handed no such key)';
        }
        if (!is_array($list) || !array_is_list($list)) {
            return $fail("Stage '{$stageName}' fans out from {$sourceLabel}, which is {$what}, not an array");
        }

        $items = [];
        $names = [];
        $labels = [];
        $keyPointer = $from['key'] ?? null;
        if (is_string($keyPointer)) {
            $seen = [];
            $taken = [];
            foreach ($list as $position => $item) {
                $leaf = JsonPointer::resolve($item, $keyPointer);
                $key = $leaf['found'] ? self::scalarKey($leaf['value']) : null;
                if ($key === null) {
                    return $fail(
                        "Stage '{$stageName}' fans out from {$sourceLabel}: item #" . ($position + 1)
                        . " has no string, number or bool key at \"{$keyPointer}\""
                    );
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $items[] = $item;
                $names[] = self::itemTaskName($stageName, $key, $taken);
                $labels[] = $key;
            }
        } else {
            foreach ($list as $item) {
                $items[] = $item;
                $names[] = null;
                $labels[] = self::itemLabel($item);
            }
        }

        $chunk = $from['chunk'] ?? null;
        $max = $from['max'] ?? null;
        $matched = count($items);
        $dropped = [];
        if (is_int($max) && $matched > $max) {
            $dropped = array_slice($labels, $max);
            $items = array_slice($items, 0, $max);
            $names = array_slice($names, 0, $max);
        }

        // Judged BEFORE the ledger records anything, like the glob leg.
        $ceiling = self::fanOutCeilingRefusal($stageName, count($items), $sourceLabel, $startedAt, 'items');
        if ($ceiling !== null) {
            return $ceiling;
        }

        if ($waveLedger !== null) {
            $waveLedger[$stageName] = ['items' => $items, 'names' => $names, 'chunk' => $chunk]
                + (is_int($max) ? ['max' => $max, 'matched' => $matched, 'dropped' => $dropped] : []);
        }

        return [
            'items' => $items,
            'names' => $names,
            'chunk' => $chunk,
            'notice' => self::fromTruncationNotice(count($items), $matched, $max, $dropped),
        ];
    }

    /** A JSON value's kind, for a refusal: "an object", "a string", "null", … */
    private static function jsonKind(mixed $value): string
    {
        return match (true) {
            is_array($value) => array_is_list($value) ? 'an array' : 'an object',
            is_string($value) => 'a string',
            is_bool($value) => 'a bool',
            is_int($value), is_float($value) => 'a number',
            $value === null => 'null',
            default => get_debug_type($value),
        };
    }

    /** A dedup key leaf as text: strings verbatim, numbers cast, bools true/false; null for anything else. */
    private static function scalarKey(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }

    /**
     * P1-1: `<stage>_<key>` reduced to the `{{name.results}}` grammar —
     * every other byte becomes `_`, a leading digit gains a `_` — with
     * `_2`, `_3`, … appended when two keys sanitise to the same name.
     *
     * @param array<string, true> $taken in/out: names already handed out
     */
    private static function itemTaskName(string $stageName, string $key, array &$taken): string
    {
        $base = (string) preg_replace('/[^A-Za-z0-9_]/', '_', $stageName . '_' . $key);
        if (preg_match('/^[A-Za-z_]/', $base) !== 1) {
            $base = '_' . $base;
        }
        $name = $base;
        for ($n = 2; isset($taken[$name]); $n++) {
            $name = $base . '_' . $n;
        }
        $taken[$name] = true;

        return $name;
    }

    /** A key-less item's `dropped:` label: compact JSON (strings verbatim), clipped to one line. */
    private static function itemLabel(mixed $item): string
    {
        $text = str_replace(["\r", "\n"], ' ', self::itemText($item));

        return strlen($text) > 120 ? substr($text, 0, 117) . '...' : $text;
    }

    /**
     * P0-3: the loud refusal for a fan-out wider than
     * {@see Workflow::FAN_OUT_CEILING}, or null when $count fits. Never a
     * truncation: cutting the list to fit would be a silent cap, and the
     * author's tool for a deliberate one is `from.max`.
     */
    private static function fanOutCeilingRefusal(string $stageName, int $count, string $source, \DateTimeImmutable $startedAt, string $unit = 'files'): ?StageResult
    {
        if ($count <= Workflow::FAN_OUT_CEILING) {
            return null;
        }

        return new StageResult(
            stageName: $stageName,
            status: WorkflowStatus::Failed,
            error: $unit === 'files'
                ? sprintf(
                    'Stage \'%s\' expanded %d files from "%s"; the per-stage fan-out ceiling is %d — narrow the glob or set from.max',
                    $stageName,
                    $count,
                    $source,
                    Workflow::FAN_OUT_CEILING,
                )
                : sprintf(
                    'Stage \'%s\' expanded %d items from %s; the per-stage fan-out ceiling is %d — set from.max',
                    $stageName,
                    $count,
                    $source,
                    Workflow::FAN_OUT_CEILING,
                ),
            startedAt: $startedAt,
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * P0-3 "no silent caps": the record a `from.max` truncation leaves on
     * its stage output, as `[header, tail]`. The header line goes ABOVE the
     * agents' output so a `capture: true` preview (the first 2 KiB) always
     * shows it; the tail — one `dropped:` line per cut path, in expansion
     * order — goes below, so with capture on the full list lives in the
     * capture file and without it the list rides the output. Both are empty
     * when nothing was dropped: an un-truncated wave's output is
     * byte-identical to a wave without `max:`.
     *
     * @param list<string> $dropped
     * @return array{0: string, 1: string}
     */
    private static function fromTruncationNotice(int $kept, int $matched, ?int $max, array $dropped): array
    {
        if ($dropped === []) {
            return ['', ''];
        }

        $header = sprintf(
            '[from: kept %d of %d matches (max: %d); %d dropped, listed at the end of this output]',
            $kept,
            $matched,
            (int) $max,
            count($dropped),
        );
        $tail = '';
        foreach ($dropped as $path) {
            $tail .= "\ndropped: " . $path;
        }

        return [$header . "\n", $tail];
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
     * {@see Tool} ({@see \SugarCraft\Code\Providers\OpenAIProvider}) or calls
     * `->name()` on each entry ({@see \SugarCraft\Code\Providers\ClaudeCodeProvider}).
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
     * `tools: []` outright; {@see \SugarCraft\Code\Providers\SglangProvider::defaultTopP()}
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

        // Each declaration by its tool-NAME half, so an argument-scoped grant
        // (`Bash(git *)`) puts `Bash` on the wire and the stage agent's
        // SubAgentGrantHook ({@see EngineExecutor}) holds every call to the
        // argument half — the same split Task's grants get
        // ({@see AgentManager::grantedToolsFor()}). A malformed pattern is
        // refused here, never read as a name that happens to match nothing.
        $declared = [];
        foreach ($taskTools as $tool) {
            if (!is_string($tool) || $tool === '') {
                throw new \RuntimeException(sprintf(
                    'A workflow task declares a tool that is not a non-empty tool name (%s); it cannot be resolved against the tool registry.',
                    get_debug_type($tool),
                ));
            }

            $declared[$tool] = self::declaredToolName($tool);
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

            foreach ($declared as $declaration => $namePattern) {
                if (PermissionRule::matchesToolName($namePattern, $tool->name())) {
                    $resolved[] = $tool;
                    $matched[$declaration] = true;
                    break;
                }
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
     * The tool-name half of a `tools:` declaration — `Bash` for `Bash(git *)`,
     * the declaration itself for a bare name — through the one parser the
     * grant matcher uses ({@see PermissionRule}).
     *
     * @throws \RuntimeException on a malformed pattern, naming why.
     */
    private static function declaredToolName(string $declaration): string
    {
        $reason = PermissionRule::patternRejectionReason($declaration);
        if ($reason !== null) {
            throw new \RuntimeException(sprintf(
                'A workflow task declares the tool pattern "%s", which %s.',
                $declaration,
                $reason,
            ));
        }

        return (new PermissionRule($declaration, PermissionAction::Allow))->toolNamePattern();
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
     *   {@see \SugarCraft\Code\Permissions\SafetyClassifier} judges arguments,
     *   and a bare tool name is never dangerous to it. An explicit `Deny` RULE
     *   still refuses under `auto`, and that is the whole of it. Auto's real
     *   enforcement is per-call, at whichever layer runs the call.
     * - `default`, `accept-edits` and `bypass-permissions` refuse nothing; the
     *   first two `Ask`, which is deliberately not a refusal (below).
     *
     * Only `Deny` refuses. `Ask` is not a refusal because settling one requires
     * the blocking permission prompt, and an engine that treated "would have
     * asked" as "no" would make every write-capable stage unrunnable in
     * {@see \SugarCraft\Code\Permissions\PermissionMode::Default} — a policy
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

            if ($this->permissionGate->refuses(new ToolDeclaration(self::declaredToolName($tool)))) {
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
                // Every task, labelled as executeStage() labels it: checking
                // only the first let a later task's refused tool through until
                // its stage started, after the earlier stages had run.
                $tasks = array_values($stage['tasks'] ?? []);
                foreach ($tasks as $i => $task) {
                    $checks[] = [$task, count($tasks) > 1 ? "Stage '{$stageName}' task #" . ($i + 1) : "Stage '{$stageName}'"];
                }
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
     * Whole-workflow pre-flight for reject() placement — gate-independent by
     * design ({@see firstDeclarationRefusal()} abstains without a permission
     * gate; a mis-anchored reject is a declaration bug, not a permission call).
     *
     * reject() rejects a VERDICT, and only a verification stage's verifier
     * produces one, so it is legal exactly there. A [stage name, message]
     * pair in the shape firstDeclarationRefusal() reports, folded into the
     * same refusal channel by the run pre-flight.
     *
     * @return array{0: string, 1: string}|null
     */
    private function firstRejectMisuse(Workflow $workflow): ?array
    {
        foreach ($workflow->stages as $stage) {
            $stageName = $stage['name'] ?? 'unknown';
            $stageType = $stage['type'] ?? '';

            if ($stageType === 'verification') {
                $task = $stage['task'] ?? null;
                if ($task instanceof WorkflowTask && $task->reject !== null) {
                    return [$stageName, self::rejectMisuseMessage("Verification stage '{$stageName}' task slot")];
                }
                if ($task instanceof WorkflowTask && $task->declaresPanel()) {
                    return [$stageName, self::panelMisuseMessage("Verification stage '{$stageName}' task slot")];
                }

                continue;
            }

            $declared = [];
            if ($stageType === 'stage' || $stageType === 'parallel') {
                $declared = $stage['tasks'] ?? [];
            } elseif ($stageType === 'pipeline') {
                foreach ($stage['stages'] ?? [] as $nested) {
                    foreach ($nested['tasks'] ?? [] as $nestedTask) {
                        $declared[] = $nestedTask;
                    }
                }
            }

            foreach ($declared as $task) {
                if ($task instanceof WorkflowTask && $task->reject !== null) {
                    return [$stageName, self::rejectMisuseMessage("Stage '{$stageName}' (type '{$stageType}')")];
                }
                if ($task instanceof WorkflowTask && $task->declaresPanel()) {
                    return [$stageName, self::panelMisuseMessage("Stage '{$stageName}' (type '{$stageType}')")];
                }
            }
        }

        return null;
    }

    /** P1-4: one refusal sentence for every illegal votes/lenses/quorum anchor. */
    private static function panelMisuseMessage(string $label): string
    {
        return "{$label}: votes, lenses and quorum convene a verifier panel and belong on the verifier slot of a verification stage";
    }

    /** One refusal sentence for every illegal reject() anchor. */
    private static function rejectMisuseMessage(string $label): string
    {
        return "{$label}: reject() rejects a verifier VERDICT and belongs on the verifier slot of a verification stage";
    }

    /**
     * Scan a verifier's completed output against its reject() pattern.
     *
     * POLARITY: a MATCH rejects — the pattern names the text that means
     * "not good enough" (e.g. '/REJECT/', '/\bNEEDS-FIX\b/i'). The reason
     * carries the pattern and the matched line so the operator sees WHY the
     * verdict was refused, in the same error field a mechanical failure uses.
     *
     * A pattern that cannot compile at scan time reports INVALID, never
     * CLEAN: a rule that did not run must not read as a pass. (The common
     * path cannot reach here with such a pattern — TaskBuilder::reject()
     * refuses it at set time; a hand-built DTO cannot slip silently.) A
     * hand-built occurrence threshold below 1 reports INVALID for the same
     * reason: a rule that can never read clean must not read as clean.
     *
     * `atLeast` is the occurrence threshold carried from reject(): the
     * verdict is rejected only when the pattern matches at least that many
     * times. 1 — the default — is the match-once behavior, reason string
     * byte-identical.
     *
     * ESCALATION OVERRIDES VERDICTS. An `ESCALATE:` line makes this report
     * VERDICT_ESCALATED BEFORE any reject rule is consulted — a question
     * outranks a verdict, and unlike a rejection it must never re-dispatch
     * (retrying a question only asks it again). A null $pattern means no
     * reject rule is set: the scan then checks for the marker and otherwise
     * reports CLEAN.
     *
     * @return array{kind: string, reason: string}
     */
    private static function scanVerdict(?string $pattern, ?string $output, int $attemptsUsed, int $atLeast = 1): array
    {
        $text = $output ?? '';

        if (preg_match(self::ESCALATION_PATTERN, $text, $asked, PREG_OFFSET_CAPTURE) === 1) {
            $line = self::matchedLine($text, (int) $asked[0][1]);
            $after = $attemptsUsed > 1 ? " [after {$attemptsUsed} attempts]" : '';

            return [
                'kind' => self::VERDICT_ESCALATED,
                'reason' => self::ESCALATION_MARKER . "verifier asked for a human decision on: \"{$line}\""
                    . ' [not retried — the run stops for a person; answer it, then /workflow resume'
                    . ' re-runs this stage]' . $after,
            ];
        }

        if ($pattern === null) {
            return ['kind' => self::VERDICT_CLEAN, 'reason' => ''];
        }

        if ($atLeast < 1) {
            return [
                'kind' => self::VERDICT_INVALID,
                'reason' => "verdict scan failed closed: reject occurrence threshold {$atLeast} is not a positive"
                    . ' number of matches [the stage cannot pass under a rule that can never read clean]',
            ];
        }

        $count = @preg_match_all($pattern, $text, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if ($count === false) {
            return [
                'kind' => self::VERDICT_INVALID,
                'reason' => "verdict scan failed closed: reject pattern '{$pattern}' is not a valid PCRE: "
                    . TaskBuilder::pcreErrorText() . ' [the stage cannot pass under a rule that never ran]',
            ];
        }

        if ($count < $atLeast) {
            return ['kind' => self::VERDICT_CLEAN, 'reason' => ''];
        }

        $line = self::matchedLine($text, (int) ($hits[0][0][1] ?? 0));
        $attempts = $attemptsUsed > 1 ? " [failed on all {$attemptsUsed} attempts]" : '';
        // The threshold clause rides only the multi-occurrence form; the
        // default-1 reason stays byte-identical to the match-once wording.
        $threshold = $atLeast > 1 ? " {$count} of at least {$atLeast} matches" : '';

        return [
            'kind' => self::VERDICT_REJECTED,
            'reason' => "verdict rejected: verifier output matched {$pattern}{$threshold} on: \"{$line}\"{$attempts}",
        ];
    }

    /**
     * The control-char-free, length-clipped line of $output that contains
     * the match at $offset — safe to embed in a one-line stage error.
     */
    private static function matchedLine(string $text, int $offset): string
    {
        $lineStart = strrpos(substr($text, 0, max(0, $offset)), "\n");
        $rest = substr($text, $lineStart === false ? 0 : $lineStart + 1);
        $newline = strpos($rest, "\n");
        $line = $newline === false ? $rest : substr($rest, 0, $newline);

        // Control bytes (including the CR of CRLF pairs and terminal escapes'
        // own ESC) would corrupt the error line; they read as spaces here.
        $line = trim((string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $line));
        $line = str_replace('"', '\\"', $line);

        if (mb_strlen($line) > 160) {
            $line = mb_substr($line, 0, 157) . '...';
        }

        return $line === '' ? '(blank line)' : $line;
    }

    /**
     * Interpolate {{variable}}, {{name.results}}, {{name.<pointer>}}, and
     * {{stageName.output}} tokens in a string.
     *
     * - `{{item}}`, `{{item.<pointer>}}` and `{{index}}` (P1-1) resolve first,
     *   and only on a `from:` fan-out item, from
     *   {@see FAN_OUT_ITEM_CONTEXT_KEY}: the item (a string verbatim, any
     *   other value as compact JSON), an RFC 6901 leaf of it (same
     *   {@see JsonPointer} rules; a string verbatim, a scalar in its
     *   interpolated spelling, a container as compact JSON, a miss left
     *   verbatim) and its 0-based position. They are spliced through
     *   placeholders AFTER the other passes, so item data — which is usually
     *   model output — is never itself re-scanned for tokens, and the item
     *   wins over a context key or result named `item`/`index`.
     * - `{{name.results}}` is replaced with one agent's output, from the
     *   {@see RESULTS_CONTEXT_KEY} map ({@see resultKey()} names its entries).
     * - `{{name.<pointer>}}` addresses the agent's typed JSON row (the
     *   {@see TYPED_RESULTS_CONTEXT_KEY} map) with an RFC 6901 pointer, so
     *   `{{build.summary}}` and `{{build.items/0/id}}` reach document
     *   leaves. The reserved tails `results` and `output` are not
     *   pointers — they belong to the dedicated forms below and are left
     *   for them, whichever way the run() ordering resolves.
     * - `{{stageName.output}}` is replaced with the output of a prior stage result.
     * - `{{variable}}` is replaced with $context['variable'].
     *
     * Substitution runs in that order, and every pass re-scans what the
     * pass before it emitted: a typed leaf containing `{{stage.output}}`
     * is therefore resolved by the stage pass that follows, while an
     * emitted `{{name.results}}` (which had its pass already) survives
     * verbatim.
     *
     * An unresolved token is left as written. A bare `{{variable}}` whose
     * context value is an ARRAY (or a JSON-serializable object) renders as
     * compact JSON (P1-1: `context` may carry real lists now, and a prompt
     * that names one wants its contents, not the raw braces). Any other
     * non-string, non-number value — a bool, null, a resource — is still
     * left verbatim: a `runFromPhp()` caller can put anything in the
     * context, and a value reaching the replacement used to be a TypeError
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

        $typedRows = $context[self::TYPED_RESULTS_CONTEXT_KEY] ?? [];
        $typedRows = is_array($typedRows) ? $typedRows : [];

        // P1-1: lift the item tokens out before any other pass sees them.
        $spliced = [];
        $fanOut = $context[self::FAN_OUT_ITEM_CONTEXT_KEY] ?? null;
        if (is_array($fanOut) && array_key_exists('item', $fanOut)) {
            $nonce = "\x00" . bin2hex(random_bytes(6)) . ':';
            $text = (string) preg_replace_callback(
                '/\{\{(?:item(?:\.([A-Za-z0-9_~\/.\-]+))?|index)\}\}/',
                static function (array $matches) use ($fanOut, $nonce, &$spliced): string {
                    if ($matches[0] === '{{index}}') {
                        $value = (string) (int) ($fanOut['index'] ?? 0);
                    } elseif (($matches[1] ?? '') === '') {
                        $value = self::itemText($fanOut['item']);
                    } else {
                        $hit = JsonPointer::resolve($fanOut['item'], $matches[1]);
                        if (!$hit['found']) {
                            return $matches[0];
                        }
                        $value = self::itemText($hit['value']);
                    }
                    $placeholder = $nonce . count($spliced) . "\x00";
                    $spliced[$placeholder] = $value;

                    return $placeholder;
                },
                $text,
            );
        }

        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\.results\}\}/',
            static fn (array $matches): string => self::interpolatable($results[$matches[1]] ?? null) ?? $matches[0],
            $text
        );

        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\.([A-Za-z0-9_~\/.\-]+)\}\}/',
            static function (array $matches) use ($typedRows, $context): string {
                if ($matches[2] === 'results' || $matches[2] === 'output') {
                    return $matches[0];
                }
                // P1-3: `{{<stage>.failed}}` reads a minSuccess stage's
                // casualty list; only a stage that recorded one claims the
                // tail, so a typed row's own `failed` leaf still resolves.
                if ($matches[2] === 'failed' && is_string($context[$matches[1] . '.failed'] ?? null)) {
                    return $context[$matches[1] . '.failed'];
                }
                // P1-4: `{{<stage>.votes}}` reads a verifier panel's votes
                // the same way — only a stage that convened one claims it.
                if ($matches[2] === 'votes' && is_string($context[$matches[1] . '.votes'] ?? null)) {
                    return $context[$matches[1] . '.votes'];
                }
                $typed = $typedRows[$matches[1]] ?? null;
                if (!is_string($typed)) {
                    return $matches[0];
                }
                $leaf = self::jsonPointerValue($typed, $matches[2]);
                if (is_bool($leaf)) {
                    return $leaf ? 'true' : 'false';
                }

                return self::interpolatable($leaf) ?? $matches[0];
            },
            $text
        );

        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\.output\}\}/',
            static fn (array $matches): string => self::interpolatable($context[$matches[1] . '.output'] ?? null) ?? $matches[0],
            $text
        );

        $text = preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/',
            static fn (array $matches): string => self::interpolatable($context[$matches[1]] ?? null)
                ?? self::contextJson($context[$matches[1]] ?? null)
                ?? $matches[0],
            $text
        );

        return $spliced === [] ? $text : strtr($text, $spliced);
    }

    /** $value as interpolated text, or null when it is not text a prompt can carry. */
    private static function interpolatable(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * P1-1: an array (or JSON-serializable object) context value as compact
     * JSON — the one spelling a list handed in as `context` takes in a
     * prompt. Null for anything else, so the caller leaves the token as is.
     */
    private static function contextJson(mixed $value): ?string
    {
        if (!is_array($value) && !$value instanceof \JsonSerializable && !$value instanceof \stdClass) {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return is_string($json) ? $json : null;
    }

    /**
     * P1-1: a fan-out item (or a leaf of one) as prompt text — a string
     * verbatim, a bool as `true`/`false`, a number in its PHP string form
     * (what `{{stage.<pointer>}}` splices), anything else (containers, JSON
     * null) as compact JSON.
     */
    private static function itemText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return is_string($json) ? $json : '';
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
     * A record from a schema'd stage additionally stores its decoded
     * payload, re-encoded as a JSON string in the separate
     * {@see TYPED_RESULTS_CONTEXT_KEY} map keyed by the same result name,
     * so `{{name.<pointer>}}` can address leaves of the document. The text
     * row is written first and byte-identical either way, and the two maps
     * never share a slot: a dotted result name (fan-out tasks are named by
     * relative path) cannot impersonate or clobber another task's typed
     * row. A stage whose output the schema could not constrain (no schema,
     * or never valid JSON) simply gains no typed row, and pointer
     * references to it stay verbatim.
     *
     * @param array<array-key, mixed> $context
     * @param list<array{key: string, result: AgentResult, schema: bool}> $records
     * @return array<array-key, mixed>
     */
    private static function withResults(array $context, array $records): array
    {
        if ($records === []) {
            return $context;
        }

        $results = $context[self::RESULTS_CONTEXT_KEY] ?? [];
        $results = is_array($results) ? $results : [];
        $typed = $context[self::TYPED_RESULTS_CONTEXT_KEY] ?? [];
        $typed = is_array($typed) ? $typed : [];
        foreach ($records as $record) {
            $text = $record['result']->output ?? '';
            $results[$record['key']] = $text;
            if (!$record['schema']) {
                continue;
            }
            $decoded = json_decode($text, true);
            if (!is_array($decoded)) {
                continue;
            }
            $encoded = json_encode($decoded);
            if (is_string($encoded)) {
                $typed[$record['key']] = $encoded;
            }
        }
        $context[self::RESULTS_CONTEXT_KEY] = $results;
        if ($typed !== []) {
            $context[self::TYPED_RESULTS_CONTEXT_KEY] = $typed;
        }

        return $context;
    }

    /**
     * RFC 6901 JSON pointer lookup into a {@see TYPED_RESULTS_CONTEXT_KEY} row,
     * through the ONE resolver ({@see JsonPointer}) that `when:`/`until:`
     * pointer leaves, `from: {stage, pointer}` and `loop.accumulate` share,
     * so the four can never disagree about what a pointer addresses.
     *
     * Returns null for any miss — a missing member, an interior object or
     * array (only scalars interpolate), a null leaf, or a row that is not
     * decodable JSON — so the caller leaves the `{{name.pointer}}` reference
     * verbatim and the typo shows.
     *
     * @return string|int|float|bool|null the interpolatable leaf, or null on miss
     */
    private static function jsonPointerValue(string $typedRow, string $pointer): string|int|float|bool|null
    {
        if (!is_array(json_decode($typedRow, true))) {
            return null;
        }
        $hit = JsonPointer::resolveJson($typedRow, $pointer);

        return $hit['found'] && is_scalar($hit['value']) ? $hit['value'] : null;
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
     * @param array<string, array{key: string, schema: bool}> $resultKeys   SubAgent id => result name + schema flag, in declaration order
     * @param list<AgentResult> $agentResults
     * @return list<array{key: string, result: AgentResult, schema: bool}>
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
        foreach ($resultKeys as $id => $entry) {
            $agentResult = $byId[$id] ?? array_shift($unmatched);
            if ($agentResult !== null) {
                $records[] = ['key' => $entry['key'], 'result' => $agentResult, 'schema' => $entry['schema']];
            }
        }

        return $records;
    }

    /**
     * P1-3: every DECLARED agent of a parallel stage paired with its result,
     * or null when the pool never produced one (cancelled before it ran) —
     * matched by SubAgent id exactly as {@see recordsInDeclarationOrder()}
     * matches, so a casualty is named the way `{{name.results}}` names it.
     *
     * @param array<string, array{key: string, schema: bool}> $resultKeys
     * @param list<AgentResult> $agentResults
     * @return list<array{0: string, 1: ?AgentResult}>
     */
    private static function declaredOutcomes(array $resultKeys, array $agentResults): array
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

        $outcomes = [];
        foreach ($resultKeys as $id => $entry) {
            $outcomes[] = [$entry['key'], $byId[$id] ?? array_shift($unmatched)];
        }

        return $outcomes;
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
     *    {@see \SugarCraft\Code\Tests\Support\ForkedChildExitConventionTest}.)
     *    The cost is that the child now dies by signal, so a parent reading
     *    its wait status sees `wifsignaled()`/SIGKILL rather than exit code
     *    130/143; {@see AgentWorkerPool::workerDiedResult()} already reports
     *    that shape ("was killed by signal 9") and no in-repo caller branches
     *    on the code.
     *
     *  - THE INSTALLING PROCESS keeps a plain `exit()`, and MUST. It is not a
     *    fork: {@see \SugarCraft\Code\Chat::driveWorkflowFiber()} resumes
     *    `run()` inside a \Fiber on the live TUI's own ReactPHP loop, so the
     *    process that installs this handler is the process holding the
     *    raw-mode terminal. sugar-core's `PosixBackend::restore()` is
     *    PID-aware and THIS pid is the owner, so the plain exit's destructor
     *    chain is what puts the user's terminal back into cooked mode after
     *    Ctrl-C; a SIGKILL here would leave them typing blind. It is also
     *    what runs {@see \SugarCraft\Code\Cli\Bootstrap}'s
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
                $this->writePauseFile($interruptId, $partialResult, $loadPath, true);
            } catch (\Throwable) {
                // Best-effort: still exit below even if pause() itself
                // couldn't write (e.g. unwritable pause dir) — resuming
                // execution as though the signal never arrived would be
                // worse than exiting with nothing captured.
            }

            // DELIBERATELY a plain exit() and not ForkedChild::exitNow():
            // this branch only ever runs in the process that installed the
            // handler, which is the live TUI/CLI process itself. Its
            // shutdown sequence is load-bearing here — what hangs off it,
            // named exactly (v2 F-1(a)): sugar-core's PID-aware
            // PosixBackend::restore() puts termios back into cooked mode via
            // the destructor chain, and Bootstrap's MCP-server stop hook runs
            // as a register_shutdown_function callback. The DEC private-mode
            // releases (kitty keyboard pop, mouse off, alt-screen leave,
            // cursor show) are sugar-core's own: bin arms the library's
            // shutdown backstop before run() (armTerminalRelease — X-CORE1 /
            // OD-17 retired bin's hand-rolled $terminalWasRun guard, which
            // this comment used to credit), so this exit() still restores
            // through the single authoritative release path. See
            // installInterruptHandlers()'s doc-block.
            $pauseFilePath = null;
            try {
                $pauseFilePath = $this->pauseFileFor($interruptId);
            } catch (\Throwable) {
                // The path is a courtesy for the observer; the record write
                // above is what matters, and its absence must not change
                // the exit — or block it.
            }
            if ($this->signalPauseHandler !== null) {
                // A caller that owns the process lifecycle (the headless CLI
                // verb, W-1/D4) decides the exit code — and may emit its
                // machine document to stdout first. Plain exit() semantics
                // below are unchanged for every other caller.
                exit((int) ($this->signalPauseHandler)($partialResult, $pauseFilePath));
            }

            exit($signo === \SIGINT ? 130 : 143);
        };

        pcntl_signal(\SIGINT, $handler);
        pcntl_signal(\SIGTERM, $handler);

        return $previousAsyncSignals;
    }

    /**
     * Install (or clear with null) the observer the SIGINT/SIGTERM handler
     * consults on a cooperative pause — see {@see self::$signalPauseHandler}.
     * Call BEFORE the run that may be signalled; the handler reads the
     * property at signal time.
     *
     * @param (\Closure(WorkflowResult, ?string): int)|null $observer
     */
    public function setSignalPauseHandler(?\Closure $observer): void
    {
        $this->signalPauseHandler = $observer;
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
     * caller that matters does: sugar-core's `Program` installs a SIGINT closure
     * that flips `$running = false` and stops the loop, which is how a
     * `bin/candy-code` session shuts down gracefully and how PHP gets to run its
     * shutdown sequence at all — `SugarCraft\Core\Util\Tty\PosixBackend` puts
     * termios back from its DESTRUCTOR, which a process killed under SIG_DFL
     * never reaches. Since {@see \SugarCraft\Code\Chat} dispatches `/workflow run`
     * synchronously inside `Chat::update()`, resetting to the default here left
     * every session that ran one workflow dying on an external `kill -INT` with
     * the terminal still in raw mode inside the alt screen. Nothing in the TUI
     * noticed, because the raw mode it runs under clears ISIG and an
     * interactive Ctrl+C therefore arrives as a byte rather than as a signal at
     * all: `PosixBackend` delegates to sugar-pty's `TermiosFactory`, whose two
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

    /**
     * C6 lifecycle announces. Every one early-returns UNARMED (no
     * dispatcher), so an engine constructed without {@see $hookDispatcher}
     * cannot differ from a pre-C6 engine by a single observable byte — the
     * pin lives in WorkflowStageHookEventsTest. Dispatch is inline and
     * synchronous (E646-clean: no timers, no budgets); the verdict is
     * discarded by design — these events are observe-only, and the only
     * consumer today (the checkpoint-commit recipe in docs/HOOKS.md) acts on
     * the announce itself, never on a refusal.
     *
     * Each named helper is invoked from exactly ONE call site (the DocFigure
     * events-table arm derives its citation census from these), so the four
     * sites below are the whole lifecycle surface: the do-head round, the
     * C2 skip door, the post-record settle, and the runFromWorkflow choke.
     */
    private function announceStageStarted(string $workflowId, string $stageName, int $round): void
    {
        if ($this->hookDispatcher === null) {
            return;
        }

        $this->hookDispatcher->dispatchStageStarted(
            $this->lifecycleHookContext($workflowId, ['stage' => $stageName, 'round' => $round]),
        );
    }

    private function announceStageComplete(string $workflowId, string $stageName, string $status, int $round): void
    {
        if ($this->hookDispatcher === null) {
            return;
        }

        $this->hookDispatcher->dispatchStageComplete(
            $this->lifecycleHookContext($workflowId, [
                'stage' => $stageName,
                'status' => $status,
                'round' => $round,
            ]),
        );
    }

    private function announceStageFail(string $workflowId, string $stageName, string $status, int $round, string $error): void
    {
        if ($this->hookDispatcher === null) {
            return;
        }

        $this->hookDispatcher->dispatchStageFail(
            $this->lifecycleHookContext($workflowId, [
                'stage' => $stageName,
                'status' => $status,
                'round' => $round,
                'error' => $error,
            ]),
        );
    }

    /**
     * The run-terminal adjudication, pinned in both polarities: the natural
     * terminals (`completed`, `failed` — a pre-flight refusal included, which
     * is a terminal that dispatched nothing) announce; `paused` and
     * `cancelled` do NOT, because both are resumable and announcing here
     * would double-count the completion when the resumed run reaches its own
     * natural terminal. Exceptions never reach this method — the choke calls
     * it only with a RETURNED result, so a crashed run announces nothing.
     */
    private function announceWorkflowCompleted(WorkflowResult $result): void
    {
        if ($this->hookDispatcher === null) {
            return;
        }

        if ($result->status !== WorkflowStatus::Completed && $result->status !== WorkflowStatus::Failed) {
            return;
        }

        $this->hookDispatcher->dispatchWorkflowComplete(
            $this->lifecycleHookContext($result->workflowId, [
                'status' => $result->status->value,
                'stages' => count($result->stageResults),
                'totalTokens' => $result->totalTokens,
                'totalCost' => $result->totalCost,
            ]),
        );
    }

    /**
     * TaskList's board-event shape (docs/HOOKS.md, *The team events*): a
     * fixed `toolName` token is what entry matchers filter on, the payload
     * rides `toolArgs` and its JSON mirror in `toolInput`. `sessionId`
     * carries the workflow run id — the same role teamId plays for boards.
     *
     * @param array<string,mixed> $payload
     */
    private function lifecycleHookContext(string $workflowId, array $payload): HookContext
    {
        $payload = ['workflow' => $workflowId] + $payload;

        return new HookContext(
            sessionId: $workflowId,
            toolName: 'Workflow',
            toolArgs: $payload,
            toolInput: json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            toolOutput: '',
            model: $this->model,
            provider: $this->provider,
            projectRoot: $this->environmentRoot ?? (getcwd() ?: ''),
        );
    }
}
