<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

/**
 * Interface for MCP servers.
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
