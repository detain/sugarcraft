<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Backend\TranscriptTurn;
use SugarCraft\Code\Backend\TurnInterrupted;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Hooks\BuiltIn\SubAgentGrantHook;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Messages\SystemMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Support\ParentProcessGuard;
use SugarCraft\Code\Tools\DelegatesToEngine;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Usage;

/**
 * The executor that makes a pooled agent — a `/workflow` stage — a whole
 * agentic run through the session's own {@see EngineBackend}, instead of the
 * single provider call {@see ProcessExecutor}'s worker makes.
 *
 * WHY IT EXISTS. That worker advertises the agent's tools and executes none of
 * them, so any stage whose first move was a tool call ended with empty output —
 * the same defect the `Task` tool had, one dispatch path over. This executor
 * runs the stage through the engine's bounded tool loop with the SAME hook
 * chain, permission gate, approver and root as the session, narrowed to the
 * tools the stage's request carries (WorkflowEngine resolves each task's grant
 * into `$request->tools`), with the request's system prompt as a system turn
 * and the agent's `maxTurns` (default {@see DEFAULT_MAX_TURNS}) as the step cap.
 * `Task` is withheld, so a stage cannot fan out further.
 *
 * IT IS A FORKED EXECUTOR. {@see AgentWorkerPool} runs it inside the child it
 * forks per agent (its `forkedExecutor` seam), which is what keeps a
 * multi-minute stage from blocking the TUI process the workflow Fiber runs in:
 * the parent only polls and yields. Every provider chunk and tool event checks
 * {@see ParentProcessGuard}, so a child whose parent died stops rather than
 * editing the tree for nobody.
 *
 * THE ENGINE IS BOUND LATE ({@see bind()}), because the session's backend is
 * replaced on a provider switch while the pool and its executor are built once
 * at launch: {@see \SugarCraft\Code\Chat}'s constructor re-binds the current
 * backend every time a Chat is built, the same place it links the AgentManager.
 * An unbound executor refuses every agent with the reason, never fabricates.
 */
final class EngineExecutor implements ForksAskFreeAgents
{
    /**
     * Step cap for an agent that declares no `maxTurns`.
     *
     * 200, matching {@see \SugarCraft\Code\Tools\BuiltIn\TaskTool::DEFAULT_MAX_TURNS}
     * (the 2026-10-02 step-budget decision): a real stage — read, edit, run
     * the tests, fix, re-run — routinely needs more than the old 50 tool
     * steps, and running out mid-task ends the stage with no final report.
     * The cap is a runaway bound, not a budget; the stage's wall-clock
     * `timeout` is what bounds its time.
     *
     * The default of the `subagentMaxTurns` setting (roadmap N-P4f), which
     * {@see defaultMaxTurns()} resolves.
     */
    public const DEFAULT_MAX_TURNS = 200;

    /** The settings key that replaces {@see DEFAULT_MAX_TURNS} (roadmap N-P4f). */
    public const MAX_TURNS_SETTINGS_KEY = 'subagentMaxTurns';

    /** At most one streamed append per this many seconds; a tool call flushes at once. */
    public const STREAM_FLUSH_SECONDS = 0.25;

    /** A tool line in the live stream is one line, capped here. */
    private const TOOL_LINE_MAX = 120;

    /**
     * The step cap for a delegated run whose preset declares no `maxTurns`:
     * the `subagentMaxTurns` setting when the merged config sets a value its
     * schema definition accepts, else {@see DEFAULT_MAX_TURNS}. Read when the
     * run starts, so a save applies to the next delegation. A preset's own
     * `maxTurns` still wins; this is only the default.
     *
     * @param ?array<string, mixed> $config the already-read merged config;
     *                                      null reads it (and an unreadable
     *                                      one costs the default, never the run)
     */
    public static function defaultMaxTurns(?array $config = null): int
    {
        if ($config === null) {
            try {
                $config = \SugarCraft\Code\Cli\Bootstrap::readUserConfig();
            } catch (\Throwable) {
                $config = [];
            }
        }

        $turns = \SugarCraft\Code\Tools\ToolLimits::honoured($config, self::MAX_TURNS_SETTINGS_KEY);

        return \is_int($turns) ? $turns : self::DEFAULT_MAX_TURNS;
    }

    /**
     * @param ?AgentManager $grantManager the manager whose
     *        {@see AgentManager::grantRefusalFor()} judges each stage call
     *        against the stage's own `tools:` declaration (the W2-g carry of
     *        step 4.2); null judges through a manager built for the bound
     *        engine — the matcher reads only the agent's declaration and the
     *        root, so the grant binds whether or not a session manager exists.
     * @param ?\Closure(): void $heartbeat the calling turn's liveness sink
     *        ({@see \SugarCraft\Code\Tools\DelegatesToEngine}), beaten on
     *        every provider chunk and tool event of an INLINE run — the
     *        `Workflow` tool's, which runs its stages inside the turn child
     *        whose parent measures silence on the turn socket. Null on the
     *        TUI's forking `/workflow` path, which has no such deadline.
     * @param ?WorktreeManager $worktreeManager the tree pool an
     *        `isolation: worktree` stage borrows (G2); null builds one lazily
     *        for the stage's own environment root, exactly as TaskTool's
     *        session does for a delegated run.
     * @param bool $forkAskFree P2-1: when this executor is INJECTED into a
     *        pool (the `Workflow` tool's inline topology), clear the agents
     *        whose effective tools cannot put a permission question
     *        ({@see AskFreeGrant}) for the pool's forking path, so a
     *        read-only fan-out runs concurrently. False (the default) keeps
     *        every injected dispatch inline, byte-identical to before.
     */
    public function __construct(
        private ?EngineBackend $engine = null,
        private ?AgentManager $grantManager = null,
        private ?\Closure $heartbeat = null,
        private ?WorktreeManager $worktreeManager = null,
        private readonly bool $forkAskFree = false,
    ) {}

    /**
     * How each agent of a pool that consulted {@see forkRefusal()} was
     * actually dispatched, keyed by agent id (a retry overwrites its entry).
     * Empty unless {@see $forkAskFree} is on.
     *
     * @var array<string, array{agent: string, forked: bool, why: ?string}>
     */
    private array $dispatchLog = [];

    public function forkRefusal(SubAgent $agent, CompleteRequest $request): ?string
    {
        if (!$this->forkAskFree) {
            return 'this executor runs every agent inline';
        }
        if ($this->engine === null) {
            return 'no session engine is bound';
        }

        $tools = self::runTools($request->tools ?? $this->engine->tools());
        if ($agent->outputSchema !== null) {
            $tools[] = new StructuredOutputTool($agent->outputSchema);
        }

        return AskFreeGrant::askRisk($tools, [$this->engine->permissionGate(), $agent->permissionGate], $agent->agent->tools);
    }

    public function recordDispatch(SubAgent $agent, bool $forked, ?string $why): void
    {
        if (!$this->forkAskFree) {
            return;
        }

        $this->dispatchLog[$agent->id] = ['agent' => $agent->agent->name, 'forked' => $forked, 'why' => $why];
    }

    public function whileForkedAgentsRun(): void
    {
        if ($this->heartbeat !== null) {
            ($this->heartbeat)();
        }
    }

    /**
     * @return array<string, array{agent: string, forked: bool, why: ?string}>
     */
    public function dispatchLog(): array
    {
        return $this->dispatchLog;
    }

    /**
     * The tools a run actually gets from $tools: every delegation withheld
     * (see the R4-m1 note in {@see run()}). One copy, so the fork test and
     * the run judge the same set.
     *
     * @param array<Tool> $tools
     * @return list<Tool>
     */
    private static function runTools(array $tools): array
    {
        return array_values(array_filter(
            $tools,
            static fn (Tool $tool): bool => !$tool instanceof DelegatesToEngine,
        ));
    }

    /**
     * Bind the session's current engine and, when given, the session's
     * AgentManager the stage grants are judged through. A null manager keeps
     * the one already bound.
     */
    public function bind(EngineBackend $engine, ?AgentManager $grantManager = null): void
    {
        $this->engine = $engine;
        $this->grantManager = $grantManager ?? $this->grantManager;
    }

    /** The manager stage grants are judged through, or null for the built-in fallback. */
    public function grantManager(): ?AgentManager
    {
        return $this->grantManager;
    }

    public function engine(): ?EngineBackend
    {
        return $this->engine;
    }

    public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
    {
        return $this->run($agent, $request, null);
    }

    /**
     * The run, with its prose and tool calls streamed as they happen — what
     * the live-agent pane paints while a stage is still working.
     *
     * WHY A FIBER. The engine reports progress through CALLBACKS (its token and
     * tool-event sinks) and a callback cannot `yield`, while this contract —
     * the one {@see AgentWorkerPool::runStreaming()} consumes in the forked
     * child and turns into progress-file appends — is a generator. So the run
     * goes inside a `\Fiber`, and the sinks `Fiber::suspend()` each buffered
     * delta out to this generator, which yields it as a Streaming result and
     * resumes. `executeStream()` stays the ONE channel every consumer of an
     * executor already reads, rather than a second, pool-only sink seam.
     *
     * Suspending is safe under the TUI's own workflow Fiber: `Fiber::suspend()`
     * always returns to whoever resumed the INNERMOST fiber, which is this
     * generator, never the outer driver. A suspension that is not one of ours
     * (a value that is not a string) is resumed at once and yields nothing.
     *
     * WHAT IS STREAMED IS AN ACTIVITY LOG, NOT THE REPLY. The pool's progress
     * file is append-only, so text cannot be retracted when a later step
     * supersedes a step's prose; each tool call therefore gets a `▸` line and
     * the stream reads as "said this, then called that". The terminal result
     * still carries only the final answer. Deltas are coalesced to at most one
     * append per {@see STREAM_FLUSH_SECONDS} (a tool call flushes at once) so a
     * token-per-chunk provider does not cost a file write per token.
     */
    public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
    {
        $buffer = '';
        $lastFlush = microtime(true);
        $flush = static function () use (&$buffer, &$lastFlush): void {
            if ($buffer === '') {
                return;
            }
            $chunk = $buffer;
            $buffer = '';
            $lastFlush = microtime(true);
            \Fiber::suspend($chunk);
        };
        $onToken = static function (string $delta) use (&$buffer, &$lastFlush, $flush): void {
            $buffer .= $delta;
            if (microtime(true) - $lastFlush >= self::STREAM_FLUSH_SECONDS) {
                $flush();
            }
        };
        $onToolStarted = static function (ToolStarted $event) use (&$buffer, $flush): void {
            if ($buffer !== '' && !str_ends_with($buffer, "\n")) {
                $buffer .= "\n";
            }
            $buffer .= '▸ ' . self::describe($event) . "\n";
            $flush();
        };

        $fiber = new \Fiber(function () use ($agent, $request, $onToken, $onToolStarted, $flush): AgentResult {
            $result = $this->run($agent, $request, $onToken, $onToolStarted);
            $flush();

            return $result;
        });

        $suspended = $fiber->start();
        while (!$fiber->isTerminated()) {
            if (\is_string($suspended)) {
                yield new AgentResult(agentId: $agent->id, status: AgentStatus::Streaming, output: $suspended);
            }
            $suspended = $fiber->resume();
        }

        yield $fiber->getReturn();
    }

    /**
     * @param ?\Closure(string): void      $onToken       prose deltas, every step
     * @param ?\Closure(ToolStarted): void $onToolStarted each tool call as it starts
     */
    private function run(SubAgent $agent, CompleteRequest $request, ?\Closure $onToken, ?\Closure $onToolStarted = null): AgentResult
    {
        $startedAt = new \DateTimeImmutable();

        if ($this->engine === null) {
            return self::failed($agent, $startedAt, 'no session engine is bound to this executor, so the agent cannot run');
        }

        // E663's rule, kept on this path: a launch whose provider could not be
        // built degrades the CHAT to the offline echo engine, and a stage run
        // through it would "succeed" with its own prompt echoed back.
        if ($this->engine->provider() instanceof EchoProvider) {
            return self::failed(
                $agent,
                $startedAt,
                'No provider configured: the session engine is the offline echo fallback, so this stage '
                . 'refuses rather than report echoed text as work. Configure a provider and re-run.',
            );
        }

        // G3 / P0-2: a stage that declares outputSchema answers through the
        // StructuredOutput tool call (see below), so its provider must be able
        // to make tool calls at all. One that cannot is refused before a
        // billable call, rather than degrading to a free-text report dressed
        // up as a schema verdict — the whole point of the schema is a
        // machine-readable result for drivers. Native JSON-schema support is
        // no longer the requirement: the tool path does not use it.
        if ($agent->outputSchema !== null && !$this->engine->provider()->supportsFunctionCalling()) {
            return self::failed(
                $agent,
                $startedAt,
                sprintf(
                    'agent "%s" declares an outputSchema, but its provider (%s) cannot make tool calls, so it cannot constrain the answer to a schema (the answer is enforced through the %s tool call); the run was not started',
                    $agent->agent->name,
                    $this->engine->provider()->name(),
                    StructuredOutputTool::NAME,
                ),
            );
        }

        // S2 (wf-g2g3): a stage that asks for a model by name rides the SAME
        // ladder a delegation consults (ModelChoice — session equality, tier
        // aliases, server feasibility), but the stage door phrases the
        // outcome the way G3 phrases an unservable schema: REFUSE, never
        // dial a stand-in. A plan author who pinned `model:` asked for that
        // model's answer; silently answering from a different one is exactly
        // the 'runtime-ignored' shape the model spec bans. Checked before the
        // worktree enter: a refused stage builds no tree and never calls.
        $runModel = null;
        if ($agent->model !== null) {
            [$pin, $unservable] = ModelChoice::decide($this->engine, $agent->agent, $agent->model);
            if ($unservable !== null) {
                return self::failed(
                    $agent,
                    $startedAt,
                    sprintf(
                        'agent "%s" asks to run on model "%s", %s; the run was not started, because a stage that names a model means that model (this session runs "%s")',
                        $agent->agent->name,
                        $unservable['asked'],
                        $unservable['why'],
                        $this->engine->servedModel() ?? $this->engine->model(),
                    ),
                );
            }
            $runModel = $pin;
        }

        // R4-m1 disclosure: delegation tools (DelegatesToEngine — Task, and
        // every *Section drive-through) are excluded from the sub-agent
        // toolset. Their body is itself a delegated turn, and inside this
        // child the parent's emitter is a pid-bound no-op — a nested
        // delegation here would report progress nowhere.
        $tools = self::runTools($request->tools ?? $this->engine->tools());

        // P0-2: the run's own answer channel, minted per run (it holds the
        // accepted answer). A same-named tool from the request is dropped so
        // the model sees exactly one StructuredOutput — ours.
        $structured = $agent->outputSchema !== null ? new StructuredOutputTool($agent->outputSchema) : null;
        if ($structured !== null) {
            $tools = array_values(array_filter(
                $tools,
                static fn (Tool $tool): bool => $tool->name() !== StructuredOutputTool::NAME,
            ));
        }
        $maxTurns = max(1, $agent->agent->maxTurns ?? self::defaultMaxTurns());

        $guard = ParentProcessGuard::capture('workflow that dispatched it');

        // The agent's own wall-clock bound, checked cooperatively at the same
        // two progress sinks the parent guard uses (audit WF-1-rem). On the
        // forking path the pool kills this child at the same deadline anyway,
        // tree and all; this is what bounds the SYNCHRONOUS paths — a build
        // without pcntl, or a failed fork — where the pool runs this executor
        // inline and has no way to interrupt it. Cooperative, so a single
        // blocking provider call or tool runs to its own bound first; the run
        // stops at the next chunk or tool event after the deadline, before the
        // next tool starts. A non-positive timeout means no per-agent bound.
        $deadlineNs = $agent->timeout > 0 ? hrtime(true) + $agent->timeout * 1_000_000_000 : null;
        $expired = false;
        $checkDeadline = static function () use ($deadlineNs, &$expired, $agent): void {
            if ($deadlineNs !== null && hrtime(true) >= $deadlineNs) {
                $expired = true;

                throw new \RuntimeException(sprintf('the agent ran past its %d s timeout and was stopped', $agent->timeout));
            }
        };

        $heartbeat = $this->heartbeat;
        // G3 honesty mirror: headless tracks whether any tool result landed in
        // the transcript (json_schema_unapplied); a consumed tool round means
        // the terminal turn's constrained answer was preceded by free-form
        // tool steps, so drivers must know the schema may not have pinned the
        // reply. Bound by reference into the event sink below.
        $sawTool = false;
        // Notes that would ride the tail of a prose report; a schema run's
        // output must stay a bare JSON document, so there they go to the
        // live activity stream instead.
        $runNotes = [];
        $onProgress = static function () use ($guard, $checkDeadline, $heartbeat): void {
            $guard();
            $checkDeadline();
            if ($heartbeat !== null) {
                $heartbeat();
            }
        };
        $onEvent = static function (object $event) use ($guard, $checkDeadline, $onToolStarted, $heartbeat, &$sawTool): void {
            $guard();
            $checkDeadline();
            if ($heartbeat !== null) {
                $heartbeat();
            }
            // The StructuredOutput answer writes nothing, so it is not the
            // tool round M-11's requestedWrite reports.
            if ($event instanceof ToolFinished && $event->toolName !== StructuredOutputTool::NAME) {
                $sawTool = true;
            }
            if ($onToolStarted !== null && $event instanceof ToolStarted) {
                $onToolStarted($event);
            }
        };

        $messages = self::messages($agent, $request, $structured !== null);

        // G2 (wf-g2g3): an `isolation: worktree` stage runs in its OWN git
        // worktree, through the same WorktreeIsolation machinery a delegated
        // Task run uses — refuse, never degrade: a stage that cannot be
        // isolated fails with the why instead of editing the shared checkout.
        // The manager is the session's when one was injected (TaskTool's
        // wiring hands one over); a bare executor builds one lazily for the
        // agent's environment root, falling back to the process cwd exactly
        // like LaunchContext does for Task.
        $worktree = null;
        $manager = $this->worktreeManager;
        if ($agent->isolation === Isolation::Worktree) {
            if ($manager === null) {
                $manager = WorktreeManager::new($agent->agent->environmentRoot ?? (getcwd() ?: '.'));
            }
            [$worktree, $why] = WorktreeIsolation::enter($manager, $agent->agent->name, $agent->id, null);
            if ($worktree === null) {
                return self::failed($agent, $startedAt, $why);
            }
        }

        // The stage's OWN declaration binds every call it makes (W2-g, the
        // workflow half of step 4.2): the request's tools are narrowed by
        // NAME only, so `tools: ['Bash(git *)']` puts the whole Bash tool on
        // the wire, and without this hook the stage ran any command the
        // session gate allowed. Same hook, same matcher and same placement as
        // Task's — ahead of the session gate, registered even under
        // withoutHooks() — so a call outside the grant is a denied call the
        // model reads and works around, never a failed stage.
        $engine = $this->engine
            ->withTools($tools)
            ->withMaxSteps($maxTurns);

        // S2: the stage's model pin rides the SAME channel a delegation uses —
        // EngineBackend::withModel, which the Runtime stamps onto every
        // request's App (the dial-side model). Null (no ask, or the ask is
        // the session's own) means the line is never touched, so an ordinary
        // stage dials exactly what it dials today.
        if ($runModel !== null) {
            $engine = $engine->withModel($runModel);
        }

        // S2: what actually got dialed is the run's truth, recorded the way
        // TaskTool records it for a delegation (post-binding, before the
        // first request) so the row and the resume read the same model.
        if ($agent->model !== null) {
            $agent->runModel = $engine->model();
        }

        // P1-6: the stage's reasoning effort rides the request the way an
        // active skill's `effort` does (Runtime stamps it onto App::$reasoningEffort
        // through the backend) — sent as declared. Only some providers read
        // the field; on one that does not, the run still runs and says so in
        // a bracketed note (the output tail, or the live stream for a schema
        // run) instead of pretending. Null: never touched.
        if ($agent->effort !== null) {
            $engine = $engine->withReasoningEffort($agent->effort->value);
            if (!$engine->honoursReasoningEffort()) {
                $runNotes[] = sprintf(
                    '[effort: %s sent, but provider "%s" ignores reasoning effort]',
                    $agent->effort->value,
                    $engine->provider()->name(),
                );
            }
        }

        // G2: re-jail the engine's tool set onto the stage's worktree —
        // withWorktreeRoot maps every current tool through its own worktree
        // jail (path jail + Bash escape deny) exactly as TaskTool's chain
        // does; ahead of the schema leg so both ride the re-jailed engine.
        if ($worktree !== null) {
            $engine = $engine->withWorktreeRoot($worktree['path']);
        }

        // P0-2: the schema is enforced through the StructuredOutput call, not
        // the provider's JSON mode. withJsonSchema() is deliberately NOT
        // applied here any more: Runtime::jsonSchemaFor() stamps it on a
        // self-finishing request, and a constrained reply cannot carry tool
        // calls — so on the opening request it would forbid the very call the
        // run must end with (and every read before it). Headless `-p
        // --json-schema` keeps that leg; this is the sub-agent path only.
        // Appended AFTER the worktree re-jail so the instance Runtime::gate()
        // recognises is the one the run carries.
        if ($structured !== null) {
            $engine = $engine->withTools([...$engine->tools(), $structured]);
        }

        $engine = $engine->withSubAgentGrant(new SubAgentGrantHook(
            $this->grantManager ?? new AgentManager($this->engine->provider(), new SkillRegistry()),
            $agent,
        ));

        // Blank-return resume (wf-g4): a child that COMPLETED its turn with a
        // blank final report is resumed on the spot, the same session carried
        // forward with one continue instruction, up to ten consecutive blanks.
        // The worktree enters above and leaves exactly once below — a resumed
        // attempt keeps editing in the stage's own tree, and every silent
        // attempt's usage is banked so the final result bills the whole run.
        $blankResume = new BlankReturnResume();
        /** @var list<?Usage> $blankUsages */
        $blankUsages = [];
        /** @var list<Message> $attemptMessages */
        $attemptMessages = $messages;
        $schemaNudged = false;
        $stopRequested = $structured === null ? null : static fn (): bool => $structured->accepted();

        while (true) {
            try {
                // A delegated run (step 3.D-2): the turn ends on `SubagentStop`,
                // not on the session's `Stop` — this engine is the session's own
                // and carries nothing else that says so. Each resume is its own
                // delegated turn on the SAME agent id, the way a follow-up would
                // already behave.
                $turn = HookManager::runAsSubagent(
                    $agent->id,
                    $agent->agent->name,
                    static fn (): TranscriptTurn => $engine->completeTranscript(
                        $attemptMessages,
                        onEvent: $onEvent,
                        onReasoning: $onProgress,
                        onHeartbeat: $heartbeat,
                        onToken: $onToken,
                        stopRequested: $stopRequested,
                    ),
                );
            } catch (TurnInterrupted $failure) {
                // A run that failed part-way still billed the steps it completed
                // (same bug class as audit B4): the interrupted transcript carries
                // each completed step's assistant turn with its usage. The offset
                // is THIS attempt's input — earlier attempts' turns already live
                // in the banked $blankUsages.
                $usages = [];
                foreach (\array_slice($failure->transcript, \count($attemptMessages)) as $message) {
                    if ($message instanceof AssistantMessage) {
                        $usages[] = $message->usage();
                    }
                }

                // G2: a stage interrupted mid-run leaves its worktree the way
                // Task leaves it — released when clean, disclosed by path (and
                // branch) in the failure text when work was kept.
                [$treeNote,] = WorktreeIsolation::leave($manager, $worktree, $agent->agent->name);

                return self::failed(
                    $agent,
                    $startedAt,
                    $failure->getMessage() . $treeNote,
                    Usage::sum([...$blankUsages, ...$usages]),
                    $expired ? AgentStatus::TimedOut : AgentStatus::Failed,
                );
            }

            $output = trim($turn->reply->content);

            // P0-2: a schema run ends on its accepted StructuredOutput call;
            // a reply that is itself exactly a valid document is accepted
            // too. Anything else gets ONE re-prompt inside the remaining step
            // budget, then fails — never prose passed off as the answer. The
            // re-prompt stands in for blank-return resume on this path: a
            // blank reply is just another reply without the call.
            if ($structured !== null) {
                $answer = $structured->answer() ?? $structured->answerFromText($output);
                if ($answer !== null) {
                    $output = $answer;

                    break;
                }

                if ($schemaNudged) {
                    [$treeNote,] = WorktreeIsolation::leave($manager, $worktree, $agent->agent->name);

                    return self::failed(
                        $agent,
                        $startedAt,
                        sprintf(
                            'agent "%s" declares an outputSchema but ended without an accepted %s call, even after being told to make one, so it produced no structured result%s',
                            $agent->agent->name,
                            StructuredOutputTool::NAME,
                            $output === '' ? '' : sprintf(' (its last reply began: "%s")', self::excerpt($output)),
                        ) . $treeNote,
                        Usage::sum([...$blankUsages, $turn->reply->usage]),
                    );
                }

                $schemaNudged = true;
                $blankUsages[] = $turn->reply->usage;
                $stepsUsed = 0;
                foreach (\array_slice($turn->transcript, \count($attemptMessages)) as $row) {
                    if ($row instanceof AssistantMessage) {
                        $stepsUsed++;
                    }
                }
                // The SAME budget, not a fresh one: what the first attempt
                // left. At least one step, so a run that spent its whole
                // budget can still deliver the answer it has.
                $engine = $engine->withMaxSteps(max(1, $maxTurns - $stepsUsed));
                $attemptMessages = [...$turn->transcript, new UserMessage(StructuredOutputTool::NUDGE)];

                continue;
            }

            if (!BlankReturnResume::isBlank($output)) {
                $blankResume->nonBlank();
                break;
            }

            if (!$blankResume->observeBlank()) {
                // The tenth consecutive blank: fail honestly, through the
                // same Failed shape a mechanical stage failure uses, with the
                // stall and its consumed budget named for the driver. The
                // legacy sentence stays so a driver that greps for the old
                // reason still recognises this one.
                [$treeNote,] = WorktreeIsolation::leave($manager, $worktree, $agent->agent->name);

                return self::failed(
                    $agent,
                    $startedAt,
                    sprintf(
                        'the agent ended without a final report (step cap %d); any work it did is in the tree but was not summarised',
                        $maxTurns,
                    ) . $treeNote . $blankResume->stallNote(),
                    Usage::sum([...$blankUsages, $turn->reply->usage]),
                );
            }

            $blankUsages[] = $turn->reply->usage;
            $attemptMessages = [...$turn->transcript, new UserMessage(BlankReturnResume::INSTRUCTION)];
        }

        // G2: release the stage's worktree exactly as Task leaves it — a clean
        // tree is removed and nothing says so; kept work is disclosed by path
        // and branch, riding the tail of the text the driver reads (and of the
        // failure text when the run reported nothing).
        [$treeNote,] = WorktreeIsolation::leave($manager, $worktree, $agent->agent->name);

        // A report that rode on resumes says so, in the bracketed tail
        // discipline; zero resumes, zero bytes.
        $tail = $treeNote . $blankResume->resumedNote()
            . ($runNotes === [] ? '' : "\n\n" . implode("\n", $runNotes));

        // P0-2: a schema run's output IS the validated JSON document, byte for
        // byte what {{name.output}} readers parse — so its notes (a kept
        // worktree, an ignored effort) go to the live activity stream rather
        // than corrupting the document. The old "[the reply was not
        // constrained…]" disclosure is gone: the answer is now enforced.
        if ($structured !== null) {
            if ($tail !== '' && $onToken !== null) {
                $onToken(trim($tail) . "\n");
            }
        } else {
            $output .= $tail;
        }

        // Banked blank attempts bill alongside the reporting one; with no
        // resumes $finalUsage IS the reply's own usage object, so the legacy
        // numbers are untouched.
        $finalUsage = $blankUsages === [] ? $turn->reply->usage : Usage::sum([...$blankUsages, $turn->reply->usage]);

        // M-11 (cc-fix-1111): this executor runs tools IN-PROCESS, so its
        // own ToolFinished record IS the evidence. A tool round that was
        // consumed here may have written anything — true, and no later
        // render may suppress the diff sections. Zero tools across the whole
        // run is a POSITIVE no-write report — false, the same honest claim
        // the tool-less ProcessExecutor worker makes about itself.
        return new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Completed,
            output: $output,
            tokensUsed: $finalUsage?->totalTokens ?? 0,
            costUsd: $finalUsage?->costUsd ?? 0.0,
            startedAt: $startedAt,
            completedAt: new \DateTimeImmutable(),
            requestedWrite: $sawTool,
        );
    }

    /**
     * Nothing to do here: this executor runs INSIDE the child
     * {@see AgentWorkerPool} forks, so this process's own state is not where a
     * cancel can land. The pool's kill site is the cancel, and it reaches
     * everything this executor's run started (audit F-E2-rem): the pool kills
     * the forked child with {@see \SugarCraft\Code\Support\ProcessContainment::killTree()}
     * (or, when the TUI's event loop is driving the run, its loop-tick twin
     * killTreeAsync() — audit R3), whose walk takes the engine's own turn
     * forks below it and every Bash command they run in its own `setsid`
     * session — a lone signal to the child would leave those running for
     * nobody.
     */
    public function cancel(string $agentId): void
    {
    }

    public function cancelAll(): void
    {
    }

    /**
     * The request's turns as typed messages, behind its system prompt. The pool
     * builds `['role' => 'user', 'content' => $task]` arrays; a request with no
     * turns at all falls back to the agent's task.
     *
     * @return list<Message>
     */
    private static function messages(SubAgent $agent, CompleteRequest $request, bool $structuredOutput = false): array
    {
        $messages = [];
        $systemPrompt = trim((string) $request->systemPrompt);
        // P0-2: the run is told how it ends; appended so the stage's own
        // system prompt stays first and verbatim.
        if ($structuredOutput) {
            $systemPrompt = ltrim($systemPrompt . "\n\n" . StructuredOutputTool::INSTRUCTION);
        }
        if ($systemPrompt !== '') {
            $messages[] = new SystemMessage($systemPrompt);
        }

        foreach ($request->messages as $entry) {
            if ($entry instanceof Message) {
                $messages[] = $entry;

                continue;
            }
            if (!\is_array($entry)) {
                continue;
            }

            $content = (string) ($entry['content'] ?? '');
            $messages[] = match ((string) ($entry['role'] ?? 'user')) {
                'system' => new SystemMessage($content),
                'assistant' => new AssistantMessage($content),
                default => new UserMessage($content),
            };
        }

        if (\count($messages) === ($systemPrompt === '' ? 0 : 1)) {
            $messages[] = new UserMessage($agent->task);
        }

        return $messages;
    }

    /** The head of a reply, one line, for a failure message. */
    private static function excerpt(string $text): string
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($line) > self::TOOL_LINE_MAX ? mb_substr($line, 0, self::TOOL_LINE_MAX - 1) . '…' : $line;
    }

    /**
     * `Name(args…)` on one line — {@see \SugarCraft\Code\Message::describeToolCall()},
     * the transcript's own rendering, flattened and capped for a pane tile.
     */
    private static function describe(ToolStarted $event): string
    {
        $line = \SugarCraft\Code\Message::describeToolCall(
            new \SugarCraft\Code\ToolCall($event->toolName, $event->arguments, $event->toolCallId),
        );
        $line = trim((string) preg_replace('/\s+/', ' ', $line));

        return mb_strlen($line) > self::TOOL_LINE_MAX ? mb_substr($line, 0, self::TOOL_LINE_MAX - 1) . '…' : $line;
    }

    /**
     * $spent is what the run billed before it failed: a failure does not
     * un-spend it, and the workflow's cost totals read it off this result.
     * $status is Failed, or TimedOut for a run its own timeout stopped.
     */
    private static function failed(
        SubAgent $agent,
        \DateTimeImmutable $startedAt,
        string $why,
        ?Usage $spent = null,
        AgentStatus $status = AgentStatus::Failed,
    ): AgentResult {
        return new AgentResult(
            agentId: $agent->id,
            status: $status,
            error: new \RuntimeException($why),
            tokensUsed: $spent?->totalTokens ?? 0,
            costUsd: $spent?->costUsd ?? 0.0,
            startedAt: $startedAt,
            completedAt: new \DateTimeImmutable(),
        );
    }
}
