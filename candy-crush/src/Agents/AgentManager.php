<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;

final class AgentManager
{
    /** @var array<string, Agent> */
    private array $agents = [];

    /** @var array<string, SubAgent> */
    private array $subAgents = [];

    public function __construct(
        private ProviderInterface $provider,
        private SkillRegistry $skillRegistry,
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
     */
    public function createSubAgent(string $agentName, string $task): SubAgent
    {
        $agent = $this->get($agentName);
        if ($agent === null) {
            throw new \RuntimeException("Unknown agent: $agentName");
        }

        $subAgent = new SubAgent(
            id: uniqid('subagent_'),
            agent: $agent,
            task: $task,
            status: SubAgent::STATUS_PENDING,
        );

        $this->subAgents[$subAgent->id] = $subAgent;

        return $subAgent;
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
     */
    public function executeSubAgent(string $id): \Generator
    {
        $subAgent = $this->getSubAgent($id);
        if ($subAgent === null) {
            return;
        }

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
                $subAgent->output .= $response->content;
                yield $subAgent;
            }
        } else {
            $response = $this->provider->complete($request);
            $subAgent->output = $response->content;
        }

        $subAgent->status = SubAgent::STATUS_COMPLETE;
        $subAgent->completedAt = new \DateTimeImmutable();
    }

    /**
     * Stop a subagent.
     */
    public function stopSubAgent(string $id): void
    {
        $subAgent = $this->getSubAgent($id);
        if ($subAgent !== null) {
            $subAgent->status = SubAgent::STATUS_STOPPED;
        }
    }

    /**
     * Remove a completed subagent.
     */
    public function removeSubAgent(string $id): void
    {
        unset($this->subAgents[$id]);
    }
}
