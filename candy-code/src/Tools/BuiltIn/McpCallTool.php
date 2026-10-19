<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Tools\AcceptsHeartbeat;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\Concerns\TruncatesOutput;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * The second half of the MCP-7 dispatcher pair: runs one deferred tool.
 *
 * `mcp_call(server, tool, arguments)` is the stable spelling of
 * `mcp__<server>__<tool>` for servers whose schemas were deferred by
 * {@see McpToolShape}. It is not a bypass: the call goes through the very
 * same `McpClient::callTool()` the bridge calls, so the router's server
 * allow/deny and the per-call refusals apply unchanged, and
 * {@see \SugarCraft\Code\Permissions\PermissionRule} translates rules naming
 * the bridge spelling across both directions of the pair — a `Deny` on
 * `mcp__server__tool` refuses the args form too.
 *
 * IT REACHES ONLY THE FROZEN ROSTER: a (server, tool) pair that is not in
 * the dispatched half of this launch's shape is refused before anything is
 * dialed — no inventing servers, no reaching a server that stayed first-class
 * by its other spelling either (that one is already declared as a tool).
 *
 * CONDITIONALLY BUILT, exactly like {@see McpListTool}: null from
 * {@see fromCatalog()} unless this launch dispatched something, so a small
 * `.mcp.json` never sees this name on the wire.
 *
 * PERMISSION CLASS: Write — the conservative half of the parity story. A call
 * addressed at a tool whose readOnlyHint is unknown must be treated as a
 * mutation (same default the bridge table gives a made-up name); the gate
 * refines Auto-mode verdicts per call via the seeded hints, and plan/dont-ask
 * refuse, exactly as they refuse an unannotated bridge.
 */
#[BuiltInTool(name: self::NAME, permission: ToolPermissionClass::Write, position: 40, gloss: 'run a deferred MCP tool: server, tool, arguments')]
final readonly class McpCallTool implements Tool, BuildsFromCatalog, TakesToolCallId, AcceptsHeartbeat
{
    use TruncatesOutput;

    public const NAME = 'mcp_call';

    public function __construct(private McpToolShape $shape) {}

    public static function fromCatalog(ToolBuildContext $context): ?Tool
    {
        return $context->mcpShape !== null && $context->mcpShape->lazy
            ? $context->mcpShape->callTool()
            : null;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Run one MCP tool that this session deferred (not declared as its own tool). '
            . 'server and tool must name an entry of mcp_list; arguments is that tool\'s input object. '
            . 'Permission rules written for the tool\'s mcp__server__tool name apply here too.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'server' => [
                    'type' => 'string',
                    'description' => 'The configured server name, exactly as mcp_list prints it.',
                ],
                'tool' => [
                    'type' => 'string',
                    'description' => 'The tool to run on that server.',
                ],
                'arguments' => [
                    'type' => 'object',
                    'description' => 'The tool arguments object, shaped by the schema mcp_list printed.',
                ],
            ],
            'required' => ['server', 'tool'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        return $this->dispatch($args, null);
    }

    public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
    {
        return $this->dispatch($args, $heartbeat);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function dispatch(array $args, ?\Closure $heartbeat): ToolResult
    {
        $id = is_string($args['id'] ?? null) ? $args['id'] : '';
        $server = $args['server'] ?? null;
        $tool = $args['tool'] ?? null;
        $start = hrtime(true);

        if (!is_string($server) || $server === '' || !is_string($tool) || $tool === '') {
            return new ToolResult(
                toolCallId: $id,
                content: 'Error: mcp_call requires string "server" and "tool"; run mcp_list to see what this session deferred.',
                isError: true,
                durationMs: McpToolBridge::elapsedMs($start),
            );
        }

        if ($this->shape->findDispatched($server, $tool) === null) {
            return new ToolResult(
                toolCallId: $id,
                content: sprintf(
                    'Error: mcp_call is limited to the deferred roster; "%s"/"%s" is not on it '
                    . '(run mcp_list, or it is a first-class tool already, or it joined after launch).',
                    $server,
                    $tool,
                ),
                isError: true,
                durationMs: McpToolBridge::elapsedMs($start),
            );
        }

        $arguments = $args['arguments'] ?? [];
        if (!is_array($arguments)) {
            return new ToolResult(
                toolCallId: $id,
                content: 'Error: mcp_call "arguments" must be an object of the tool\'s parameters.',
                isError: true,
                durationMs: McpToolBridge::elapsedMs($start),
            );
        }

        try {
            $raw = $this->shape->client->callTool($server, $tool, $arguments, $heartbeat);
        } catch (\Throwable $e) {
            return new ToolResult(
                toolCallId: $id,
                content: sprintf('Error: MCP tool %s failed: %s', McpToolBridge::wireNameFor($server, $tool), $e->getMessage()),
                isError: true,
                durationMs: McpToolBridge::elapsedMs($start),
            );
        }

        return McpToolBridge::replyResult(
            $raw,
            $id,
            $start,
            fn (string $text): string => $this->truncateOutput($text, self::DEFAULT_MAX_OUTPUT_BYTES),
        );
    }
}
