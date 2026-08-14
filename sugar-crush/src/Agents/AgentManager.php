<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\ToolCall;

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
     */
    public function __construct(
        private ProviderInterface $provider,
        private SkillRegistry $skillRegistry,
        private ?AgentWorkerPool $workerPool = null,
        private ?\Closure $permissionGateFactory = null,
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
            id: uniqid('subagent_'),
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
     * Every registered agent's live output buffer, keyed by agent name, with
     * silent agents omitted.
     *
     * This is the multi-agent shape the tmux/iTerm2 split-pane compositor
     * ({@see \SugarCraft\Crush\Tui\Renderer::renderWithSplit()}) needs: it lays
     * several agents' live output out side by side, so it wants "who is
     * producing text right now", not one name at a time. Omitting the silent
     * ones is what keeps it from rendering a row of empty tiles — the exact
     * reason that compositor was deferred rather than wired.
     *
     * Keyed off the REGISTERED agents rather than off the sub-agent map, so a
     * sub-agent whose registration was removed cannot resurrect a pane.
     *
     * @return array<string, string> agent name => live output
     */
    public function liveOutputs(): array
    {
        $outputs = [];
        foreach ($this->agents as $name => $agent) {
            $output = $this->liveOutput($agent->name);
            if ($output !== '') {
                $outputs[$name] = $output;
            }
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

            // Run completion
            $request = new \SugarCraft\Crush\Providers\CompleteRequest(
                model: $subAgent->agent->model,
                messages: [
                    new \SugarCraft\Crush\Messages\UserMessage($subAgent->task),
                ],
                systemPrompt: $systemPrompt,
            );

            if ($this->provider->supportsStreaming()) {
                $subAgent->status = SubAgent::STATUS_STREAMING;

                foreach ($this->provider->completeStream($request) as $response) {
                    // Evaluate tool calls through the permission gate if set
                    if ($response->toolCalls !== null && $subAgent->permissionGate !== null) {
                        $this->evaluateToolCalls($response->toolCalls, $subAgent);
                    }

                    // Accumulated per chunk so a mid-flight sub-agent already
                    // reports real usage; providers report per-chunk deltas,
                    // hence += rather than assignment.
                    $subAgent->tokensUsed += $response->tokensUsed;
                    $subAgent->costUsd += $response->costUsd;

                    $subAgent->output .= $response->content;
                    yield $subAgent;
                }
            } else {
                $response = $this->provider->complete($request);

                // Evaluate tool calls through the permission gate if set
                if ($response->toolCalls !== null && $subAgent->permissionGate !== null) {
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
     * Evaluate tool calls through the sub-agent's permission gate.
     * Denied tool calls cause the sub-agent to fail immediately.
     * Ask decisions also fail since sub-agents cannot prompt for user input.
     *
     * @param array<ToolCall> $toolCalls
     * @throws \RuntimeException When a tool call is denied or requires user input
     */
    private function evaluateToolCalls(array $toolCalls, SubAgent $subAgent): void
    {
        if ($subAgent->permissionGate === null) {
            return;
        }

        foreach ($toolCalls as $toolCall) {
            $decision = $subAgent->permissionGate->evaluate($toolCall);

            if ($decision === \SugarCraft\Crush\Permissions\PermissionDecision::Deny) {
                $subAgent->status = SubAgent::STATUS_FAILED;
                $subAgent->error = sprintf(
                    'Tool call "%s" denied by permission gate (mode: %s)',
                    $toolCall->name,
                    $subAgent->permissionGate->mode()->value,
                );
                throw new \RuntimeException($subAgent->error);
            }

            if ($decision === \SugarCraft\Crush\Permissions\PermissionDecision::Ask) {
                $subAgent->status = SubAgent::STATUS_FAILED;
                $subAgent->error = sprintf(
                    'Tool call "%s" requires user input but sub-agents cannot prompt (mode: %s)',
                    $toolCall->name,
                    $subAgent->permissionGate->mode()->value,
                );
                throw new \RuntimeException($subAgent->error);
            }
        }
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
