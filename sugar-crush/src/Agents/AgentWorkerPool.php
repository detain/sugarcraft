<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * Worker pool for parallel agent execution.
 *
 * Manages concurrent agent execution with a configurable max limit (default 5),
 * returning results as they complete via a Generator. Coordinates execution through
 * an ExecutorInterface which handles the actual spawning mechanism.
 *
 * Mirrors charmbracelet/charmcrush AgentWorkerPool implementation.
 */
final class AgentWorkerPool
{
    /**
     * @var array<string, SubAgent> Currently executing agents, keyed by agent id
     *
     * EXACTLY one entry per in-flight agent. executeAll()'s outer loop runs
     * until this drains, so any entry that no completion path can remove is a
     * permanent hang. A forked agent's child PID is tracked separately in
     * {@see $activePids} rather than by adding a second '__pid:<pid>:<id>' key
     * here: that second key made count() double-count (halving the effective
     * maxConcurrent) and, worse, left the two keys to be removed by two
     * different racing mechanisms — pcntl_wait() removed the pid key while only
     * a hasResult() poll that happened to land in the window between the child
     * writing its result file and the child actually dying could remove the
     * plain key. Lose that race (child killed, child crashed before writing, or
     * simply a poll that missed a short shutdown) and the plain key was
     * stranded in $active with no result file for hasResult() to ever match,
     * and executeAll() span forever.
     */
    private array $active = [];

    /**
     * @var array<string, int> agent id => forked child PID
     *
     * Only populated for agents started through pcntl_fork(); the synchronous
     * paths (injected executor, fork unavailable, fork failed) have no child to
     * reap and store their result inline.
     *
     * The key type is aspirational: PHP silently stores a numeric-string agent
     * id (executeAll() takes caller-supplied SubAgents, so '42' is legal) as an
     * int key, which is why every read of it casts back. See
     * {@see waitForCompletion()}.
     */
    private array $activePids = [];

    /** @var array<string, SubAgent> Queued agents */
    private array $queue = [];

    /** @var array<string, bool> Agent IDs marked for cancellation */
    private array $cancelled = [];

    /** @var bool When true, stop executing remaining agents on first failure */
    private bool $stopOnFirstFailure = false;

    /** @var ExecutorInterface|null The executor used for agent invocations */
    private ?ExecutorInterface $executor = null;

    /** True when a custom executor was injected (not the default ProcessExecutor). */
    private readonly bool $customExecutor;

    /** True when cancelAll() was called; cleared at the start of each executeAll(). */
    private bool $wasCancelledByUser = false;

    /** Poll backoff for waitForCompletion() when nothing has completed yet (microseconds). */
    private const WAIT_POLL_INTERVAL_USEC = 5_000;

    /**
     * {@see reapTerminatedWorkers()}'s bounded WNOHANG window: 20 attempts x
     * 5ms is a 100ms ceiling on how long a teardown may sit reaping, shared
     * across every worker it just signalled. Deliberately the same pair of
     * numbers as {@see \SugarCraft\Crush\Backend\EngineBackend}'s
     * REAP_ATTEMPTS/REAP_POLL_MICROSECONDS, which exists for the same reason:
     * a signalled child is normally collected on the first attempt or two, and
     * the budget only bounds the cost of one that is slow to die.
     */
    private const REAP_ATTEMPTS = 20;

    private const REAP_POLL_MICROSECONDS = 5_000;

    /**
     * @var array<int, true> PIDs this pool signalled but has not yet confirmed
     * reaped, swept opportunistically at the very top of the next executeAll()
     * and drained for good by {@see __destruct()}.
     *
     * The sweep sits ABOVE executeAll()'s early returns, not below them:
     * cancelAll() is the main producer of entries here and it also SETS
     * wasCancelledByUser, so the very next executeAll() always takes the first
     * early return — a sweep placed after it could never, by construction, see
     * the children cancelAll() had just run out of budget on.
     *
     * cancelAll() and an abandoned generator (a caller that breaks out of the
     * executeAll() foreach — note that WorkflowEngine does NOT: it drains the
     * generator in full, and stopOnFirstFailure is handled inside executeAll()
     * by emptying the queue) both tear the run down while children are still
     * alive; nothing polls afterwards, so without this list every such teardown
     * left a permanent zombie. Mirrors
     * {@see \SugarCraft\Crush\Backend\EngineBackend::$unreapedChildren}: a
     * tracked list rather than a blanket `pcntl_waitpid(-1, ...)`, because this
     * process contains other components (EngineBackend, Chat's parallel tool
     * calls, BackgroundSessionRunner, the executor's own proc_open()ed workers)
     * that wait on their OWN pids and check the returned pid — a blind sweep
     * would steal their exit statuses.
     */
    private array $unreapedChildren = [];

    /** True once the sequential-fallback warning has been logged for this pool instance. */
    private bool $sequentialFallbackWarned = false;

    /** @var bool Whether {@see warnForkFailed()} has already fired on this pool. */
    private bool $forkFailureWarned = false;

    /**
     * @var bool Force {@see forkProcess()} to report failure, for tests.
     *
     * The sibling of {@see $forcePcntlUnavailableForTesting} and set the same
     * way — by Reflection, because this class is `final`. A fork that FAILS
     * cannot be provoked honestly: it needs `RLIMIT_NPROC` exhausted or the
     * machine out of memory, and a test that arranged either would take the
     * whole box down with it rather than just this arm.
     */
    private bool $forceForkFailureForTesting = false;

    /**
     * @internal Test-only seam. When non-null, overrides pcntlForkAvailable()
     * so the sequential-fallback path can be exercised deterministically via
     * Reflection, without requiring an environment that actually lacks pcntl.
     */
    private ?bool $forcePcntlUnavailableForTesting = null;

    /**
     * Absolute path to this pool instance's private IPC directory.
     *
     * Result files used to live directly in sys_get_temp_dir() under a name
     * derived only from the agent id, so two processes (or two pools) running
     * the same agent id read and clobbered each other's results — exactly what
     * happened when sibling git worktrees ran their suites concurrently and
     * produced an intermittent FanOutResearchTest failure. It was also a
     * predictable path in a world-writable directory, i.e. a symlink-attack
     * target. One unpredictable 0700 directory per pool instance fixes both.
     */
    private string $resultDir;

    /**
     * PID that created $resultDir. Forked children inherit this object and run
     * destructors on exit(), so only the creating process may delete the
     * directory — otherwise a child would erase the very result file the
     * parent is still waiting to read.
     */
    private int $resultDirOwnerPid;

    public function __construct(
        private readonly int $maxConcurrent = 5,
        ?ExecutorInterface $executor = null,
    ) {
        $this->executor = $executor;
        $this->customExecutor = $executor !== null;
        // The path is fixed at construction (not lazily on first write) because
        // forked children must derive the SAME path the parent polls; a lazily
        // randomised path would differ per process and hang the pool.
        $this->resultDir = self::makeResultDirPath();
        $this->resultDirOwnerPid = (int) getmypid();
    }

    /**
     * A clone (see withStopOnFirstFailure()) gets its own IPC directory so the
     * two instances never observe each other's results and neither destructor
     * removes files the other still owns.
     *
     * The child bookkeeping is dropped for the same reason, and it matters more
     * now that cancelAll() and __destruct() both SIGTERM and reap whatever
     * $activePids holds: a clone did not fork those children, so inheriting
     * them lets it kill and collect processes the ORIGINAL is still waiting on.
     * The original's waitForCompletion() then sees -1 for every one and settles
     * each agent Failed-status-unknowable, throwing away results that were
     * about to land. $unreapedChildren is cleared for the mirror reason — the
     * original's deferred sweep is the only thing entitled to collect them, and
     * two sweeps racing for the same pid is exactly the stolen-exit-status
     * hazard the tracked-list design exists to avoid. The clone also has a
     * fresh $resultDir the inherited children would never write into, so
     * carrying them over could not have worked in any case.
     */
    public function __clone(): void
    {
        $this->resultDir = self::makeResultDirPath();
        $this->resultDirOwnerPid = (int) getmypid();
        $this->activePids = [];
        $this->unreapedChildren = [];
    }

    /**
     * Stop and collect this pool's forked workers, then remove its private IPC
     * directory — if this process created it.
     *
     * The teardown is here and not only in executeAll()'s reset because the
     * reset can physically never run for the pools that need it most: both
     * in-repo call sites build a pool, run it once, and drop it
     * (WorkflowEngine::executeParallelStage() constructs a fresh pool per
     * stage; Chat::executeAgents() does the same from AgentPoolConfig). A
     * caller that takes the \Generator those return and breaks out of it after
     * the first result leaves live children behind, and the pool then goes out
     * of scope — so without this the rmdir below would delete $resultDir out
     * from under children still trying to write into it, and nobody would ever
     * reap them: N zombies plus N orphaned `php -r` grandchildren for the life
     * of a TUI process that runs for hours.
     *
     * Safe in a destructor, and the try/catch is what makes that claim
     * unconditional rather than merely true of the two helpers. Neither of them
     * throws on its own (posix_kill is silenced, pcntl_waitpid is always
     * WNOHANG, and with nothing outstanding both are a single empty foreach) —
     * but "this helper does not throw" is not the same claim as "nothing throws
     * out of this destructor". The reap spends up to 100ms in usleep(), and
     * pcntl_async_signals(true) is unconditionally on in BOTH contexts a pool is
     * ever built in (candy-core's Program run loop and WorkflowEngine::run()),
     * so any signal the host process handles in userland is delivered inside
     * that window and a handler that throws would surface its exception right
     * here. An exception leaving a destructor is an uncatchable fatal, and
     * during shutdown there is nothing left that could handle it anyway, so it
     * is swallowed: the teardown is best-effort cleanup with no caller to report
     * to. (No in-repo handler throws today — Program's SIGINT/SIGWINCH/SIGTSTP/
     * SIGCONT set state or send() a Msg, WorkflowEngine's SIGINT/SIGTERM already
     * catches \Throwable before exit(), candy-pty's SignalForwarder forwards —
     * so this widens a window the pre-fix glob/@unlink loop already had, rather
     * than admitting a new class of hazard.)
     *
     * It runs AFTER the owner-pid guard on purpose — a forked child inherits
     * this object and runs destructors on exit(), and it must neither signal its
     * siblings nor steal their exit statuses.
     */
    public function __destruct()
    {
        if ($this->resultDirOwnerPid !== (int) getmypid()) {
            return;
        }

        try {
            $this->releaseForkedWorkers();
            $this->reapTerminatedWorkers();
        } catch (\Throwable) {
            // Swallowed deliberately — see above.
        }

        if (!is_dir($this->resultDir)) {
            return;
        }

        foreach (glob($this->resultDir . '/*.result') ?: [] as $leftover) {
            @unlink($leftover);
        }
        foreach (glob($this->resultDir . '/*.progress') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($this->resultDir);
    }

    /**
     * Execute all agents, yielding results as they complete.
     *
     * @param SubAgent[] $agents
     *
     * For each agent, this method builds a per-agent CompleteRequest using the
     * agent's ->task field as the user message.  This ensures that parallel
     * stages where each agent has a distinct prompt work correctly — a future
     * executor that reads $request->messages directly (rather than using the
     * agent's task field) would still receive the correct per-agent prompt.
     *
     * The $request parameter supplies shared fields (model, tools, systemPrompt,
     * temperature, maxTokens) that are common across all agents in the pool.
     *
     * @return \Generator<AgentResult>
     */
    public function executeAll(array $agents, CompleteRequest $request): \Generator
    {
        // Straggler children an earlier teardown ran out of reap budget on are
        // collected BEFORE the early returns below, never after: cancelAll()
        // both produces those stragglers and sets wasCancelledByUser, so the
        // executeAll() immediately following a cancel always takes the first
        // early return — a sweep below it would let every cancelled run's
        // leftovers survive an extra generation by construction. One WNOHANG
        // syscall per tracked pid, and nothing at all when the list is empty.
        $this->sweepUnreapedChildren();

        // If cancelAll() was called before this executeAll(), cancel all pending
        // agents before they are even queued. This makes cancelAll() effective
        // when called before executeAll() iteration begins.
        if ($this->wasCancelledByUser) {
            $this->wasCancelledByUser = false;
            return;
        }

        if ($agents === []) {
            return;
        }

        $this->queue = [];
        foreach ($agents as $agent) {
            $this->queue[$agent->id] = $agent;
        }
        $this->active = [];
        // Any pid still tracked at this point belongs to an abandoned run — a
        // caller that broke out of the previous generator left its children
        // running. (No in-repo caller does: WorkflowEngine and Chat both drain
        // the generator in full, and both build a single-use pool, so this
        // reset covers REUSED pools only. The single-use case is covered by
        // __destruct().) Wiping the map alone would leave them alive AND
        // untracked, so hand them to the deferred sweep instead; the sweep
        // itself costs one WNOHANG syscall per straggler and buys back every
        // child the last teardown's bounded reap had to give up on.
        //
        // Bounded (100ms) like cancelAll()'s. The full 100ms is only reachable
        // while something is genuinely outstanding — either a child just
        // signalled here, or one still tracked in $unreapedChildren (which on a
        // build without ext-posix means a worker nobody could signal, running
        // out its full executor timeout and costing every executeAll() the
        // whole window until it dies). With nothing outstanding the first sweep
        // finds an empty list and returns at once.
        $this->releaseForkedWorkers();
        $this->reapTerminatedWorkers();
        $this->cancelled = [];

        $executor = $this->executor ?? $this->createDefaultExecutor();

        while ($this->queue !== [] || $this->active !== []) {
            // Fill up to maxConcurrent slots
            while (count($this->active) < $this->maxConcurrent && $this->queue !== []) {
                $agent = array_shift($this->queue);
                if ($agent === null) {
                    break;
                }

                if (isset($this->cancelled[$agent->id])) {
                    unset($this->cancelled[$agent->id]);
                    continue;
                }

                $this->active[$agent->id] = $agent;

                // Build a per-agent request from the agent's task field so that
                // each agent's executor receives its own prompt. This ensures
                // parallel stages with non-identical prompts work correctly —
                // a future executor that uses $request->messages directly (rather
                // than the agent's task field) would otherwise receive only the
                // first task's prompt for all agents.
                $agentRequest = new CompleteRequest(
                    model: $request->model,
                    messages: [
                        ['role' => 'user', 'content' => $agent->task],
                    ],
                    tools: $request->tools,
                    systemPrompt: $request->systemPrompt,
                    temperature: $request->temperature,
                    maxTokens: $request->maxTokens,
                );

                // Start agent — try forking if available, fall back to sync
                $this->startAgent($agent, $agentRequest, $executor);
            }

            if ($this->active === []) {
                break;
            }

            // Wait for at least one to complete
            $completedId = $this->waitForCompletion();
            if ($completedId === null) {
                continue;
            }

            $result = $this->extractResult($completedId);
            if ($result !== null) {
                yield $result;

                if ($this->stopOnFirstFailure && $result->isFailure()) {
                    foreach (array_keys($this->queue) as $queuedId) {
                        $this->cancelled[$queuedId] = true;
                    }
                    $this->queue = [];
                }
            }
        }
    }

    /**
     * Execute a single agent and return the result immediately.
     */
    public function executeOne(SubAgent $agent, CompleteRequest $request): AgentResult
    {
        $executor = $this->executor ?? $this->createDefaultExecutor();
        return $executor->execute($agent, $request);
    }

    /**
     * Number of agents currently executing.
     */
    public function getActiveCount(): int
    {
        return count($this->active);
    }

    /**
     * Number of agents waiting to execute.
     */
    public function getQueueSize(): int
    {
        return count($this->queue);
    }

    /**
     * Cancel a specific agent by ID.
     *
     * If the agent is queued, it is removed from the queue.
     * If the agent is running, its executor is signalled to cancel.
     */
    public function cancel(string $agentId): void
    {
        if (isset($this->queue[$agentId])) {
            unset($this->queue[$agentId]);
            return;
        }

        if (isset($this->active[$agentId])) {
            $this->cancelled[$agentId] = true;
            // Only set on the injected-executor path; on the default path the
            // executor lives inside the forked child, so the signal below is the
            // only cancellation channel the parent has.
            $this->executor?->cancel($agentId);
            $this->terminateWorker($agentId);
        }
    }

    /**
     * Cancel all pending and running agents.
     *
     * Sets an internal flag so that a subsequent executeAll() call that has
     * not yet dispatched any agents will return immediately without running
     * anything.
     */
    public function cancelAll(): void
    {
        $this->wasCancelledByUser = true;

        foreach (array_keys($this->queue) as $queuedId) {
            $this->cancelled[$queuedId] = true;
        }
        $this->queue = [];

        $this->executor?->cancelAll();

        // Signal every forked worker before dropping the bookkeeping — clearing
        // $active alone would orphan the children, leaving them running against
        // a pool nobody is reading any more.
        $this->releaseForkedWorkers();
        $this->active = [];

        // ...and then collect them. Nothing polls after cancelAll(), so a
        // signalled-but-unwaited child is a permanent zombie: one per worker,
        // in a TUI that lives for hours. Bounded (100ms total) so a child that
        // is slow to die costs a deferred reap rather than a frozen caller.
        $this->reapTerminatedWorkers();
    }

    /**
     * Configure stopOnFirstFailure behavior for this pool instance.
     *
     * Called by WorkflowEngine::executeParallelStage() when the workflow has
     * $stopOnFirstFailure=true.  The flag is checked inside executeAll() after
     * each agent result is collected to cancel remaining queued agents on failure.
     *
     * Implementation chain: WorkflowBuilder::stopOnFirstFailure() -> WorkflowEngine
     * reads Workflow::$stopOnFirstFailure -> calls withStopOnFirstFailure() here ->
     * executeAll() checks $this->stopOnFirstFailure when yielding results.
     *
     * NOTE: This is an extension beyond the base 6-method spec — not in the
     * charmbracelet/charmcrush AgentWorkerPool contract.
     */
    public function withStopOnFirstFailure(bool $stop): self
    {
        $clone = clone $this;
        $clone->stopOnFirstFailure = $stop;
        return $clone;
    }

    /**
     * Returns the configured concurrency limit.
     */
    public function getMaxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    /**
     * Returns the executor instance used by this pool.
     */
    public function getExecutor(): ?ExecutorInterface
    {
        return $this->executor;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    //
    // `protected`, NOT overridable. This banner used to say "overridable for
    // testing" and that was never true: the class is `final` (see the class
    // declaration), so no subclass can exist to override any of them, and
    // nothing in `src/`, `tests/` or `bin/` extends it. Thirteen methods sat
    // under a promise the type system forbids — five of them added by the
    // progress-publishing work without anyone re-reading the heading.
    //
    // `protected` rather than `private` is kept deliberately, for the one
    // thing it still buys: these are the seam a future non-final variant
    // would specialise, and demoting them to `private` would be a silent
    // narrowing of that option. Tests reach them through the public surface
    // or through reflection, never by subclassing. If you drop `final`, this
    // comment is what you have to update first.
    // -------------------------------------------------------------------------

    /**
     * Start an agent execution.
     *
     * When a custom executor is injected (e.g., for testing), runs synchronously
     * in the same process. When using the default ProcessExecutor, forks a child
     * process for true parallelism. The pool's concurrency management (dispatch
     * up to maxConcurrent, result collection, cancellation) is identical either way.
     *
     * ## FOUR DISPATCH PATHS, ONE OF WHICH IS LIVE
     *
     * Only the `pcntl_fork()` path publishes progress. It runs
     * {@see runStreaming()}, which mirrors each `Streaming` chunk into the
     * agent's progress file for {@see pumpProgress()} to pick up — the whole
     * mechanism behind the live split pane. The other three (an injected
     * `customExecutor`, a build without `pcntl`, and a failed `fork()`) call
     * `$executor->execute()` SYNCHRONOUSLY IN THE PARENT.
     *
     * Two consequences, and the second is the one that matters:
     *
     *  1. No progress is published, so `AgentManager::liveOutputs()` stays
     *     empty and the pane shows nothing for the agent.
     *  2. The parent is inside `execute()` for its whole duration, so the
     *     ReactPHP loop does not turn and {@see idle()} — the only
     *     `Fiber::suspend()` in `src/` — is never reached. Measured on the
     *     shipped workflow shapes: a parallel stage on the FORKING executor
     *     suspends ~111k times over a run; the same stage on an injected
     *     executor suspends ZERO times. So making the parent's `execute()`
     *     publish would write a file nothing gets a chance to read: there is
     *     no repaint to feed until the call returns, by which point
     *     `AgentManager::drain()` has settled the final text anyway.
     *
     * That is why this is documented rather than "fixed" by routing the
     * synchronous paths through `executeStream()`. Doing so would buy no
     * visible output while changing the interface method every injected
     * executor must implement — the pool's own tests inject mocks that stub
     * `execute()`. The blocking is the defect; publishing is not the fix for
     * it, and an asynchronous in-parent executor is its own change.
     *
     * USER-FACING because the README tells you to inject an
     * `ExecutorInterface` to reach a real model — which silently opts you out
     * of the live pane. Stated under *Limitations* there too, so the person
     * who follows that advice is told before they wonder.
     */
    protected function startAgent(SubAgent $agent, CompleteRequest $request, ExecutorInterface $executor): void
    {
        // Every dispatch starts from a clean progress file, whichever path it
        // then takes, and BEFORE the work rather than after it.
        //
        // A child that is killed or crashes never gets to tidy up, and an
        // agent id is stable across a retry — so a stale tail left by an
        // earlier dispatch of the same id on this pool would be mirrored by
        // the first pumpProgress() as THIS run's live output: a pane showing
        // the answer to a question that is no longer being asked. Clearing it
        // in the parent's post-fork branch instead would race the child's
        // first append.
        $this->discardProgress($agent->id);

        // If a custom executor was injected, run synchronously.
        // PHPUnit mocks do not survive pcntl_fork across process boundaries.
        // The agent stays in $this->active until waitForCompletion extracts its result.
        if ($this->customExecutor) {
            $result = $executor->execute($agent, $request);
            $this->storeResult($agent->id, $result);
            // Do NOT unset from active here — waitForCompletion handles removal
            return;
        }

        // Using the default ProcessExecutor — fork for true parallelism
        if (!$this->pcntlForkAvailable()) {
            $this->warnSequentialFallback();
            $result = $executor->execute($agent, $request);
            $this->storeResult($agent->id, $result);
            // Do NOT unset from active here — waitForCompletion's sync-result
            // check (hasResult()) handles removal, same as the customExecutor
            // path above. Removing it here instead would drop the agent from
            // $active before executeAll()'s outer loop ever calls
            // waitForCompletion()/extractResult() for it, silently discarding
            // the result it just stored.
            return;
        }

        $pid = $this->forkProcess();
        if ($pid === -1) {
            // Fork failed — execute synchronously. Same reasoning as above:
            // leave the agent in $active for waitForCompletion() to reap.
            $this->warnForkFailed();
            $result = $executor->execute($agent, $request);
            $this->storeResult($agent->id, $result);
            return;
        }

        if ($pid === 0) {
            // Child process: execute and store result, then exit. A plain
            // exit() is safe here (not ForkedChild::exitNow()) because the
            // one thing that made a bare exit() dangerous - an inherited
            // raw-mode Tty's destructor clobbering the real terminal on the
            // way out - is now fixed at the ROOT in candy-core's
            // PosixBackend::restore() (PID-aware; see #1406). This path is
            // also currently unreachable from bin/sugarcrush's live path
            // (Renderer.php's R20.fix docblock), so there is no live-TUI
            // scenario this protects that candy-core doesn't already cover.
            //
            // Nothing here catches: ProcessExecutor::checkBackpressure() throws
            // at >=80% memory and spawnWorker() throws when proc_open() fails,
            // both BEFORE any result is written. The child then fatals on the
            // uncaught exception and this agent's only completion evidence is
            // its exit status — no signal, no crash, just a child that ends
            // without a result file. That is the 100%-reproducible shape of the
            // hang waitForCompletion() now closes by settling on the reap:
            // whatever the child fails to say for itself, the parent reports
            // from the exit status.
            $this->storeResult($agent->id, $this->runStreaming($executor, $agent, $request));
            exit(0);
        }

        // Parent: remember which child owns this agent so waitForCompletion()
        // can reap exactly that PID. The agent already occupies its single
        // $active slot (added by executeAll() before dispatch) — adding a
        // second, differently-keyed entry here is what used to strand agents in
        // $active forever; see the $active docblock.
        $this->activePids[$agent->id] = $pid;

        // Dispatch time, so a worker that dies without writing a result still
        // yields a *timed* AgentResult (see workerDiedResult()). Only set when
        // nothing else has: SubAgent::$startedAt means "when execution actually
        // began", and AgentManager stamps it on the paths it drives itself.
        $agent->startedAt ??= new \DateTimeImmutable();
    }

    /**
     * Run one agent in the CHILD, publishing its partials as they arrive and
     * returning the terminal result.
     *
     * `executeStream()` rather than `execute()` because the two differ only in
     * whether the caller is told about the `Streaming` messages the worker was
     * already sending — `execute()` accumulates them into a local `$buffer`
     * that nothing outside that stack frame ever sees. Across a fork, "nothing
     * outside that stack frame" includes the entire parent process, which is
     * why a running sub-agent had no observable output at all.
     *
     * The LAST result wins: `ProcessExecutor::executeStream()` is documented to
     * end on a terminal one (complete, error, timeout, or a worker that died),
     * and `Streaming` results carry a chunk rather than an outcome. A generator
     * that somehow yielded nothing at all leaves the child with no result file,
     * which {@see waitForCompletion()} already reports from the exit status.
     */
    private function runStreaming(
        ExecutorInterface $executor,
        SubAgent $agent,
        CompleteRequest $request,
    ): AgentResult {
        $last = null;

        foreach ($executor->executeStream($agent, $request) as $result) {
            if ($result->status === AgentStatus::Streaming) {
                $this->publishProgress($agent->id, $result->output ?? '');
                continue;
            }

            $last = $result;
        }

        return $last ?? new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Failed,
            error: new \RuntimeException('Worker produced no terminal result'),
            startedAt: $agent->startedAt,
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Wait for at least one child process to complete.
     *
     * Returns the agent ID of the completed agent, or null if no agent
     * completed this cycle.
     */
    protected function waitForCompletion(): ?string
    {
        // Before the reap, so a child that finishes this cycle still has its
        // last partial mirrored — and so a caller that renders between polls
        // has something to render. See pumpProgress().
        $this->pumpProgress();

        // Forked agents settle on the reap, never on a result-file poll. Tying
        // removal to the child's exit is what makes this total: the child is
        // guaranteed to exit exactly once, whereas its result file may never
        // appear at all (crash, OOM, SIGKILL, cancellation), and a waiter that
        // needed that file to show up is precisely what hung the pool.
        if ($this->activePids !== [] && function_exists('pcntl_waitpid')) {
            foreach ($this->activePids as $agentId => $pid) {
                // PHP coerces numeric-string array keys to int, so an agent
                // whose caller-supplied id is '42' comes back out of this
                // foreach as int(42) — and every consumer below is typed
                // string, under declare(strict_types=1). Cast once, here.
                $agentId = (string) $agentId;

                $status = 0;
                // waitpid on OUR pid rather than pcntl_wait() for any child:
                // this pool runs inside a process that proc_open()s children of
                // its own (the executor's workers, tool calls, MCP servers), and
                // a bare pcntl_wait() would reap those too — stealing the exit
                // status their proc_close() is waiting on.
                $reaped = pcntl_waitpid($pid, $status, WNOHANG);
                if ($reaped === 0) {
                    // Still running — the only non-terminal answer.
                    continue;
                }

                // $reaped === -1 means the child is gone but unwaitable: some
                // other waiter got there first. A SIGCHLD handler set to SIG_IGN
                // has the kernel auto-reap every child (candy-pty's
                // SignalForwarder can install exactly that), and so does any
                // blanket wait() elsewhere in the process. Treating -1 as "not
                // yet" would strand this agent in $active with nothing left able
                // to remove it — the precise shape of permanent hang this waiter
                // exists to rule out — so it settles the agent too, with a
                // result that admits the exit status is unknowable.
                $agent = $this->active[$agentId] ?? null;
                unset($this->activePids[$agentId], $this->active[$agentId]);

                // The child left no readable result. Synthesize the failure
                // rather than returning an agent id that extractResult() will
                // decline: executeAll() yields one result per agent it
                // dispatched, and a silent drop here would report a killed
                // sub-agent as though it had never run.
                if (!$this->hasDecodableResult($agentId)) {
                    $this->storeResult(
                        $agentId,
                        $this->workerDiedResult($agentId, $reaped === $pid ? $status : null, $agent),
                    );
                }

                return $agentId;
            }
        }

        // Synchronous paths (injected executor, fork unavailable, fork failed)
        // wrote their result inline and have no child to reap.
        foreach ($this->active as $agentId => $agent) {
            if (isset($this->activePids[$agentId])) {
                continue;
            }

            if ($this->hasResult($agent->id)) {
                unset($this->active[$agentId]);
                return $agent->id;
            }
        }

        // Nothing completed this cycle — idle briefly before the caller polls
        // again. Without this, the WNOHANG reap above turns executeAll()'s outer
        // loop into a hot CPU spin while forked children are still running.
        $this->idle();

        return null;
    }

    /**
     * Give up the CPU for one poll interval — to the event loop when there is
     * one, to the kernel otherwise.
     *
     * ## Why this is the seam that unfroze the TUI
     *
     * This is the ONLY point at which the parent process is idle while agents
     * run: everything above it is a WNOHANG reap or a `file_exists()`, and the
     * blocking `stream_select()` is in the CHILD. So it is also the only place
     * a synchronous `$engine->run()` can hand control back to anything.
     *
     * {@see \SugarCraft\Crush\Chat::workflowRun()} runs the whole workflow
     * inside a `\Fiber` for exactly this reason. A fiber suspends its entire
     * call stack, however deep — `Chat` → `WorkflowEngine::run()` →
     * `executeParallelStage()` → `AgentManager::executeAll()` → here — and
     * resumes it where it stopped, which is what turns a call chain nobody was
     * going to rewrite into generators into a cooperatively scheduled one. The
     * `usleep()` it replaces is what the ReactPHP loop does with the interval
     * instead: repaint the frame.
     *
     * `Fiber::getCurrent()` rather than a constructor flag, because whether
     * suspending is legal is a property of the CALL, not of the pool: the same
     * pool instance is driven from a fiber by the TUI and from plain
     * synchronous code by `bin/` and by tests, and a fiber-less
     * `Fiber::suspend()` is a `FiberError`.
     *
     * A suspension is a yield, not a sleep: the driver resumes on its own
     * timer, so the poll interval is the driver's, not
     * {@see WAIT_POLL_INTERVAL_USEC}.
     */
    protected function idle(): void
    {
        if (\Fiber::getCurrent() !== null) {
            \Fiber::suspend();

            return;
        }

        usleep(self::WAIT_POLL_INTERVAL_USEC);
    }

    /**
     * Ask a forked worker to stop.
     *
     * SIGTERM, not SIGKILL — but NOT because the child runs a graceful
     * shutdown: it installs no SIGTERM handler, so the default disposition
     * applies and it dies without running destructors or shutdown functions,
     * exactly as it would under SIGKILL. (It could not usefully install one
     * either: a worker blocked in the executor's stream_select() never reaches
     * a pcntl_signal_dispatch() point, so a handler would only fire in the
     * moments the child was already between syscalls.) The reasons SIGTERM is
     * still the right signal are that it is the catchable one — anything the
     * child later grows, or any process that replaces it, gets the chance a
     * SIGKILL would deny — and that it is *distinguishable* in the wait status,
     * so {@see workerDiedResult()} can tell a user "killed by signal 15", i.e.
     * "you cancelled this", apart from a signal 9 the OOM killer sent.
     *
     * The one real casualty is the executor's own proc_open()ed `php -r`
     * grandchild: the child dies before proc_terminate() can run, so the
     * grandchild is orphaned. It self-exits within about a second on EPIPE when
     * its now-closed pipes are next written, which is why this is left alone
     * rather than papered over with a pid-tree walk.
     *
     * This only signals — it never touches $active/$activePids, so cancel()
     * leaves the agent in place for waitForCompletion() to reap, and that reap
     * is what settles it to a terminal Failed result. (cancelAll() drops the
     * bookkeeping itself, because it tears the whole run down and nothing polls
     * afterwards; it hands the pids to {@see $unreapedChildren} instead.)
     */
    private function terminateWorker(string $agentId): void
    {
        $pid = $this->activePids[$agentId] ?? null;
        if ($pid === null || !function_exists('posix_kill')) {
            return;
        }

        @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
    }

    /**
     * Move every tracked forked worker onto the deferred-reap list, after
     * asking it to stop.
     *
     * All three callers (cancelAll(), executeAll()'s reset of a previous run's
     * leftovers, and __destruct()) drop $activePids, which is the only record
     * the pool keeps of its children — so the signal and the hand-off have to
     * happen together or the children become unstoppable and unreapable in the
     * same statement.
     */
    private function releaseForkedWorkers(): void
    {
        foreach ($this->activePids as $agentId => $pid) {
            // Numeric-string agent ids come back out of this foreach as int;
            // terminateWorker() is typed string. See waitForCompletion().
            $this->terminateWorker((string) $agentId);
            $this->unreapedChildren[$pid] = true;
        }

        $this->activePids = [];
    }

    /**
     * Collect the children {@see releaseForkedWorkers()} just signalled, over a
     * bounded WNOHANG window shared by all of them.
     *
     * Never an unflagged pcntl_waitpid(): posix_kill() is guarded because
     * ext-posix is not guaranteed, and in exactly that build nothing signalled
     * the children at all — a blocking wait would then hang the caller on a
     * worker that is still happily running its 300-second timeout.
     */
    private function reapTerminatedWorkers(): void
    {
        for ($attempt = 0; $attempt < self::REAP_ATTEMPTS; $attempt++) {
            $this->sweepUnreapedChildren();
            if ($this->unreapedChildren === []) {
                return;
            }

            usleep(self::REAP_POLL_MICROSECONDS);
        }
    }

    /**
     * One non-blocking pass over the children an earlier teardown ran out of
     * budget on, so a straggler from run N is collected at run N+1 instead of
     * sitting as a zombie for the life of the process.
     *
     * Deliberately does not sleep or retry: anything still running here gets
     * looked at again next run. See {@see $unreapedChildren} for why this walks
     * a tracked list rather than calling pcntl_waitpid(-1, ...).
     */
    private function sweepUnreapedChildren(): void
    {
        if (!function_exists('pcntl_waitpid')) {
            return;
        }

        $status = 0;
        foreach (array_keys($this->unreapedChildren) as $pid) {
            // 0 means "still running, nothing reaped yet"; $pid means reaped;
            // -1 means unwaitable (already reaped, or never ours) — both of the
            // latter are terminal.
            if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                unset($this->unreapedChildren[$pid]);
            }
        }
    }

    /**
     * Whether a result file exists AND parses back into an AgentResult.
     *
     * {@see hasResult()} is file_exists(), which a child SIGKILLed between
     * file_put_contents()'s open(O_TRUNC) and its write() satisfies with a
     * 0-byte file. Gating the synthesis below on mere existence would then skip
     * it, extractResult() would return null, and executeAll() would yield
     * *nothing at all* for an agent it dispatched — the same "an agent settles
     * without a result" defect the synthesis exists to close, just through a
     * narrower window. Decoding is the only honest test of "a result arrived".
     *
     * Non-destructive on purpose: the caller's own extractResult() still needs
     * the file.
     */
    private function hasDecodableResult(string $agentId): bool
    {
        if (!$this->hasResult($agentId)) {
            return false;
        }

        $data = @file_get_contents($this->resultFile($agentId));
        if (!is_string($data) || $data === '') {
            return false;
        }

        $decoded = json_decode($data, true);

        return is_array($decoded) && $this->arrayToResult($decoded) !== null;
    }

    /**
     * Terminal result for a forked worker that exited without leaving a
     * readable one.
     *
     * The wait status is decoded into the message because "the sub-agent
     * produced nothing" and "the sub-agent was killed by SIGKILL" are very
     * different things to a user staring at a failed workflow stage. A null
     * $status means the child was reaped by someone else (see
     * waitForCompletion()), so no status was ever ours to read.
     *
     * completedAt is stamped because every other AgentResult in the library
     * carries one: {@see AgentManager::drain()} mirrors the pair onto the
     * SubAgent, and a terminal result with both timestamps null makes
     * {@see AgentResult::durationMs()} and SubAgent::elapsedSeconds() report a
     * flat 0s in the status strip and the dashboard. startedAt comes from the
     * SubAgent, stamped at dispatch by {@see startAgent()} — the reap is the
     * moment we learned the worker was dead, which is the most this path can
     * honestly claim about when it actually died.
     */
    private function workerDiedResult(string $agentId, ?int $status, ?SubAgent $agent = null): AgentResult
    {
        // No function_exists() guards on the pcntl_w*() family: the only caller
        // is inside a function_exists('pcntl_waitpid') branch, and pcntl is one
        // extension — if waitpid is loaded, so is the rest of it.
        if ($status === null) {
            $reason = 'is gone, but its exit status had already been collected by another '
                . 'waiter (a SIGCHLD handler, or a wait() elsewhere in this process), so how '
                . 'it died is unknowable';
        } elseif (pcntl_wifsignaled($status)) {
            $reason = 'was killed by signal ' . pcntl_wtermsig($status);
        } elseif (pcntl_wifexited($status)) {
            $reason = 'exited with code ' . pcntl_wexitstatus($status);
        } else {
            // Unreachable in practice — a WNOHANG wait without WUNTRACED only
            // ever returns an exited-or-signalled status — but a status neither
            // macro claims must still produce a terminal result rather than an
            // undefined $reason.
            $reason = 'exited abnormally';
        }

        return new AgentResult(
            agentId: $agentId,
            status: AgentStatus::Failed,
            output: null,
            error: new \RuntimeException(
                'AgentWorkerPool: worker process for agent ' . $agentId . ' ' . $reason
                . ' before writing a result.'
            ),
            startedAt: $agent?->startedAt,
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Store result to a temp file for inter-process communication.
     *
     * Uses JSON rather than serialize() — the result is a plain DTO (a status
     * enum, scalars, and an optional error message), so there is no need for
     * unserialize()'s arbitrary-class instantiation on the read side, which is
     * a latent security smell even when the file is one we wrote ourselves.
     *
     * If json_encode() still fails despite resultToArray() sanitizing
     * non-finite floats (belt-and-suspenders for any future field that isn't
     * JSON-safe), we must NOT skip the write: hasResult()/waitForCompletion()
     * key entirely off file_exists(), so a skipped write leaves the agent
     * stuck in $active forever and executeAll()'s outer loop never
     * terminates — a silent infinite hang, strictly worse than the busy-spin
     * this IPC mechanism replaced. Fall back to a minimal failure payload
     * (all-scalar, always JSON-safe) so the pool always makes progress.
     */
    protected function storeResult(string $agentId, AgentResult $result): void
    {
        $this->ensureResultDir();
        $file = $this->resultFile($agentId);
        $json = json_encode($this->resultToArray($result), JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = json_encode([
                'agentId' => $agentId,
                'status' => AgentStatus::Failed->value,
                'output' => null,
                'errorMessage' => 'AgentWorkerPool: failed to encode agent result to JSON for IPC.',
                'tokensUsed' => 0,
                'costUsd' => 0.0,
                'startedAt' => null,
                'completedAt' => null,
            ]);
        }

        file_put_contents($file, $json === false ? '' : $json);
    }

    /**
     * Check if a result file exists.
     */
    protected function hasResult(string $agentId): bool
    {
        return file_exists($this->resultFile($agentId));
    }

    /**
     * Read and delete the result file for a completed agent.
     */
    protected function extractResult(string $agentId): ?AgentResult
    {
        $file = $this->resultFile($agentId);
        if (!file_exists($file)) {
            return null;
        }

        $data = file_get_contents($file);
        @unlink($file);
        // The result supersedes every partial that led to it, and
        // AgentManager::drain() is about to write it onto the SubAgent.
        $this->discardProgress($agentId);

        if ($data === false || $data === '') {
            return null;
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->arrayToResult($decoded);
    }

    /**
     * Path to the temp result file for an agent, inside this pool instance's
     * private IPC directory.
     *
     * The agent id is hashed rather than passed through basename(): the id is
     * caller-supplied, and basename() was only ever incidental sanitisation —
     * it still lets through names that collide with siblings ('.result') or
     * that are not valid filenames. A hash is total, collision-free in
     * practice, and identical in the parent and in a forked child.
     */
    protected function resultFile(string $agentId): string
    {
        return $this->resultDir . '/' . hash('sha256', $agentId) . '.result';
    }

    /**
     * Path to the append-only file a forked worker streams its partial output
     * through, alongside {@see resultFile()} and in the same private dir.
     */
    protected function progressFile(string $agentId): string
    {
        return $this->resultDir . '/' . hash('sha256', $agentId) . '.progress';
    }

    /**
     * Append one chunk of a running agent's output. Called in the CHILD.
     *
     * Append-only and unbuffered, because the reader is a live TUI: the point
     * is that a partial answer is visible before the whole one exists. A
     * parent that reads mid-write sees a truncated tail, which is the correct
     * thing for a progress pane to show and is corrected by the next poll —
     * so no locking, whose cost would be paid on every chunk to prevent a
     * flicker.
     */
    protected function publishProgress(string $agentId, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $this->ensureResultDir();
        @file_put_contents($this->progressFile($agentId), $chunk, FILE_APPEND);
    }

    /**
     * Mirror every running agent's published progress onto the SubAgent this
     * pool holds. Called in the PARENT, once per poll.
     *
     * ## Why this exists at all
     *
     * `SubAgent::$output` had exactly one writer on the pool path:
     * {@see AgentManager::drain()}, which settles the FINAL text when a result
     * arrives — and sets the status terminal in the same breath. So a
     * pool-executed sub-agent was, at every instant of its life, either
     * running with an empty buffer or finished. Anything filtering for "is
     * producing text right now" — which is precisely what
     * {@see AgentManager::liveOutputs()} does, and through it the split-pane
     * compositor's activation policy — could therefore never see one, no
     * matter how long the agent ran or how much it said.
     *
     * That is why making `/workflow run` asynchronous was necessary but not
     * sufficient for the pane to appear: a frame that painted mid-run would
     * have had nothing to paint. This is the other half.
     *
     * The whole file is re-read rather than tailed from an offset: a live
     * agent's buffer is bounded by what it can say in one task, the read is
     * once per poll per RUNNING agent, and an offset would have to be
     * invalidated on every path that recycles an id.
     */
    protected function pumpProgress(): void
    {
        foreach ($this->activePids as $agentId => $_pid) {
            $agent = $this->active[(string) $agentId] ?? null;
            if ($agent === null || $agent->isComplete() || $agent->isStopped()) {
                continue;
            }

            $published = @file_get_contents($this->progressFile((string) $agentId));
            if ($published === false || $published === '' || $published === $agent->output) {
                continue;
            }

            $agent->output = $published;
            // Only now, and only for an agent that has actually said
            // something: `pending` is what settleAbandoned() distinguishes a
            // never-dispatched task by, and `streaming` is what every
            // liveness-aware reader means by "working".
            $agent->status = SubAgent::STATUS_STREAMING;
        }
    }

    /** Drop one agent's progress file; its result supersedes it. */
    protected function discardProgress(string $agentId): void
    {
        @unlink($this->progressFile($agentId));
    }

    /**
     * Build an unpredictable, process-qualified path for a pool's IPC directory.
     *
     * The random suffix is what defeats the symlink/pre-creation attack the old
     * fixed path invited; the PID keeps the name self-describing when a stale
     * directory has to be tracked down by hand.
     */
    private static function makeResultDirPath(): string
    {
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            // random_bytes() only fails when the CSPRNG is unavailable; a
            // unique-but-guessable name is still better than a shared one.
            $suffix = str_replace('.', '', uniqid('', true));
        }

        return sys_get_temp_dir() . '/sc_pool_' . getmypid() . '_' . $suffix;
    }

    /**
     * Create this pool's private IPC directory on first write.
     *
     * 0700 keeps agent output (which can contain anything the model produced)
     * out of other local users' reach and stops them planting symlinks for us
     * to write through. Called from storeResult() only — the read-side helpers
     * work off the path alone, so merely polling for a result never litters
     * temp with directories.
     */
    private function ensureResultDir(): void
    {
        if (is_dir($this->resultDir)) {
            return;
        }

        // Concurrent forked children race to create the shared directory; the
        // loser's mkdir() fails but the directory it needed now exists.
        if (!@mkdir($this->resultDir, 0700, true) && !is_dir($this->resultDir)) {
            throw new \RuntimeException(
                'AgentWorkerPool: could not create the IPC directory ' . $this->resultDir
            );
        }
    }

    /**
     * Whether pcntl_fork() is available in this process.
     *
     * Factored out (rather than calling function_exists('pcntl_fork') inline)
     * so a test can force the sequential-fallback path deterministically via
     * the forcePcntlUnavailableForTesting seam, without requiring a real
     * environment that lacks the pcntl extension.
     */
    protected function pcntlForkAvailable(): bool
    {
        if ($this->forcePcntlUnavailableForTesting !== null) {
            return !$this->forcePcntlUnavailableForTesting;
        }

        return function_exists('pcntl_fork');
    }

    /**
     * `pcntl_fork()`, behind a seam so the failure arm can be driven.
     *
     * Factored out for exactly the reason {@see pcntlForkAvailable()} was, and
     * with more need: "pcntl is missing" can at least be simulated by claiming
     * it is, whereas "the fork failed" is a kernel resource verdict. Without
     * this seam {@see startAgent()}'s `-1` branch was unreachable from any
     * test, which is a large part of how it went so long emitting nothing.
     *
     * @return int the child PID in the parent, 0 in the child, -1 on failure
     */
    private function forkProcess(): int
    {
        if ($this->forceForkFailureForTesting) {
            return -1;
        }

        return pcntl_fork();
    }

    /**
     * Report, once per pool, that a `pcntl_fork()` actually FAILED.
     *
     * A FAILED FORK AND AN ABSENT `pcntl` ARE DIFFERENT EVENTS, and until this
     * existed they were indistinguishable to the operator: both degrade to
     * sequential execution in the parent, and only one of them said so. The
     * distinction is the actionable part. A build without `pcntl` is a static
     * fact about the installation, fixed by installing an extension, and it
     * will be true for every pool in every run. A fork that returns -1 is a
     * runtime resource verdict — `EAGAIN` for `RLIMIT_NPROC`, `ENOMEM` for
     * memory — which may clear by itself, may be caused by this application's
     * own concurrency, and tells the operator to look at limits rather than at
     * packages. That is why the errno is named rather than merely the outcome.
     *
     * `error_log()` AND NOT THE MID-SESSION TRANSCRIPT SEAM, under the rule the
     * two tool-call parsers' class doc-blocks state — a notice reaches
     * {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::warn()} if and
     * only if the emitter did not produce what the caller asked for. The answer
     * is NO here, checked at the arm rather than assumed: {@see startAgent()}'s
     * `-1` branch falls straight through to `$executor->execute($agent,
     * $request)` and `storeResult()`, byte for byte what the
     * `pcntlForkAvailable()` arm above it does, so the agent still runs and its
     * result is still stored and reaped. What is lost is CONCURRENCY, which is
     * not an action the model can take and not a row worth re-sending to it on
     * every subsequent turn. Same answer as {@see warnSequentialFallback()},
     * same reasoning, different event.
     *
     * ONCE PER POOL, matching the sibling and for the sibling's reason: the
     * alternative is one line per dispatched agent, and a pool that has run out
     * of processes is precisely the one about to dispatch many. The cost is
     * that a fork failure which clears and later recurs is logged only the
     * first time; the count is not surfaced anywhere, which is recorded as a
     * finding rather than fixed here.
     */
    private function warnForkFailed(): void
    {
        if ($this->forkFailureWarned) {
            return;
        }

        $this->forkFailureWarned = true;

        // Guarded rather than called bare: this arm is reachable through the
        // testing seam on a build where `pcntl_fork()` itself was never called,
        // and `pcntl_strerror(0)` reports "Success", which is the one thing
        // this line must not say.
        $errno = \function_exists('pcntl_get_last_error') ? pcntl_get_last_error() : 0;

        error_log(sprintf(
            'AgentWorkerPool: pcntl_fork() FAILED (%s) — this agent, and any later one that '
            . 'hits the same failure, runs sequentially in the parent instead of concurrently. '
            . 'Unlike a build without pcntl this is a runtime resource limit and may clear on '
            . 'its own; if it does not, raise the process limit (RLIMIT_NPROC) or lower '
            . 'maxConcurrent, currently %d.',
            $errno === 0 ? 'no errno was reported' : pcntl_strerror($errno) . ' (errno ' . $errno . ')',
            $this->maxConcurrent,
        ));
    }

    /**
     * Log a visible warning the first time this pool falls back to
     * sequential (non-parallel) execution because pcntl_fork() is
     * unavailable. Only fires once per pool instance — subsequent agents
     * hitting the same fallback path would otherwise spam the log.
     *
     * DELIBERATELY STILL `error_log()` AND NOT THE MID-SESSION TRANSCRIPT SEAM
     * (E192), and this class was on E192's list of three emitters to route, so
     * the reason is recorded rather than left as an omission. The rule the two
     * tool-call parsers' class doc-blocks state — a notice goes to
     * {@see \SugarCraft\Crush\Diagnostics\RuntimeNoticeSink::warn()} if and
     * only if the emitter did not produce what the caller asked for — answers
     * NO here, and it is checkable at the one call site rather than a matter of
     * taste: {@see startAgent()}'s `pcntlForkAvailable()` arm falls straight
     * through to `$executor->execute($agent, $request)` and `storeResult()`,
     * so every agent still runs and every result is still stored and reaped.
     * THE CITATION WAS `executeOne()` AND THAT WAS WRONG — that method is two
     * lines, `$this->executor ?? $this->createDefaultExecutor()` then
     * `$executor->execute(…)`, and contains neither arm. A reader who followed
     * it would have found no evidence for the argument above and concluded the
     * reasoning was stale. Both fallback arms live in {@see startAgent()}.
     * What the caller loses is CONCURRENCY, not an action. A seam row is a
     * `Role::System` message re-sent to the model on every subsequent turn, and
     * "your agents ran one after another" is neither something the model can
     * act on nor something the user cannot infer from the wall clock.
     *
     * WHAT WOULD CHANGE THE ANSWER: an agent that does not run at all. Neither
     * arm does that, which is why neither is on the seam.
     *
     * WHAT THIS PARAGRAPH USED TO SAY: it pointed at "the DEFERRED FINDING
     * recorded against the `pcntl_fork() === -1` arm in {@see startAgent()},
     * which degrades to the same sequential execution and warns about NOTHING,
     * not even on stderr." WHAT IS TRUE NOW: that arm warns —
     * {@see warnForkFailed()} — and the finding is closed. WHY THE SENTENCE
     * STILL EARNS ITS PLACE: the two arms remain a matched pair that a reader
     * will compare, and the comparison is the argument. They reach the same
     * fallback by different causes, they answer the routing rule the same way
     * for the same reason, and their messages differ only where the operator's
     * remedy differs. A future change that moves one onto the seam and leaves
     * the other here has almost certainly got the rule wrong.
     */
    protected function warnSequentialFallback(): void
    {
        if ($this->sequentialFallbackWarned) {
            return;
        }

        $this->sequentialFallbackWarned = true;
        error_log(
            'AgentWorkerPool: pcntl_fork() is unavailable — falling back to '
            . 'sequential (non-parallel) agent execution. Install/enable the '
            . 'pcntl extension to restore concurrent agent execution.'
        );
    }

    /**
     * Convert an AgentResult to a JSON-safe plain array for IPC.
     *
     * The ?Throwable $error field cannot round-trip through JSON as-is, so
     * only its message survives — sufficient for isFailure() semantics and
     * for surfacing the failure reason, without resurrecting an arbitrary
     * exception class via unserialize().
     *
     * costUsd is sanitized to null when non-finite (NAN/INF/-INF): unlike
     * serialize(), json_encode() has no representation for non-finite floats
     * and returns false for the whole payload if one slips through, which
     * (before this guard) silently skipped the result-file write entirely and
     * hung the pool forever. arrayToResult()'s `?? 0.0` coalesces the null
     * back to a safe default on the read side.
     */
    private function resultToArray(AgentResult $result): array
    {
        return [
            'agentId' => $result->agentId,
            'status' => $result->status->value,
            'output' => $result->output,
            'errorMessage' => $result->error?->getMessage(),
            'tokensUsed' => $result->tokensUsed,
            'costUsd' => is_finite($result->costUsd) ? $result->costUsd : null,
            'startedAt' => $result->startedAt?->format('U.u'),
            'completedAt' => $result->completedAt?->format('U.u'),
        ];
    }

    /**
     * Reconstruct an AgentResult from the array produced by resultToArray().
     *
     * Returns null when the decoded payload is missing a recognizable status
     * — treated the same as a corrupt/unreadable result file.
     */
    private function arrayToResult(array $decoded): ?AgentResult
    {
        $status = is_string($decoded['status'] ?? null) ? AgentStatus::tryFrom($decoded['status']) : null;
        if ($status === null) {
            return null;
        }

        $errorMessage = $decoded['errorMessage'] ?? null;

        return new AgentResult(
            agentId: (string) ($decoded['agentId'] ?? ''),
            status: $status,
            output: $decoded['output'] ?? null,
            error: is_string($errorMessage) ? new \RuntimeException($errorMessage) : null,
            tokensUsed: (int) ($decoded['tokensUsed'] ?? 0),
            costUsd: (float) ($decoded['costUsd'] ?? 0.0),
            startedAt: $this->parseTimestamp($decoded['startedAt'] ?? null),
            completedAt: $this->parseTimestamp($decoded['completedAt'] ?? null),
        );
    }

    /**
     * Parse a 'U.u'-formatted timestamp string back into a DateTimeImmutable.
     */
    private function parseTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('U.u', $value);
        return $parsed !== false ? $parsed : null;
    }

    private function createDefaultExecutor(): ExecutorInterface
    {
        return new ProcessExecutor();
    }
}
