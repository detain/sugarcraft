<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\TransientFailure;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\Tools\Tool;

final class AgentManager
{
    /**
     * Modes Plan is allowed to exit into once sub-agents are live: approving
     * a plan hands control to normal working modes, so this is a deliberate
     * carve-out from the seal rather than a mid-session mode change.
     */
    private const array PLAN_EXIT_MODES = [
        PermissionMode::Auto,
        PermissionMode::AcceptEdits,
        PermissionMode::Default,
    ];

    /** @var array<string, Agent> */
    private array $agents = [];

    /** @var array<string, SubAgent> */
    private array $subAgents = [];

    private ?TeamManager $teamManager = null;

    /**
     * Tracks the permission mode locked for this session.
     * Once the first sub-agent is created, the session mode is sealed except
     * for the explicit Plan -> {Auto, AcceptEdits, Default} exit carved out
     * below.
     *
     * @see createSubAgent() enforcement
     */
    private ?PermissionMode $sessionPermissionMode = null;

    /**
     * @param \Closure(PermissionMode): PermissionGate $permissionGateFactory Factory to create PermissionGate from PermissionMode
     * @param \Closure(ToolCall, SubAgent): bool $permissionApprover Settles a
     *        {@see PermissionDecision::Ask} into a real allow/deny. @see evaluateToolCalls()
     * @param ?list<Tool> $toolRegistry The session's model-facing tool set —
     *        {@see \SugarCraft\Crush\Cli\Bootstrap::tools()}'s return — from
     *        which {@see resolveGrantedTools()} selects the subset an agent's
     *        definition names. It is the CEILING and never a source: nothing
     *        below can hand a sub-agent a tool this array does not already
     *        contain, which is the same argument
     *        {@see \SugarCraft\Crush\Cli\Bootstrap::filterToolSet()} rests on.
     *
     *        NULL, THE DEFAULT, IS NOT AN EMPTY REGISTRY. It means the caller
     *        supplied none, and every sub-agent then reaches its provider with
     *        `tools: null` exactly as it did before this parameter existed —
     *        the pre-existing behaviour, kept reachable so a caller that has no
     *        tool set (every test double in `tests/`, and `Bootstrap` until its
     *        own one-line change lands) is not forced to invent one. An empty
     *        ARRAY is a different statement: a registry exists and offers
     *        nothing, so any declaration at all is unresolvable and refused.
     */
    public function __construct(
        private ProviderInterface $provider,
        private SkillRegistry $skillRegistry,
        private ?AgentWorkerPool $workerPool = null,
        private ?\Closure $permissionGateFactory = null,
        private ?\Closure $permissionApprover = null,
        private ?array $toolRegistry = null,
    ) {}

    /**
     * Register an agent.
     */
    public function register(Agent $agent): void
    {
        $this->agents[$agent->name] = $agent;
    }

    /**
     * Get an agent by name.
     */
    public function get(string $name): ?Agent
    {
        return $this->agents[$name] ?? null;
    }

    /**
     * Get all agents.
     *
     * @return array<Agent>
     */
    public function all(): array
    {
        return array_values($this->agents);
    }

    /**
     * Get active agents.
     *
     * "Active" is what every renderer keys off — {@see
     * \SugarCraft\Crush\Renderer::agentDisplayState()} and {@see
     * \SugarCraft\Crush\Tui\Components\AgentDashboardPane::agentEntry()} both
     * map it onto the literal status string "working" — so an agent with a
     * live sub-agent is active here even when its registration says
     * otherwise. That derivation is what lets a roster be registered as idle
     * templates ({@see \SugarCraft\Crush\Cli\Bootstrap::agentRoster()}) without
     * the launch painting a permanent strip of agents claiming to be working
     * on a session where nothing has been delegated yet.
     *
     * The derived case returns a `withActive(true)` COPY rather than mutating
     * the registration: the registration is the configured agent and outlives
     * any sub-agent, and callers that registered an agent as active still get
     * their own instance back untouched.
     *
     * @return array<Agent>
     */
    public function active(): array
    {
        $active = [];

        foreach ($this->agents as $agent) {
            if ($this->isWorking($agent->name)) {
                $active[] = $agent->isActive ? $agent : $agent->withActive(true);
                continue;
            }

            if ($agent->isActive) {
                $active[] = $agent;
            }
        }

        return $active;
    }

    /**
     * Whether the named agent has at least one sub-agent that has not reached
     * a terminal state.
     *
     * Pending counts as working: a sub-agent queued behind
     * {@see AgentWorkerPool}'s concurrency limit is work the user asked for
     * and is waiting on, and reporting it as idle would make a saturated pool
     * look like an empty one.
     */
    public function isWorking(string $agentName): bool
    {
        foreach ($this->subAgentsOf($agentName) as $subAgent) {
            if (!$subAgent->isComplete() && !$subAgent->isStopped()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create and start a subagent.
     *
     * @param PermissionMode|null $permissionMode Override the default permission mode for this sub-agent.
     *                                            When null, uses PermissionMode::Default.
     */
    public function createSubAgent(string $agentName, string $task, ?PermissionMode $permissionMode = null): SubAgent
    {
        $agent = $this->get($agentName);
        if ($agent === null) {
            throw new \RuntimeException("Unknown agent: $agentName");
        }

        $mode = $permissionMode ?? PermissionMode::Default;

        // Enforce permission mode cannot be changed mid-session.
        // Plan spec says BypassPermissions/DontAsk can only be set at launch —
        // once any sub-agent is created, re-entering either of those dangerous
        // modes is sealed off for the rest of the session. The one explicit
        // exception the plan carves out: Plan mode is allowed to exit into
        // Auto/AcceptEdits/Default once a plan is approved and sub-agents are
        // already live. Throwing LogicException makes the contract explicit.
        if ($this->sessionPermissionMode !== null && $this->sessionPermissionMode !== $mode) {
            $isPlanExit = $this->sessionPermissionMode === PermissionMode::Plan
                && in_array($mode, self::PLAN_EXIT_MODES, true);

            if (!$isPlanExit) {
                throw new \LogicException('Permission mode cannot be changed mid-session');
            }
        }
        $this->sessionPermissionMode = $mode;

        $gate = $this->createPermissionGate($mode);

        $subAgent = new SubAgent(
            // E329: the literal prefix is a NAMESPACE, not an entropy source —
            // uniqid('a') and uniqid('b') at one instant differ only in those
            // bytes and carry the same 13-hex microtime. The pid and the
            // more-entropy flag are what make two processes' ids distinct; the
            // id reaches disk through AgentWorkerPool::resultFile().
            id: uniqid('subagent_' . getmypid() . '_', true),
            agent: $agent,
            task: $task,
            permissionGate: $gate,
        );

        $this->subAgents[$subAgent->id] = $subAgent;

        return $subAgent;
    }

    /**
     * Create a PermissionGate from a PermissionMode using the configured factory,
     * or return a default gate if no factory is configured.
     */
    private function createPermissionGate(PermissionMode $mode): PermissionGate
    {
        if ($this->permissionGateFactory !== null) {
            return ($this->permissionGateFactory)($mode);
        }

        // Default factory: create a basic gate with no custom rules
        return new PermissionGate($mode);
    }

    /**
     * Get a subagent by ID.
     */
    public function getSubAgent(string $id): ?SubAgent
    {
        return $this->subAgents[$id] ?? null;
    }

    /**
     * Every sub-agent spawned from a given registered agent.
     *
     * The live agent view renders one row per registered {@see Agent}, but
     * telemetry only exists on the {@see SubAgent}s that agent spawned, so
     * the per-agent accessors below all roll up through this.
     *
     * @return array<SubAgent>
     */
    public function subAgentsOf(string $agentName): array
    {
        return array_values(array_filter(
            $this->subAgents,
            fn(SubAgent $subAgent) => $subAgent->agent->name === $agentName,
        ));
    }

    /**
     * Wall-clock seconds the named agent has been working, spanning from the
     * earliest sub-agent start to the latest activity.
     *
     * The span (rather than a sum of per-sub-agent elapsed times) is what the
     * status line wants: sub-agents run concurrently via
     * {@see AgentWorkerPool}, so summing them would report several minutes of
     * "elapsed" for an agent that has been busy for thirty seconds.
     * Returns 0 when the agent has no started sub-agents.
     */
    public function elapsedSeconds(string $agentName): int
    {
        $started = array_filter(
            $this->subAgentsOf($agentName),
            fn(SubAgent $subAgent) => $subAgent->startedAt !== null,
        );

        if ($started === []) {
            return 0;
        }

        $earliest = null;
        $latest = null;
        foreach ($started as $subAgent) {
            /** @var \DateTimeImmutable $startedAt */
            $startedAt = $subAgent->startedAt;
            $begin = $startedAt->getTimestamp();
            // A still-running sub-agent pins the end of the span to now, so
            // the display ticks up instead of freezing at the last completion.
            $end = $subAgent->completedAt?->getTimestamp() ?? time();

            $earliest = $earliest === null ? $begin : min($earliest, $begin);
            $latest = $latest === null ? $end : max($latest, $end);
        }

        return max(0, (int) $latest - (int) $earliest);
    }

    /**
     * Tokens consumed across every sub-agent spawned from the named agent.
     *
     * Note: no renderer reads this yet. The status line still shows hardcoded
     * zeros until W3.S5b swaps Renderer::agentDisplayState() onto these
     * accessors — the numbers here are real, the display is not yet wired.
     */
    public function tokensUsed(string $agentName): int
    {
        return array_sum(array_map(
            fn(SubAgent $subAgent) => $subAgent->tokensUsed,
            $this->subAgentsOf($agentName),
        ));
    }

    /**
     * Dollar cost accrued across every sub-agent spawned from the named agent.
     */
    public function costUsd(string $agentName): float
    {
        return array_sum(array_map(
            fn(SubAgent $subAgent) => $subAgent->costUsd,
            $this->subAgentsOf($agentName),
        ));
    }

    /**
     * The named agent's output buffer AS IT IS BEING PRODUCED — the public
     * "current live output buffer" accessor {@see \SugarCraft\Crush\Renderer}'s
     * own class docblock records as the missing piece, and the one thing that
     * separates a renderer able to show a sub-agent working from one that can
     * only show its final result.
     *
     * The value is a snapshot, not a stream: {@see executeSubAgent()} appends
     * to {@see SubAgent::$output} per streamed chunk and {@see executeAll()}
     * settles the out-of-process pool's output back onto the same field, so a
     * caller polling this between frames observes real incremental text. Pull
     * rather than push deliberately — every consumer in this codebase (the
     * transcript strip, {@see
     * \SugarCraft\Crush\Tui\Components\AgentDashboardPane}, the split-pane
     * compositor) renders from a frame tick it already owns, and a push
     * callback would only invert that control flow.
     *
     * Sub-agents are joined newest-last in creation order, which is the order
     * {@see subAgentsOf()} preserves, so a tail-oriented reader (the dashboard
     * peeks the last few lines) sees the most recent activity.
     */
    public function liveOutput(string $agentName): string
    {
        $chunks = [];
        foreach ($this->subAgentsOf($agentName) as $subAgent) {
            if ($subAgent->output !== '') {
                $chunks[] = $subAgent->output;
            }
        }

        return implode("\n", $chunks);
    }

    /**
     * Every agent that is producing text RIGHT NOW, keyed by agent name, with
     * the silent and the finished ones omitted.
     *
     * This is the multi-agent shape the tmux/iTerm2 split-pane compositor
     * ({@see \SugarCraft\Crush\Tui\Renderer::renderView()}) needs: it lays
     * several agents' live output out side by side, so it wants "who is
     * producing text right now", not one name at a time. Omitting the silent
     * ones is what keeps it from rendering a row of empty tiles — the exact
     * reason that compositor was deferred rather than wired.
     *
     * ## Two things this method used to get wrong, both measured
     *
     * It iterated {@see $agents} — the REGISTERED map, written only by
     * {@see register()} — so it could not see a workflow-spawned agent at all.
     * {@see \SugarCraft\Crush\Workflows\WorkflowEngine::executeParallelStage()}
     * never registers: it builds ad-hoc `Agent`s named `$task->name ??
     * $task->agentType` and hands the `SubAgent`s straight to
     * {@see executeAll()}, which files them under {@see $subAgents} and nowhere
     * else. Registering a real roster and inserting a `SubAgent` named
     * `style-fixer` exactly as `executeAll()` does gave
     * `liveOutput(\'style-fixer\')` a full buffer while this method returned
     * `[]`. Neither shipped workflow names a parallel task after a roster
     * agent (`examples/workflows/lint-then-fix.yaml:41,49`,
     * `workflows/deep-research.php:46,57,68,79`), so the registered map was the
     * one place the answer could never be.
     *
     * And it had no liveness filter, while every other consumer of this data
     * goes through {@see active()}, which does. Nothing clears
     * {@see SubAgent::$output} — {@see drain()} settles the pool's final text
     * onto it — so a finished agent stayed in this map for the rest of the
     * session and a pane keyed off it would never have come down.
     *
     * So: derived from {@see $subAgents}, filtered with the same
     * `!isComplete() && !isStopped()` predicate {@see isWorking()} applies, and
     * grouped by the sub-agent's own agent name. Registration is now
     * irrelevant here, which also means a removed registration cannot strand an
     * entry: {@see removeSubAgent()} takes the sub-agent out of the map this
     * reads.
     *
     * Buffers are joined newest-last within an agent, matching
     * {@see liveOutput()}; unlike that one, this method sees only the
     * non-terminal sub-agents, because a raw-buffer accessor and an
     * is-it-happening accessor want different things.
     *
     * @return array<string, string> agent name => live output
     */
    public function liveOutputs(): array
    {
        $outputs = [];
        foreach ($this->subAgents as $subAgent) {
            if ($subAgent->isComplete() || $subAgent->isStopped() || $subAgent->output === '') {
                continue;
            }

            $name = $subAgent->agent->name;
            $outputs[$name] = isset($outputs[$name])
                ? $outputs[$name] . "\n" . $subAgent->output
                : $subAgent->output;
        }

        return $outputs;
    }

    /**
     * Execute a subagent task.
     *
     * @throws \RuntimeException When subagent is not found
     */
    public function executeSubAgent(string $id): \Generator
    {
        $subAgent = $this->getSubAgent($id);
        if ($subAgent === null) {
            throw new \RuntimeException("SubAgent not found: $id");
        }

        try {
            $subAgent->status = SubAgent::STATUS_RUNNING;
            // Stamped here, not in the constructor: createdAt already records
            // creation, and elapsed telemetry must exclude queue time.
            $subAgent->startedAt = new \DateTimeImmutable();

            // Build system prompt from agent config
            $systemPrompt = $subAgent->agent->systemPrompt();

            // Apply skills
            foreach ($subAgent->agent->skillNames as $skillName) {
                $skill = $this->skillRegistry->get($skillName);
                if ($skill !== null) {
                    $systemPrompt .= $skill->systemPromptContribution();
                }
            }

            // Run completion.
            //
            // `tools:` is NOT optional decoration here — omitting it is what
            // made every sub-agent toolless while its prompt described a
            // roster. {@see resolveGrantedTools()} carries the whole argument,
            // including why `null` (no registry, or no declaration) is still a
            // correct answer rather than a failure.
            $request = new \SugarCraft\Crush\Providers\CompleteRequest(
                model: $subAgent->agent->model,
                messages: [
                    new \SugarCraft\Crush\Messages\UserMessage($subAgent->task),
                ],
                tools: $this->resolveGrantedTools($subAgent->agent),
                systemPrompt: $systemPrompt,
            );

            if ($this->provider->supportsStreaming()) {
                $subAgent->status = SubAgent::STATUS_STREAMING;

                // Transient-failure retry (crush_code.md Phase 5 item 8), on
                // the same policy as Runtime's two provider seams so the same
                // 5xx is not recoverable on the main turn and fatal for a
                // sub-agent. This loop is INSIDE the outer try, so only a
                // failure that survives every attempt reaches the catch that
                // marks the sub-agent FAILED.
                //
                // Unlike Runtime::runStreaming(), a mid-stream failure IS
                // retried here even after real output, because this seam's
                // observation channel for the OUTPUT is a PULL of a whole field
                // rather than a push of deltas: consumers read SubAgent::$output
                // as a snapshot ({@see liveOutput()} says so explicitly), and
                // the three fields below are restored to their pre-attempt
                // values. Runtime cannot do that because its $onToken sink is
                // append-only and there is no un-emit.
                //
                // WHAT DOES NOT ROLL BACK, stated because a rollback claim that
                // over-reaches is worse than none. An attempt also runs
                // evaluateToolCalls(), which is not a read: it calls
                // PermissionGate::evaluate() — `decide($call, commitAutoStrikes:
                // true)`, which advances the Auto-mode circuit-breaker counters
                // — and then the $permissionApprover, which is a BLOCKING
                // USER-FACING PROMPT. Neither is undoable, and neither is
                // undone. Measured: one Write call plus a 503 mid-stream shows
                // the user 2 approval prompts for the same tool call and
                // double-commits its strikes.
                //
                // So this is the same append-only argument that stops Runtime
                // from retrying mid-stream, applied to a different channel and
                // reaching the opposite answer — which is defensible only
                // because the seam has no production caller yet (backlog E28
                // carries the measurement and the severity). The fix, when the
                // seam is wired, is to hoist tool evaluation out of the retried
                // region or make it idempotent per call id; it is NOT to widen
                // this comment.
                for ($attempt = 1; $attempt <= TransientFailure::MAX_ATTEMPTS; $attempt++) {
                    $lastAttempt = $attempt === TransientFailure::MAX_ATTEMPTS;

                    // Snapshot rather than zero: a SubAgent may already carry
                    // output and usage from before this call, and a retry must
                    // rewind to where THIS attempt started, not to empty.
                    $outputBefore = $subAgent->output;
                    $tokensBefore = $subAgent->tokensUsed;
                    $costBefore = $subAgent->costUsd;

                    $errorChunk = null;
                    $thrown = null;

                    try {
                        foreach ($this->provider->completeStream($request) as $response) {
                            // Evaluate tool calls through the permission gate if set.
                            // A denial throws from in here, and is classified
                            // permanent by TransientFailure's allow-list - which is
                            // the whole reason that classifier is an allow-list and
                            // not a deny-list.
                            // NO LONGER GATED ON `permissionGate !== null`: the
                            // agent's own tool grant is enforced in there too
                            // ({@see refuseCallOutsideGrant()}), and a
                            // declaration does not stop being the agent's
                            // statement about itself because the caller
                            // attached no gate.
                            if ($response->toolCalls !== null) {
                                $this->evaluateToolCalls($response->toolCalls, $subAgent);
                            }

                            // Accumulated per chunk so a mid-flight sub-agent already
                            // reports real usage; providers report per-chunk deltas,
                            // hence += rather than assignment.
                            $subAgent->tokensUsed += $response->tokensUsed;
                            $subAgent->costUsd += $response->costUsd;

                            $subAgent->output .= $response->content;

                            if ($response->isError) {
                                $errorChunk = $response;
                            }

                            yield $subAgent;
                        }
                    } catch (\Throwable $e) {
                        $thrown = $e;
                    }

                    if ($thrown !== null) {
                        if ($lastAttempt || !TransientFailure::isTransient($thrown)) {
                            throw $thrown;
                        }
                    } elseif ($errorChunk === null
                        || $lastAttempt
                        || !TransientFailure::responseIsTransient($errorChunk)
                    ) {
                        break;
                    }

                    $subAgent->output = $outputBefore;
                    $subAgent->tokensUsed = $tokensBefore;
                    $subAgent->costUsd = $costBefore;
                    TransientFailure::backoff($attempt);
                }
            } else {
                $response = null;

                // See the streaming branch. Nothing is observable until
                // complete() returns, so this half has nothing to roll back.
                for ($attempt = 1; $attempt <= TransientFailure::MAX_ATTEMPTS; $attempt++) {
                    $lastAttempt = $attempt === TransientFailure::MAX_ATTEMPTS;

                    try {
                        $response = $this->provider->complete($request);
                    } catch (\Throwable $e) {
                        if ($lastAttempt || !TransientFailure::isTransient($e)) {
                            throw $e;
                        }
                        TransientFailure::backoff($attempt);

                        continue;
                    }

                    if ($lastAttempt || !TransientFailure::responseIsTransient($response)) {
                        break;
                    }

                    TransientFailure::backoff($attempt);
                }

                // Evaluate tool calls through the agent's grant and then the
                // permission gate if set. See the streaming branch for why the
                // gate-presence precondition is gone.
                if ($response->toolCalls !== null) {
                    $this->evaluateToolCalls($response->toolCalls, $subAgent);
                }

                $subAgent->output = $response->content;
                $subAgent->tokensUsed += $response->tokensUsed;
                $subAgent->costUsd += $response->costUsd;
            }

            $subAgent->status = SubAgent::STATUS_COMPLETE;
            $subAgent->completedAt = new \DateTimeImmutable();
        } catch (\Throwable $e) {
            $subAgent->status = SubAgent::STATUS_FAILED;
            $subAgent->error = $e->getMessage();
            // Failure is terminal too: without stamping the end of the span,
            // elapsedSeconds() would keep counting against wall-clock forever
            // and render a dead sub-agent as still working.
            $subAgent->completedAt ??= new \DateTimeImmutable();
            throw $e;
        }
    }

    /**
     * Evaluate tool calls through the agent's own grant and then through the
     * sub-agent's permission gate. Either refusal fails the sub-agent
     * immediately.
     *
     * TWO CHECKS, IN THIS ORDER, ANSWERING DIFFERENT QUESTIONS.
     * {@see refuseCallOutsideGrant()} asks whether the AGENT declared this
     * capability at all; the gate asks whether the SESSION's policy permits it.
     * Passing the first is not permission and passing the second is not a
     * grant. THIS METHOD USED TO RETURN EARLY WHEN NO GATE WAS ATTACHED, which
     * was right while the gate was the only check here and is wrong now: an
     * agent's declaration is its own statement about itself and does not become
     * unenforceable because the caller owns no UI.
     *
     * ASK is routed to {@see $permissionApprover}, the seam that makes it a
     * question instead of a dead end. Before it, every Ask was an immediate
     * hard failure, which made {@see PermissionMode::Auto}'s whole 3-strike
     * circuit breaker unreachable for sub-agents: its ESCALATION path — three
     * consecutive blocks of one category escalating from Deny to Ask so a
     * human can intervene — behaved exactly like the Deny it was escalating
     * away from, only with a worse message. (Pre-existing; verified against
     * the pre-P1.1 baseline, not a regression from that step.)
     *
     * Deliberately the same contract {@see \SugarCraft\Crush\Runtime::settleAsk()}
     * uses for the identical problem on the main loop, so an approver written
     * once works on both: literal `true` grants and NOTHING else does. A
     * `(bool)` cast would make every {@see \SugarCraft\Crush\Permissions\PermissionReply}
     * — `Reject` included, since every enum case is a truthy object — read as
     * permission, which is exactly how ForeignAgentPresetRegistry once granted
     * tool access it should have refused.
     *
     * With no approver attached the behaviour is unchanged and fails CLOSED:
     * a sub-agent whose caller owns no UI must not run a call the gate refused
     * to decide on its own.
     *
     * @param array<ToolCall> $toolCalls
     * @throws \RuntimeException When a tool call falls outside the agent's own
     *         grant, is denied by the gate, or an Ask goes unanswered/refused
     */
    private function evaluateToolCalls(array $toolCalls, SubAgent $subAgent): void
    {
        foreach ($toolCalls as $toolCall) {
            // FIRST, AND UNCONDITIONALLY. Two different questions are being
            // asked and the agent's own is the cheaper one to answer: the grant
            // needs no gate, no approver and no user, so settling it here keeps
            // a call the agent never asked for from ever reaching a blocking
            // approval prompt.
            $this->refuseCallOutsideGrant($toolCall, $subAgent);

            if ($subAgent->permissionGate === null) {
                continue;
            }

            $decision = $subAgent->permissionGate->evaluate($toolCall);

            if ($decision === \SugarCraft\Crush\Permissions\PermissionDecision::Deny) {
                $this->refuseToolCall($toolCall, $subAgent, 'denied by permission gate');
            }

            if ($decision !== \SugarCraft\Crush\Permissions\PermissionDecision::Ask) {
                continue;
            }

            if ($this->permissionApprover === null) {
                $this->refuseToolCall(
                    $toolCall,
                    $subAgent,
                    'requires approval and no approver is attached to this manager',
                );
            }

            if (($this->permissionApprover)($toolCall, $subAgent) !== true) {
                $this->refuseToolCall($toolCall, $subAgent, 'refused at the permission prompt');
            }
        }
    }

    /**
     * Stop a sub-agent on a refused tool call.
     *
     * One exit for all three refusal reasons so a sub-agent can never be left
     * marked RUNNING after its work was refused — the shape the Ask branch got
     * wrong by carrying its own near-duplicate of this.
     *
     * @throws \RuntimeException always
     */
    private function refuseToolCall(ToolCall $toolCall, SubAgent $subAgent, string $reason): never
    {
        $subAgent->status = SubAgent::STATUS_FAILED;
        $subAgent->error = sprintf(
            'Tool call "%s" %s (mode: %s)',
            $toolCall->name,
            $reason,
            $subAgent->permissionGate?->mode()->value ?? 'unknown',
        );

        throw new \RuntimeException($subAgent->error);
    }

    /**
     * The tool objects an agent's DECLARATION names, or null when this manager
     * has no registry to resolve them against.
     *
     * WHY THIS EXISTS. `AgentDefinition::$defaultTools` reached
     * {@see Agent::$tools} faithfully — {@see Agent::fromDefinition()} passes it
     * straight through — and then died here: {@see executeSubAgent()} built its
     * {@see CompleteRequest} with no `tools` argument, which
     * {@see CompleteRequest::__construct()} defaults to `null`, and EVERY
     * provider gates its tool block on `$request->tools !== null`. So a preset
     * declaring `['Read', 'Grep', 'Bash(git *)']` reached the model with no
     * tools whatsoever while its system prompt described the ones it had. The
     * prompt lied to the model and nothing reddened.
     *
     * WHY IT IS NOT `tools: $agent->tools`. The two sides speak different types.
     * A declaration is a STRING in {@see PermissionRule}'s pattern dialect; a
     * provider wants {@see Tool} OBJECTS and calls `->name()` on each
     * ({@see \SugarCraft\Crush\Providers\ClaudeCodeProvider::complete()}) or
     * hands them to its own `formatTools()`. Passing the strings through would
     * fatal on `->name()` or serialise garbage.
     *
     * THE DIALECT IS PermissionRule's, NOT A SECOND ONE, for the reason that
     * class's doc-block gives at length: two glob dialects for tool names are
     * two things `mcp__git__*` can mean. The NAME half selects the tool;
     * {@see PermissionRule::matchesToolName()} is the same static
     * {@see \SugarCraft\Crush\Cli\Bootstrap::filterToolSet()} already filters
     * the model-facing set with. The ARGUMENT half of `Bash(git *)` cannot be
     * expressed on the wire at all — a tool schema has no place to say "only
     * git commands" — so it is NOT dropped here: it is enforced per call by
     * {@see refuseCallOutsideGrant()}, which is where the arguments exist.
     *
     * FAILS LOUD, NEVER OPEN. A declaration that resolves to nothing throws
     * rather than being skipped. Skipping is the tempting shape and it is this
     * bug wearing a different hat: a typo'd `Reed` would silently produce a
     * sub-agent with a smaller roster than its prompt claims, which is exactly
     * the failure this method was written to end. Same for a malformed pattern
     * and for a registry entry that is not a {@see Tool} — an instrument that
     * quietly ignores what it cannot parse has a hole shaped like the next
     * defect.
     *
     * ORDER IS THE REGISTRY'S, not the declaration list's: `Bootstrap::tools()`
     * documents its array as a wire order the model has learned, and a subset
     * that reshuffles it would hand two agents the same tools in two orders.
     * Iterating the registry outside also dedupes by construction, so
     * `['Bash', 'Bash(git *)']` yields one `Bash`, not two — and both
     * declarations are still marked resolved, which is a separate fact the
     * loop had to be corrected to get right.
     *
     * @return ?list<Tool>
     * @throws \RuntimeException When a declaration is malformed, resolves to no
     *         tool in the registry, or the registry holds a non-{@see Tool}.
     */
    private function resolveGrantedTools(Agent $agent): ?array
    {
        if ($this->toolRegistry === null) {
            return null;
        }

        $patterns = $this->declarationNamePatterns($agent);
        if ($patterns === []) {
            // NO DECLARATION IS NOT AN EMPTY GRANT, and the difference is
            // visible to the model. `Agent::fromArray()` defaults `tools` to
            // `[]` and several in-tree Agents are built with it literally, so
            // `[]` is "this agent says nothing about tools" far more often than
            // it is "this agent forbids all of them". Sending `tools: []` would
            // put an empty array on the wire, which OpenAI-shaped providers
            // read as a real (empty) tool block rather than as absence, so this
            // returns null — the same `?:` reading {@see \SugarCraft\Crush\Runtime}
            // already applies to `App::$tools`.
            return null;
        }

        $matched = array_fill_keys(array_keys($patterns), false);
        $granted = [];

        foreach ($this->toolRegistry as $index => $tool) {
            if (!$tool instanceof Tool) {
                throw new \RuntimeException(sprintf(
                    'Tool registry entry %s is a %s, not a %s, so a sub-agent grant cannot be resolved against it.',
                    var_export($index, true),
                    get_debug_type($tool),
                    Tool::class,
                ));
            }

            // EVERY matching pattern is marked, not just the first, and this
            // is not tidiness. `['Bash', 'Bash(git *)']` is a legitimate pair —
            // the first grants the tool, the second narrows a call — and
            // breaking out on the first hit left the second marked unresolved,
            // so a correct grant was refused with a message saying it matched
            // no tool. Found by the ordering test, not by reading this loop.
            $hit = false;
            foreach ($patterns as $i => $namePattern) {
                if (PermissionRule::matchesToolName($namePattern, $tool->name())) {
                    $matched[$i] = true;
                    $hit = true;
                }
            }

            if ($hit) {
                $granted[] = $tool;
            }
        }

        $unresolved = [];
        foreach ($matched as $i => $hit) {
            if (!$hit) {
                $unresolved[] = $agent->tools[$i];
            }
        }

        if ($unresolved !== []) {
            throw new \RuntimeException(sprintf(
                'Agent "%s" grants %s, which match no tool this session offers (%s). '
                . 'A grant that resolves to nothing is refused rather than dropped: dropping it '
                . 'would hand the sub-agent a smaller roster than its system prompt describes.',
                $agent->name,
                implode(', ', array_map(static fn(string $d): string => '"' . $d . '"', $unresolved)),
                $this->toolRegistry === []
                    ? 'the registry is empty'
                    : implode(', ', array_map(
                        static fn(Tool $t): string => $t->name(),
                        array_filter($this->toolRegistry, static fn($t): bool => $t instanceof Tool),
                    )),
            ));
        }

        return $granted;
    }

    /**
     * Refuse a call the agent's own declaration does not cover.
     *
     * THE ARGUMENT HALF OF A GRANT IS ENFORCED HERE AND NOWHERE ELSE. The
     * roster {@see resolveGrantedTools()} builds can only carry tool NAMES —
     * `Bash(git *)` puts the whole `Bash` tool on the wire, because a tool
     * schema has no field that says "git commands only". Handing a reviewer
     * preset the unconstrained `Bash` it never asked for would be a widening
     * committed inside the fix for a lie, so the constraint is applied to the
     * CALL, where the arguments finally exist.
     *
     * {@see PermissionRule} with {@see PermissionAction::Allow} is the matcher,
     * not a second one, and its `Allow` arm is the reason this is worth doing:
     * a shell subject is split on `[;&|\r\n]+` and every segment must match, so
     * `Bash(git *)` admits `git status` and refuses `git log && rm -rf /` —
     * where a bare `fnmatch('git *', ...)` over the whole command would admit
     * both.
     *
     * NOT THE PERMISSION GATE'S JOB, AND NOT A REPLACEMENT FOR IT. The gate
     * answers "does this SESSION's policy allow this call"; this answers "did
     * this AGENT ask for this capability". A sub-agent is subject to both, in
     * that order, and this one runs even when no gate is attached — a
     * declaration is the agent's own statement about itself and does not become
     * unenforceable because the caller owns no UI.
     *
     * AN AGENT WITH NO DECLARATION IS NOT POLICED. `Agent::$tools === []` means
     * the agent says nothing about tools ({@see resolveGrantedTools()} has the
     * argument), and reading silence as "forbid everything" would refuse every
     * call for every Agent built without the field — which is most of them.
     *
     * @throws \RuntimeException When the call is outside the grant, or a
     *         declaration cannot be parsed.
     */
    private function refuseCallOutsideGrant(ToolCall $toolCall, SubAgent $subAgent): void
    {
        $declarations = $subAgent->agent->tools;
        if ($declarations === []) {
            return;
        }

        // Parsed for its side effect as well as its result: a malformed or
        // non-string declaration throws out of here rather than being treated
        // as a grant that happens not to match.
        $this->declarationNamePatterns($subAgent->agent);

        foreach ($declarations as $declaration) {
            if ((new PermissionRule((string) $declaration, PermissionAction::Allow))->matches($toolCall)) {
                return;
            }
        }

        $this->refuseToolCall(
            $toolCall,
            $subAgent,
            sprintf(
                'is outside the tool grant agent "%s" declares [%s]',
                $subAgent->agent->name,
                implode(', ', array_map(static fn($d): string => (string) $d, $declarations)),
            ),
        );
    }

    /**
     * An agent's declarations, validated, reduced to their tool-NAME halves.
     *
     * One place both {@see resolveGrantedTools()} and
     * {@see refuseCallOutsideGrant()} go through, so a declaration cannot be
     * well-formed enough to grant a tool and malformed at the moment a call
     * arrives. Keys are preserved so a caller can report WHICH declaration
     * failed against `Agent::$tools`.
     *
     * @return array<int|string, string>
     * @throws \RuntimeException On a non-string or malformed declaration.
     */
    private function declarationNamePatterns(Agent $agent): array
    {
        $patterns = [];

        foreach ($agent->tools as $i => $declaration) {
            if (!is_string($declaration)) {
                throw new \RuntimeException(sprintf(
                    'Agent "%s" declares a tool of type %s at index %s; a declaration is a %s pattern string.',
                    $agent->name,
                    get_debug_type($declaration),
                    var_export($i, true),
                    PermissionRule::class,
                ));
            }

            $reason = PermissionRule::patternRejectionReason($declaration);
            if ($reason !== null) {
                throw new \RuntimeException(sprintf(
                    'Agent "%s" declares the tool pattern "%s", which %s.',
                    $agent->name,
                    $declaration,
                    $reason,
                ));
            }

            $patterns[$i] = (new PermissionRule($declaration, PermissionAction::Allow))->toolNamePattern();
        }

        return $patterns;
    }

    /**
     * Execute multiple sub-agents in parallel via AgentWorkerPool.
     *
     * `$pool` lets a caller that already owns a call-scoped pool route through
     * this manager without losing that pool's settings: Chat builds one from
     * its AgentPoolConfig and WorkflowEngine builds one per parallel stage
     * from the workflow's maxConcurrent/stopOnFirstFailure. Before those two
     * call sites were routed here they called AgentWorkerPool::executeAll()
     * directly, so no SubAgent was ever registered and the telemetry
     * accessors above reported zeros for real work (crush_feat.md 5E item 6).
     * Omit it to fall back to the manager's own pool.
     *
     * @param SubAgent[] $agents
     * @return \Generator<AgentResult>
     * @see P1.S10 for wiring AgentWorkerPool into Chat; callers migrating from Agent[] must now pass SubAgent[]
     */
    public function executeAll(array $agents, CompleteRequest $request, ?AgentWorkerPool $pool = null): \Generator
    {
        $pool ??= $this->workerPool ?? new AgentWorkerPool();

        // Register each sub-agent so it is trackable via getSubAgent().
        $batch = [];
        foreach ($agents as $agent) {
            assert($agent instanceof SubAgent, 'executeAll requires SubAgent[]');
            if ($agent->task === '') {
                throw new \InvalidArgumentException('SubAgent task cannot be empty');
            }
            $this->subAgents[$agent->id] = $agent;
            $batch[] = $agent->id;
        }

        try {
            yield from $this->drain($pool->executeAll($agents, $request));
        } finally {
            // The pool yields a result for every sub-agent it RAN, and none for
            // the ones it did not: withStopOnFirstFailure() empties the queue on
            // the first failure and cancelAll() empties it outright, so those
            // sub-agents are never dispatched and never settled by the loop
            // above. Left at `pending`, they make isWorking() -- and through it
            // active(), the status strip and the dashboard -- report the agent
            // as `[working]` for the rest of the session with nothing running.
            // A caller that abandons this generator mid-iteration leaves the
            // same wreckage, which is why this is a finally rather than a tail.
            $this->settleAbandoned($batch);
        }
    }

    /**
     * Mirror each pool result back onto the SubAgent this manager holds.
     *
     * Split out of {@see executeAll()} so its `finally` cannot swallow or
     * reorder the yields; the settling has to happen after the LAST one.
     *
     * @param \Generator<AgentResult> $results
     * @return \Generator<AgentResult>
     */
    private function drain(\Generator $results): \Generator
    {
        foreach ($results as $result) {
            $subAgent = $this->subAgents[$result->agentId] ?? null;
            if ($subAgent !== null) {
                // The pool executes out-of-process, so the SubAgent object held
                // here never sees the streaming loop that executeSubAgent() uses
                // to accumulate usage. Mirroring AgentResult's numbers back is
                // what keeps the telemetry accessors truthful on this path --
                // which is the only path Chat/WorkflowEngine actually take.
                $subAgent->startedAt ??= $result->startedAt;
                $subAgent->completedAt = $result->completedAt ?? $subAgent->completedAt;
                $subAgent->tokensUsed += $result->tokensUsed;
                $subAgent->costUsd += $result->costUsd;
                // Status and output, for the same reason and with sharper
                // consequences: a pool-executed sub-agent used to be left at
                // `pending` with an empty buffer forever, so isWorking() (and
                // through it active(), the status strip and the dashboard)
                // would report a finished agent as still working for the rest
                // of the session, and liveOutput() would never see the text the
                // child actually produced.
                $subAgent->status = self::subAgentStatus($result->status);
                if ($result->output !== null) {
                    $subAgent->output = $result->output;
                }
                $subAgent->error ??= $result->error?->getMessage();
            }

            yield $result;
        }
    }

    /**
     * Settle every sub-agent of a batch the pool never reported on.
     *
     * `stopped` rather than `failed`: these tasks were cancelled or abandoned
     * before they ran, which is what stopSubAgent() already records for a task
     * the user killed — reporting them as failures would invent an outcome the
     * work never had. completedAt is stamped for the same reason that method
     * stamps it: elapsedSeconds() freezes the span on it, and a null there
     * counts against wall-clock forever.
     *
     * @param list<string> $batch
     */
    private function settleAbandoned(array $batch): void
    {
        foreach ($batch as $id) {
            $subAgent = $this->subAgents[$id] ?? null;
            if ($subAgent === null || $subAgent->isComplete() || $subAgent->isStopped()) {
                continue;
            }

            $subAgent->status = SubAgent::STATUS_STOPPED;
            $subAgent->completedAt ??= new \DateTimeImmutable();
            $subAgent->error ??= 'Cancelled before completion.';
        }
    }

    /**
     * Map the worker pool's {@see AgentStatus} onto {@see SubAgent}'s own
     * status vocabulary.
     *
     * The two enumerations exist because SubAgent predates AgentStatus and
     * carries string constants; they are not merged here because SubAgent's
     * set is a strict subset (it has no Queued) and collapsing Queued onto
     * `pending` is a decision this mapping should state rather than a rename
     * should hide. TimedOut folds onto `failed` for the same reason
     * {@see \SugarCraft\Crush\Tui\Components\AgentDashboardPane::sessionStatus()}
     * folds it: from the caller's side a timeout is a failure that happens to
     * have a cause.
     */
    private static function subAgentStatus(AgentStatus $status): string
    {
        return match ($status) {
            AgentStatus::Pending, AgentStatus::Queued => SubAgent::STATUS_PENDING,
            AgentStatus::Running => SubAgent::STATUS_RUNNING,
            AgentStatus::Streaming => SubAgent::STATUS_STREAMING,
            AgentStatus::Completed => SubAgent::STATUS_COMPLETE,
            AgentStatus::Stopped => SubAgent::STATUS_STOPPED,
            AgentStatus::Failed, AgentStatus::TimedOut => SubAgent::STATUS_FAILED,
        };
    }

    /**
     * Stop a subagent.
     */
    public function stopSubAgent(string $id): void
    {
        $subAgent = $this->getSubAgent($id);
        if ($subAgent === null) {
            return;
        }

        $subAgent->status = SubAgent::STATUS_STOPPED;
        // Stopping ends the work span; leaving completedAt null would make
        // elapsedSeconds() tick up forever for an agent the user killed.
        $subAgent->completedAt ??= new \DateTimeImmutable();
    }

    /**
     * Remove a completed subagent.
     */
    public function removeSubAgent(string $id): void
    {
        unset($this->subAgents[$id]);
    }

    // -------------------------------------------------------------------------
    // Team management
    // -------------------------------------------------------------------------

    /**
     * Set the team manager instance.
     */
    public function setTeamManager(TeamManager $teamManager): void
    {
        $this->teamManager = $teamManager;
    }

    /**
     * Get the team manager instance.
     */
    public function getTeamManager(): ?TeamManager
    {
        return $this->teamManager;
    }

    /**
     * Create a new team and register it.
     *
     * @throws \RuntimeException When no team manager is set
     * @throws \InvalidArgumentException When a team with the given ID already exists
     */
    public function createTeam(
        string $teamId,
        string $name,
        string $leadAgentId,
        ?TeamConfig $config = null,
    ): Team {
        if ($this->teamManager === null) {
            throw new \RuntimeException('TeamManager has not been set on AgentManager');
        }

        return $this->teamManager->createTeam($teamId, $name, $leadAgentId, $config);
    }

    /**
     * Fetch a team by its ID.
     *
     * @throws \RuntimeException When no team manager is set
     */
    public function getTeam(string $teamId): ?Team
    {
        if ($this->teamManager === null) {
            throw new \RuntimeException('TeamManager has not been set on AgentManager');
        }

        return $this->teamManager->getTeam($teamId);
    }

    /**
     * Check whether a team exists (is registered).
     *
     * @throws \RuntimeException When no team manager is set
     */
    public function hasTeam(string $teamId): bool
    {
        if ($this->teamManager === null) {
            throw new \RuntimeException('TeamManager has not been set on AgentManager');
        }

        return $this->teamManager->hasTeam($teamId);
    }

    /**
     * Unregister and return a team by ID.
     *
     * @throws \RuntimeException When no team manager is set
     */
    public function removeTeam(string $teamId): ?Team
    {
        if ($this->teamManager === null) {
            throw new \RuntimeException('TeamManager has not been set on AgentManager');
        }

        return $this->teamManager->removeTeam($teamId);
    }

    /**
     * Return all registered teams.
     *
     * @return Team[]
     * @throws \RuntimeException When no team manager is set
     */
    public function getTeams(): array
    {
        if ($this->teamManager === null) {
            throw new \RuntimeException('TeamManager has not been set on AgentManager');
        }

        return $this->teamManager->getTeams();
    }

    /**
     * Return the number of currently registered teams.
     *
     * @throws \RuntimeException When no team manager is set
     */
    public function teamCount(): int
    {
        if ($this->teamManager === null) {
            throw new \RuntimeException('TeamManager has not been set on AgentManager');
        }

        return $this->teamManager->teamCount();
    }
}
