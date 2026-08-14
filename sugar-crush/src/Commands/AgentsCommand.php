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
 *   /agents        — list the agents currently working, or the idle roster count
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
     * List the agents that are currently WORKING, falling back to a count of
     * the registered-but-idle roster when none of them is.
     *
     * "Active" on {@see AgentManager::active()} means *currently doing work*,
     * not *configured*, so an empty result is the normal state of a healthy
     * launch rather than an empty manager — which is why the two no-work
     * branches below have to say different things.
     */
    private function listAgents(): int
    {
        $agents = $this->agentManager->active();

        if ($agents === []) {
            // "Active" means *currently working* (see AgentManager::active()),
            // so on a real launch this is the normal idle state, not an empty
            // manager -- Bootstrap::agentRoster() registers six built-in agents
            // plus any on-disk preset. The two branches are worded apart on
            // purpose: pairing "No active agents configured." with "9 agent(s)
            // registered and idle" read as a self-contradiction, when what is
            // true is that nine agents exist and none is busy. The names are
            // reachable through `/agent <name>`, which does not filter on
            // active.
            $idle = count($this->agentManager->all());
            if ($idle > 0) {
                echo "\n  No agents are working right now.\n";
                echo "  {$idle} agent(s) registered and idle — use /agent <name> for details.\n\n";

                return 0;
            }

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
