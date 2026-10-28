<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol\Methods;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Cli\McpDecision;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\Protocol\CallContext;
use SugarCraft\Code\Protocol\MethodRegistry;
use SugarCraft\Code\Protocol\MethodSpec;
use SugarCraft\Code\Protocol\Params;
use SugarCraft\Code\Protocol\Scope;

/**
 * MCP surface rows over the wire (C-MCP-2.b, GAP-MCP-07).
 *
 * `mcp.resources` answers the per-server resource rows the launch-time
 * surface pass remembered — the same bounded snapshot the `/mcp` panel
 * counts — so a client can show what a started server offers without ever
 * asking the server again. Like every surface readout this is display data,
 * never enforcement: the strict per-call round trips inside {@see
 * \SugarCraft\Code\MCP\McpClient::listResources()} stay the live authority.
 *
 * The method reads the process-memoised client ONLY. A cold peek answers
 * `{status: "not-started"}` rather than building one: listing resources must
 * never launch a server any more than a popup may run a tool.
 *
 * Hostile bytes are bounded HERE, at the surface, per the RAW BYTES LAW on
 * {@see \SugarCraft\Code\MCP\McpResourceProvider}: transports hand rows up
 * verbatim, and each answerable field is flattened and clipped before it
 * reaches a client.
 */
final class McpMethods
{
    /** Byte caps per wire field — bounds, not truncation policy for humans. */
    private const URI_MAX_CHARS = 512;
    private const NAME_MAX_CHARS = 200;
    private const MIME_MAX_CHARS = 128;
    private const DESCRIPTION_MAX_CHARS = 400;

    private function __construct()
    {
    }

    public static function register(MethodRegistry $registry): void
    {
        $registry->add(MethodSpec::new(
            'mcp.resources',
            Scope::Read,
            'The launch-frozen MCP resource rows of the session root, without starting any server.',
            self::resources(...),
        ));
    }

    /** @return array<string, mixed> */
    private static function resources(CallContext $call, Params $params): array
    {
        $host = $call->host(SessionMethods::sessionId($params));
        $client = self::memoisedClient($host->workspace()->root);
        if ($client === null) {
            return ['status' => 'not-started', 'servers' => []];
        }

        $servers = [];
        foreach ($client->resourcesRoster() as $name => $resources) {
            $rows = [];
            foreach ($resources as $resource) {
                if (\is_array($resource)) {
                    $rows[] = self::row($resource);
                }
            }
            $servers[] = ['server' => self::bound((string) $name, self::NAME_MAX_CHARS), 'resources' => $rows];
        }

        return ['status' => 'started', 'servers' => $servers];
    }

    /**
     * The memo-only client peek — {@see Bootstrap::mcpPinRefusals()}'s shape,
     * never its builder path: the decision is re-derived to compute the memo
     * key, but a miss answers null instead of starting servers.
     */
    private static function memoisedClient(?string $root): ?McpClient
    {
        $key = McpDecision::mcpConfigMemoKey(Bootstrap::mcpConfigDecision($root));
        $pid = \getmypid() ?: 0;
        if (!LaunchContext::hasMcpClient($pid, $key)) {
            return null;
        }

        return LaunchContext::mcpClientFor($pid, $key);
    }

    /**
     * One bounded resource row. `uri` and `name` are the MCP spec's required
     * members; `mimeType` and `description` are optional and answer null when
     * the server did not carry them (or carried them non-textually).
     *
     * @param array<array-key, mixed> $resource
     *
     * @return array{uri: string, name: string, mimeType: string|null, description: string|null}
     */
    private static function row(array $resource): array
    {
        return [
            'uri' => self::bound(self::text($resource['uri'] ?? null), self::URI_MAX_CHARS),
            'name' => self::bound(self::text($resource['name'] ?? null), self::NAME_MAX_CHARS),
            'mimeType' => self::optionalBound($resource['mimeType'] ?? null, self::MIME_MAX_CHARS),
            'description' => self::optionalBound($resource['description'] ?? null, self::DESCRIPTION_MAX_CHARS),
        ];
    }

    /** A wire member that must answer a string whatever the server sent. */
    private static function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) || \is_float($value) ? (string) $value : '';
    }

    /** @return string|null */
    private static function optionalBound(mixed $value, int $maxChars): ?string
    {
        if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
            return null;
        }

        $bound = self::bound((string) $value, $maxChars);

        return $bound === '' ? null : $bound;
    }

    /**
     * Control/unknown codepoints collapsed to spaces, edges trimmed, then the
     * first `$maxChars` characters kept with the house ellipsis — the same
     * flattening discipline the command rows learned in {@see
     * \SugarCraft\Code\Commands\McpPromptCommands}.
     */
    private static function bound(string $value, int $maxChars): string
    {
        $flat = \trim((string) \preg_replace('/[\p{C}]+/u', ' ', $value));
        if (\mb_strlen($flat) <= $maxChars) {
            return $flat;
        }

        return \mb_substr($flat, 0, $maxChars - 1) . '…';
    }
}
