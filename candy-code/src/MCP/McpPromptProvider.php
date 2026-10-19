<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

/**
 * The prompts capability of an {@see McpServer}, split out per the same
 * narrowed-`instanceof` law as {@see McpResourceProvider} — GitMcpServer and
 * ClaudeCodeMcpServer answer neither, and the McpServer interface must not
 * grow methods they can only fake.
 *
 * MCP-2 (plan_comparison §3): the WIRE half only. `prompts/get` is answered
 * in its call shape; NOTHING here materializes a prompt as a slash command,
 * a Chat input, or a transcript block — that surface is a later lane.
 *
 * RAW BYTES LAW and READ ON DEMAND LAW apply exactly as documented on
 * {@see McpResourceProvider}: prompt TEXT is third-party server output and
 * rides back unsanitized for the surface to fence, and the roster is never
 * cached because servers add prompts while running.
 */
interface McpPromptProvider
{
    /**
     * The server's current `prompts/list`, cursor pages followed to the
     * end, entries in wire order.
     *
     * @return list<array<mixed>> the raw `prompts` rows
     */
    public function listPrompts(): array;

    /**
     * One `prompts/get` round trip; the full raw `result` member comes back.
     *
     * @param array<string,mixed> $arguments the server-declared prompt
     *        arguments; empty encodes as a JSON OBJECT on the wire (the SDK
     *        servers reject the array form — the audit MCP-1/MCP-2 shape law
     *        already enforced for `tools/call`)
     * @return array<mixed> the raw `result` of the reply
     */
    public function getPrompt(string $name, array $arguments = []): array;
}
