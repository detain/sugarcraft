<?php
declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use SugarCraft\Crush\Agents\AgentPreset;

/**
 * Routes MCP tool access per-agent, respecting per-preset allowlists and
 * global wildcard deny patterns.
 *
 * An agent preset's `mcpServers` field names only the servers that preset
 * actually needs. Wildcard deny patterns like "untrusted_*" block whole
 * families of servers regardless of which preset is active.
 *
 * @see https://github.com/sugarcraft/sugar-crush/crush_code_plan.md P7.S8
 */
final class McpRouter
{
    /**
     * @param array<string, McpServer>  $servers     Globally configured servers, keyed by name
     * @param array<string, string>      $denyPatterns Map of server-name pattern => "deny"
     */
    public function __construct(
        private array $servers,
        private array $denyPatterns = [],
    ) {}

    /**
     * Filter tools to only those the given preset is allowed to see.
     *
     * Logic (in order):
     *  1. Strip servers matching any global deny pattern (fnmatch wildcards)
     *  2. If the preset has a non-empty mcpServers list, strip everything else
     *
     * @return array<McpTool>
     */
    public function resolveAllowedTools(AgentPreset $preset): array
    {
        $allowed = $this->applyDenyPatterns(array_keys($this->servers));
        $allowed = $this->applyAllowList($allowed, $preset->mcpServers);

        $tools = [];
        foreach ($allowed as $serverName) {
            $tools = array_merge($tools, $this->servers[$serverName]->listTools());
        }

        return $tools;
    }

    /**
     * Returns the list of server names that would be allowed for a given preset,
     * after both deny patterns and allowlist have been applied.
     *
     * @return array<string>
     */
    public function resolveAllowedServers(AgentPreset $preset): array
    {
        $allowed = $this->applyDenyPatterns(array_keys($this->servers));

        return $this->applyAllowList($allowed, $preset->mcpServers);
    }

    /**
     * Remove servers matching any deny pattern (fnmatch wildcards supported).
     *
     * @param array<string> $serverNames
     * @return array<string>
     */
    private function applyDenyPatterns(array $serverNames): array
    {
        if ($this->denyPatterns === []) {
            return $serverNames;
        }

        return array_values(array_filter(
            $serverNames,
            fn(string $name): bool => !$this->matchesAnyDenyPattern($name)
        ));
    }

    /**
     * Check if a server name matches any deny pattern.
     */
    private function matchesAnyDenyPattern(string $serverName): bool
    {
        foreach ($this->denyPatterns as $pattern => $action) {
            if ($action === 'deny' && fnmatch($pattern, $serverName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply the preset's mcpServers allowlist. An empty allowlist means "all are
     * allowed" (after deny patterns have been applied).
     *
     * @param array<string> $serverNames
     * @param array<string> $allowList
     * @return array<string>
     */
    private function applyAllowList(array $serverNames, array $allowList): array
    {
        if ($allowList === []) {
            return $serverNames;
        }

        // Expand any glob patterns in the allowlist
        $expandedAllowList = [];
        foreach ($allowList as $entry) {
            if (str_contains($entry, '*')) {
                foreach ($serverNames as $name) {
                    if (fnmatch($entry, $name)) {
                        $expandedAllowList[] = $name;
                    }
                }
            } else {
                $expandedAllowList[] = $entry;
            }
        }

        return array_values(array_intersect($serverNames, $expandedAllowList));
    }
}
