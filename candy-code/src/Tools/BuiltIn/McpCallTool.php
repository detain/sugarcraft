<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\MCP\McpServerRequests;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Tools\AcceptsHeartbeat;
use SugarCraft\Code\Tools\AcceptsToolProgress;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\Concerns\TruncatesOutput;
use SugarCraft\Code\Tools\McpToolBridge;
use SugarCraft\Code\Tools\RelaysPermissionAsks;
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
 * `mcp__server__tool` refuses the args form too, and an exact argument-free
 * `Allow mcp__server__tool` covers it (globs and `Allow mcp_call` do not
 * translate). `PreToolUse` HOOKS match either spelling of the same call: a
 * server-scoped matcher keeps firing when the floor defers its server, and a
 * matcher naming `mcp_call` keeps seeing the dispatcher frame.
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
final readonly class McpCallTool implements Tool, BuildsFromCatalog, TakesToolCallId, AcceptsHeartbeat, RelaysPermissionAsks, AcceptsToolProgress
{
    use TruncatesOutput;

    public const NAME = 'mcp_call';

    /**
     * The turn's ask and progress channels ride in here exactly as they do
     * for {@see McpToolBridge} (C-MCP-3.b): `mcp_call` is the other spelling
     * of the same capability and must put the same elicitation dialect, not
     * a second one. Defaults keep every existing construction site
     * byte-unchanged (the catalog builder, the corpus probe, the shape).
     */
    public function __construct(
        private McpToolShape $shape,
        private ?\Closure $permissionApprover = null,
        private ?\Closure $toolProgressEmitter = null,
        private bool $elicitationsClosed = false,
    ) {}

    /**
     * C-AGT-3: this dispatcher narrowed to one preset's `mcpServers`
     * allowlist — {@see McpToolShape::scopedTo()} carries the binding. Name,
     * schema and description are byte-identical: a scoped roster must not
     * look like a different tool, only a smaller one.
     *
     * @param array<array-key, mixed> $mcpServers
     */
    public function scopedTo(array $mcpServers): self
    {
        // The bound channels survive the narrowing: a scoped dispatcher is
        // the SAME tool speaking over a smaller roster, and the turn bound
        // its ask/progress channels to the tool, not to the roster.
        return new self(...array_replace(get_object_vars($this), ['shape' => $this->shape->scopedTo($mcpServers)]));
    }

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
            $raw = $this->shape->client->callTool($server, $tool, $arguments, $heartbeat, $this->perCallDesk($id, $server));
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
            $server,
            $tool,
            fn (string $text): string => $this->truncateOutput($text, self::DEFAULT_MAX_OUTPUT_BYTES),
        );
    }

    /**
     * {@see RelaysPermissionAsks} — see {@see McpToolBridge::withPermissionApprover()}
     * for the sticky-close law both spellings share.
     */
    public function withPermissionApprover(\Closure $approver): Tool
    {
        if ($this->elicitationsClosed) {
            return $this;
        }

        return new self(...array_replace(get_object_vars($this), ['permissionApprover' => $approver]));
    }

    /**
     * {@see AcceptsToolProgress} — see {@see McpToolBridge::withToolProgressEmitter()}.
     */
    public function withToolProgressEmitter(\Closure $emit): self
    {
        return new self(...array_replace(get_object_vars($this), ['toolProgressEmitter' => $emit]));
    }

    /**
     * The `-p` closure, the same latch {@see McpToolBridge::withoutInteractiveUser()}
     * implements for the bridge spelling.
     */
    public function withoutInteractiveUser(string $why): self
    {
        return new self(...array_replace(get_object_vars($this), [
            'permissionApprover' => null,
            'elicitationsClosed' => true,
        ]));
    }

    /**
     * The per-call answer desk for one dispatch — same shape and same
     * disclosed root caveat as {@see McpToolBridge::perCallDesk()}; progress
     * frames carry the `mcp_call` row key itself, because under this spelling
     * `mcp_call` IS the running tool row (the addressed server tool is a
     * parameter, not a row of its own).
     */
    private function perCallDesk(string $callId, string $server): ?McpServerRequests
    {
        $ask = $this->elicitationsClosed ? null : $this->permissionApprover;
        $emit = $this->toolProgressEmitter;
        if ($ask === null && ($emit === null || $callId === '')) {
            return null;
        }

        return new McpServerRequests(
            '',
            $ask === null ? null : static function (array $questions) use ($ask, $server, $callId): mixed {
                return McpToolBridge::askElicitation($ask, $server, $callId, $questions);
            },
            $emit === null || $callId === '' ? null : McpToolBridge::progressSink($emit, $callId, self::NAME),
        );
    }
}
