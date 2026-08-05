<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Chat;

/**
 * Implements the /agents and /agent commands for listing and inspecting agents.
 *
 * Usage:
 *   /agents        — list all active agents with their status
 *   /agent <name>  — show details for a specific agent
 *
 * @mirrors charmbracelet/crush AgentsCommand
 */
final class AgentsCommand
{
    public function __construct(
        private readonly AgentManager $agentManager,
    ) {
    }

    /**
     * Execute the /agents or /agent command.
     *
     * @param Chat  $chat  The current chat session
     * @param array $args  Parsed command arguments (from CommandParser)
     * @return int         Exit code: 0 on success, non-zero on failure
     */
    public function execute(Chat $chat, array $args = []): int
    {
        // /agents with no args lists all active agents
        if ($args === []) {
            return $this->listAgents();
        }

        // /agent <name> shows details for a specific agent
        $agentName = $args[0];

        return $this->showAgent($agentName);
    }

    /**
     * List all active agents with their status.
     */
    private function listAgents(): int
    {
        $agents = $this->agentManager->active();

        if ($agents === []) {
            echo "\n  No active agents configured.\n";
            echo "  Use the agents configuration file to define agents.\n\n";

            return 0;
        }

        echo "\n  Active Agents:\n";
        echo "  " . str_repeat("─", 50) . "\n";

        foreach ($agents as $agent) {
            $status = $agent->isActive ? "● active" : "○ inactive";
            $nameLen = strlen($agent->name);
            $descLen = 40 - $nameLen;
            $desc = strlen($agent->description) > $descLen
                ? substr($agent->description, 0, $descLen - 3) . "..."
                : $agent->description;

            echo "  {$agent->name}";
            echo str_repeat(" ", max(1, 20 - $nameLen));
            echo $desc;
            echo str_repeat(" ", max(1, 42 - strlen($desc) - $nameLen));
            echo $status . "\n";
        }

        echo "\n";

        return 0;
    }

    /**
     * Show detailed information for a specific agent.
     */
    private function showAgent(string $name): int
    {
        $agent = $this->agentManager->get($name);

        if ($agent === null) {
            echo "\n  Unknown agent: {$name}\n";
            echo "  Use /agents to see available agents.\n\n";

            return 1;
        }

        echo "\n  Agent: {$agent->name}\n";
        echo "  " . str_repeat("─", 50) . "\n";
        echo "  Description: {$agent->description}\n";
        echo "  Model:       {$agent->model}\n";
        echo "  Provider:     {$agent->provider}\n";
        echo "  Status:       " . ($agent->isActive ? "active" : "inactive") . "\n";

        if ($agent->skillNames !== []) {
            echo "  Skills:      " . implode(", ", $agent->skillNames) . "\n";
        }

        if ($agent->tools !== []) {
            echo "  Tools:       " . implode(", ", $agent->tools) . "\n";
        }

        if ($agent->hooks !== []) {
            echo "  Hooks:       " . implode(", ", $agent->hooks) . "\n";
        }

        echo "\n";

        return 0;
    }
}
