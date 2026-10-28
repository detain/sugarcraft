<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

/**
 * Capability seam for transports that can answer the server's own
 * mid-call requests (C-MCP-3).
 *
 * Deliberately NOT part of {@see McpServer}: the contract stays what it
 * always was, and callers discover this capability the same way they
 * already discover `pumpStderr()` — a narrowed `instanceof` at the one
 * call site that wants it. That narrowing is precedent, not laziness: the
 * stdio transport shares a pipe with the server and can interleave an
 * answer inside a read; the HTTP arms carry no downward channel yet, and
 * pretending otherwise would advertise a capability that cannot deliver.
 *
 * @see \SugarCraft\Code\MCP\McpClient::callTool() the consuming call site
 * @see \SugarCraft\Code\MCP\McpClient::pumpStderr() the same pattern
 */
interface AnswersServerRequests
{
    /**
     * The bounded `callTool` with an exchange-scoped answer desk: every
     * request or notification the server sends while this one call awaits
     * its response is routed through $serverRequests instead of the
     * transport's default answer, and the wall clock the answer desk spends
     * is paused out of the call's timeout budget.
     *
     * @param array<string,mixed> $args
     * @param (\Closure(): void)|null $onWait
     * @return array<mixed>
     */
    public function callToolAnsweringRequests(string $toolName, array $args, ?\Closure $onWait = null, ?McpServerRequests $serverRequests = null): array;
}
