<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

/**
 * The server refused the session at the HTTP layer with 401/403.
 *
 * ISS-042: this shape used to be indistinguishable from any other start
 * failure. `startServer()` catches a failed `start()` and skips the server in
 * SILENCE — the runtime-failure class it has always swallowed on purpose —
 * so an MCP endpoint that was merely NOT YET LOGGED IN produced a vanished
 * tool set with no diagnosis anywhere: the operator could see the server was
 * missing from `/mcp`'s live rows but nothing said "log in", even though
 * `candy-code mcp auth login <server>` is exactly the fix. The distinction is
 * why this is its OWN class rather than a message string: an auth refusal is
 * a CONFIG-shaped event (the human can act on it from the transcript) wearing
 * a RUNTIME-shaped failure, and only the typed form lets {@see McpClient}
 * route it to the launch notice without re-reading exception prose.
 *
 * Carries the server NAME because the remediation is a command line an
 * operator types; {@see McpClient::authRequiredNotice()} composes it into
 * one row per refusing server.
 */
final class HttpMcpAuthRequiredException extends \RuntimeException
{
    public function __construct(
        public readonly string $serverName,
        public readonly int $status,
    ) {
        parent::__construct(sprintf(
            'HTTP %d — the server refused our credentials',
            $status,
        ));
    }
}
