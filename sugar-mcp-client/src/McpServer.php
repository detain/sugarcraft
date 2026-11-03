<?php

declare(strict_types=1);

namespace SugarCraft\McpClient;

/**
 * The lifecycle every MCP server transport must honour: start, expose its
 * tool table, answer tool calls, stop.
 *
 * Extracted from the candy-code MCP stack (src/MCP/McpServer.php). One
 * implementation ships today (StdioMcpServer); HTTP/SSE transports remain
 * downstream.
 *
 * Calling a server directly is the expected shape for a single-server embed.
 * `McpRouter` exists for the OTHER case — narrowing WHICH servers or tools a
 * caller may reach from a config's allow/deny policy. It filters lists of
 * servers; it never proxies traffic itself.
 */
interface McpServer
{
    /**
     * Bring the server up and complete the MCP handshake (initialize →
     * notifications/initialized → tools/list), caching the tool table.
     *
     * `StdioMcpServer::start()` additionally accepts an `onWait:` beat as a
     * concrete-class opt-in for liveness across the whole handshake.
     *
     * @throws \RuntimeException when the server will not come up (spawn
     *         refusal, a refused or unanswered handshake leg, or a protocol
     *         version the client will not speak).
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
     * Implementations may accept MORE than the two parameters declared here
     * (`StdioMcpServer` takes `onWait:`, `timeoutSeconds:` and
     * `onServerMessage:`), but those are concrete-class opt-ins: code typed
     * to this interface sees exactly this signature — including the parameter
     * NAMES, so `callTool(name: ..., arguments: ...)` binds on every
     * implementation.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function callTool(string $name, array $arguments): array;
}
