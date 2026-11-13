<?php

declare(strict_types=1);

namespace SugarCraft\McpClient;

/**
 * The contract every MCP server transport must honour: start, expose its
 * tool table, answer tool calls, stop — plus the two raw RPC legs
 * (`request()`/`notify()`) every transport speaks, so code typed against
 * this interface can reach protocol traffic beyond the tool surface
 * without growing a second type per transport family.
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
     * implementation. The order of anything trailing the shared pair is
     * concrete-class API: on `StdioMcpServer`, `onWait:` is third and
     * `timeoutSeconds:` fourth — positional callers past the shared pair
     * write against that class, never against this contract.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>
     */
    public function callTool(string $name, array $arguments): array;

    /**
     * One raw request/response round trip — the leg `callTool()` rides,
     * exposed for protocol traffic beyond the tool surface (resources,
     * prompts, completion) without this contract growing speculative
     * convenience methods.
     *
     * `$deadline` is an ABSOLUTE monotonic instant (`hrtime` basis), never a
     * duration and never wall clock; transports that bound waits refuse
     * implausible magnitudes loudly. The answer is the reply as parsed
     * JSON-RPC — a server's own error REPLY is not a failure: it arrives as
     * an error-bearing message so the caller can read the server's
     * diagnosis — while `null` means the exchange itself did not complete
     * (not started, dead pipe, deadline lost).
     *
     * @param array<string,mixed>|null $params
     */
    public function request(string $method, ?array $params = null, ?float $deadline = null): ?McpMessage;

    /**
     * One-way leg: send a notification, await nothing (JSON-RPC promises no
     * reply). Same absolute-monotonic `$deadline` law as
     * {@see self::request()}.
     *
     * @param array<string,mixed>|null $params
     * @return bool true once the frame went out whole; false when it did not
     *         (not started, dead pipe, deadline lost)
     */
    public function notify(string $method, ?array $params = null, ?float $deadline = null): bool;
}
