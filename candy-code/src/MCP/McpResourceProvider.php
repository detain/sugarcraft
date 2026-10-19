<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

/**
 * The resources capability of an {@see McpServer}, split out the way the
 * stderr pump and the liveness readout were split out before it: NOT every
 * transport speaks every part of the protocol, and widening {@see McpServer}
 * would force GitMcpServer and ClaudeCodeMcpServer to fake answers they have
 * no channel for. Callers narrow with `instanceof` — the pumpStderr precedent
 * ({@see McpClient::pumpStderr()}: "only a spawned child has an fd 2").
 *
 * MCP-1 (plan_comparison §3): the wire half only. The product surface —
 * `@resource:` mentions, the notice conversion, the PromptFence framing of
 * returned bytes — is a later lane and is deliberately NOT this interface's
 * concern.
 *
 * RAW BYTES LAW: what comes back is THIRD-PARTY SERVER OUTPUT. These methods
 * return it exactly as the wire answered; nothing here sanitizes, truncates,
 * or re-encodes. Framing untrusted content for the model transcript is the
 * surface's job (PromptFence), and framing it for a human is the panel's —
 * a transport that quietly scrubbed would leave both downstream layers
 * believing they had already seen the danger.
 *
 * READ ON DEMAND LAW (launch-frozen roster): unlike `tools/list`, a resource
 * roster is NEVER cached. Servers mutate their resource lists while running,
 * and the launch-time freeze exists to make the TOOL surface stable — not to
 * make every protocol answer a snapshot. Every call here round-trips.
 */
interface McpResourceProvider
{
    /**
     * The server's current `resources/list`, cursor pages followed to the
     * end, entries in wire order. Throws when the server refuses (error
     * reply, dead pipe, or a pagination loop) — a silently empty roster is
     * the defect this method's error gates exist to close.
     *
     * @return list<array<mixed>> the raw `resources` rows
     */
    public function listResources(): array;

    /**
     * One `resources/read` round trip. The full raw `result` member comes
     * back (`contents` and everything the server chose to add to it) —
     * unvalidated by design per the RAW BYTES LAW.
     *
     * @return array<mixed> the raw `result` of the reply
     */
    public function readResource(string $uri): array;
}
