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
        return array_values(array_filter(
            $serverNames,
            static fn(string $name): bool => self::serverAllowed($name, $allowList),
        ));
    }

    /**
     * Whether ONE server passes a preset's `mcpServers` allowlist.
     *
     * THE LAW, EXPOSED. `applyAllowList()` filters a whole server map; the
     * sub-agent roster in {@see \SugarCraft\Crush\Agents\AgentManager::resolveGrantedTools()}
     * answers the same question one entry at a time (E696-α: MCP bridges are
     * narrowed out of a preset's grant at resolution). Both must read the same
     * law — empty list allows all, `*` entries `fnmatch`, everything else is
     * exact equality on the RAW config server key — or a preset's roster and
     * its router view diverge, which is the two-dialects defect PermissionRule
     * refuses to host for tool names. One spelling of the membership rule, one
     * implementation; the filter delegates here rather than mirroring it.
     *
     * RAW BOTH SIDES ON PURPOSE: entries are compared against the `.mcp.json`
     * key as configured, never against the sanitised `mcp__<key>__` wire
     * spelling (E42) — a caller holding only a wire name must not route it in
     * here; {@see \SugarCraft\Crush\Tools\McpToolBridge::descriptor()} carries
     * the raw half.
     *
     * @param array<array-key, mixed> $allowList The preset's `mcpServers` field,
     *        untrusted shape — a foreign import casts with `(array)` only.
     * @throws \RuntimeException when the list is non-empty and an entry is not
     *         a non-empty string: a malformed allowlist fails loud rather than
     *         reading as a rule that matches nothing, on the same argument
     *         {@see \SugarCraft\Crush\Agents\AgentManager::namePatterns()} gives
     *         for the grant lists.
     */
    public static function serverAllowed(string $server, array $allowList): bool
    {
        if ($allowList === []) {
            return true;
        }

        foreach ($allowList as $entry) {
            if (!is_string($entry) || $entry === '') {
                throw new \RuntimeException(sprintf(
                    'An mcpServers allowlist entry must be a non-empty string, %s given; '
                    . 'a server allowlist is refused rather than read as a rule that matches nothing.',
                    get_debug_type($entry),
                ));
            }

            if (str_contains($entry, '*') ? fnmatch($entry, $server) : $entry === $server) {
                return true;
            }
        }

        return false;
    }
}
