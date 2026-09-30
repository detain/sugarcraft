<?php

declare(strict_types=1);

namespace SugarCraft\Mcp;

/**
 * The lifecycle every MCP server transport must honour: start, expose its
 * tool table, answer tool calls, stop.
 *
 * Extracted from the sugar-crush MCP stack (src/MCP/McpServer.php). Phase 1
 * ships one implementation (StdioMcpServer); the HTTP transport remains
 * downstream until Phase 2.
 *
 * Consumers route through McpRouter rather than calling a server directly, so
 * allow/deny narrowing applies uniformly no matter which transport answers.
 */
interface McpServer
{
    /**
     * Bring the server up and complete the MCP handshake (initialize →
     * notifications/initialized → tools/list), caching the tool table.
     *
     * @throws \RuntimeException when the server will not come up.
     */
    public function start(): void;

    /**
     * Tear the server down. Must be safe to call when never started and to
     * call twice; implementations must leave no child process behind.
     */
    public function stop(): void;

    /**
     * The tool table captured at start(), each entry tagged with this
     * server's name.
     *
     * @return list<McpTool>
     */
    public function listTools(): array;

    /**
     * Invoke a tool and return the MCP result payload as an array.
     *
     * Implementations wrap non-array results in the standard
     * `{"content":[{"type":"text","text":...}]}` shape, and report failure as
     * `{"error": ...}` rather than throwing, because callers render the error
     * into a model-facing transcript.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function callTool(string $name, array $arguments): array;
}
