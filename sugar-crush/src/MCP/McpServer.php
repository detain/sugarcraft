<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

require_once __DIR__ . '/../../../vendor/autoload.php';

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
