<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * Interface for MCP servers.
 *
 * Implementations:
 * - {@see GitMcpServer} – pure-PHP Git operations (git status, commit, branch, etc.)
 *   wired via {@see GitCommandHandlers}; registered in the container or instantiated
 *   directly, not loaded from the MCP config file.
 * - {@see StdioMcpServer} – stdio-based external MCP server (configured via
 *   mcpServers[].type=stdio in the MCP config).
 * - {@see HttpMcpServer} – HTTP-based external MCP server (configured via
 *   mcpServers[].type=http in the MCP config).
 *
 * Routing: Per-agent MCP routing (mcpServers allowlist + wildcard deny patterns)
 * is implemented in {@see McpRouter::resolveAllowedTools()}. McpServer
 * implementations delegate routing decisions to McpRouter, which applies deny
 * patterns first, then restricts tools to those in the preset's mcpServers
 * allowlist. Clients should call McpRouter::resolveAllowedTools() rather than
 * calling listTools() directly on a server.
 *
 * @see McpRouter::resolveAllowedTools()
 */
interface McpServer
{
    public function start(): void;

    public function stop(): void;

    /**
     * @return array<McpTool>
     */
    public function listTools(): array;

    /**
     * @return array<mixed>
     */
    public function callTool(string $toolName, array $args): array;
}
