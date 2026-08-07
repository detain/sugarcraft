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
    /** @var array<string, Agent> */
    private array $agents = [];

    /** @var array<string, SubAgent> */
    private array $subAgents = [];

    private ?TeamManager $teamManager = null;

    /**
     * Tracks the permission mode locked for this session.
     * Once the first sub-agent is created, the session mode is sealed.
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
     * @return array<Agent>
     */
    public function active(): array
    {
        return array_values(array_filter(
            $this->agents,
            fn($agent) => $agent->isActive
        ));
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
        // Plan spec says BypassPermissions can only be set at launch — once any
        // sub-agent is created the session's permission mode is sealed to what
        // was used first. Throwing LogicException makes the contract explicit.
        if ($this->sessionPermissionMode !== null && $this->sessionPermissionMode !== $mode) {
            throw new \LogicException('Permission mode cannot be changed mid-session');
        }
        $this->sessionPermissionMode ??= $mode;

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
            }

            $subAgent->status = SubAgent::STATUS_COMPLETE;
            $subAgent->completedAt = new \DateTimeImmutable();
        } catch (\Throwable $e) {
            $subAgent->status = SubAgent::STATUS_FAILED;
            $subAgent->error = $e->getMessage();
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
     * @param SubAgent[] $agents
     * @return \Generator<AgentResult>
     * @see P1.S10 for wiring AgentWorkerPool into Chat; callers migrating from Agent[] must now pass SubAgent[]
     */
    public function executeAll(array $agents, CompleteRequest $request): \Generator
    {
        $pool = $this->workerPool ?? new AgentWorkerPool();

        // Register each sub-agent so it is trackable via getSubAgent().
        foreach ($agents as $agent) {
            assert($agent instanceof SubAgent, 'executeAll requires SubAgent[]');
            if ($agent->task === '') {
                throw new \InvalidArgumentException('SubAgent task cannot be empty');
            }
            $this->subAgents[$agent->id] = $agent;
        }

        yield from $pool->executeAll($agents, $request);
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
