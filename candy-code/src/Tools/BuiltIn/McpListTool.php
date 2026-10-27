<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\BuiltIn;

use SugarCraft\Code\MCP\McpTool;
use SugarCraft\Code\MCP\McpToolShape;
use SugarCraft\Code\Tools\Catalog\BuildsFromCatalog;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Code\Tools\Concerns\TruncatesOutput;
use SugarCraft\Code\Tools\TakesToolCallId;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolResult;

/**
 * The roster of deferred MCP tools (MCP-7), the first half of the dispatcher pair.
 *
 * When a server's advertised schemas cost more than the lazy floor
 * (`mcp.lazySchemaTokens`, see {@see McpToolShape}), its tools are NOT
 * declared as `mcp__server__tool` bridges — they would pay their schema
 * tokens on every request step. This tool is how the model discovers what it
 * is missing: the dispatched roster WITH each entry's JSON Schema, so the
 * cost is paid once, on demand, only for servers a turn actually reaches
 * for.
 *
 * IT ANSWERS FROM THE FROZEN SHAPE, never from a fresh `listTools()`: the
 * pair and the bridges were declared from one derivation at launch, so what
 * this lists is exactly what `mcp_call` can reach, and adoption of anything
 * that appeared mid-session stays next session (the E703-α rule).
 *
 * CONDITIONALLY BUILT: {@see fromCatalog()} returns null unless this launch
 * dispatched at least one tool, so a small `.mcp.json` (and a no-MCP launch)
 * sees neither half of the pair — the wire roster stays byte-identical to the
 * pre-MCP-7 shape in the common case.
 *
 * PERMISSION CLASS: Read. It lists configuration the user already opted into
 * and changes nothing; like the bridges it describes, it auto-allows in Auto
 * mode and never blocks.
 */
#[BuiltInTool(name: self::NAME, permission: ToolPermissionClass::Read, position: 39, gloss: 'list deferred MCP tools and their schemas')]
final readonly class McpListTool implements Tool, BuildsFromCatalog, TakesToolCallId
{
    use TruncatesOutput;

    public const NAME = 'mcp_list';

    public function __construct(private McpToolShape $shape) {}

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
        return new self($this->shape->scopedTo($mcpServers));
    }

    public static function fromCatalog(ToolBuildContext $context): ?Tool
    {
        return $context->mcpShape !== null && $context->mcpShape->lazy
            ? $context->mcpShape->listTool()
            : null;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return "List the MCP tools this session reaches through mcp_call instead of as first-class tools, "
            . 'each with its input schema so you can build the arguments. '
            . 'Call with no arguments for everything; pass "server" to see one server\'s tools. '
            . 'Pass "tool" to see exactly one tool\'s entry, schema included. '
            . 'A roster whose full schemas ride above the output cap comes back as names only — fetch each schema that way. '
            . 'Run a listed tool with mcp_call(server, tool, arguments).';
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
                    'description' => 'Optional: list only this server\'s deferred tools.',
                ],
                // C-MCP-6.a: the schemas are what this tool defers, so the
                // per-tool lookup is how a model gets ONE back without
                // paying for the whole roster (or eating the output cap).
                'tool' => [
                    'type' => 'string',
                    'description' => 'Optional: print exactly this tool\'s entry, schema included — the way to fetch one schema from a names-only overview.',
                ],
            ],
            // The house schema shape: `required` always present, empty here
            // (BoardReadTool is the precedent) — the suite pins the key.
            'required' => [],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $id = is_string($args['id'] ?? null) ? $args['id'] : '';
        $filter = $args['server'] ?? null;
        if ($filter !== null && !is_string($filter)) {
            return new ToolResult(
                toolCallId: $id,
                content: 'Error: mcp_list "server" must be a string naming one deferred server.',
                isError: true,
            );
        }
        $tool = $args['tool'] ?? null;
        if ($tool !== null && !is_string($tool)) {
            return new ToolResult(
                toolCallId: $id,
                content: 'Error: mcp_list "tool" must be a string naming one deferred tool.',
                isError: true,
            );
        }

        // C-MCP-6.a: one exact-name entry, schema included, on demand. The
        // miss answers as information (the same non-failure polarity the
        // empty-server filter keeps) — a wrong name is a wrong guess, not a
        // broken tool.
        if ($tool !== null && $tool !== '') {
            $lines = [];
            foreach ($this->shape->dispatchedDescriptors as $descriptor) {
                if ($descriptor->name === $tool) {
                    $lines[] = 'Server ' . $descriptor->serverName . ':';
                    $lines[] = self::schemaLine($descriptor);
                }
            }
            $content = $lines === []
                ? sprintf('No deferred MCP tool named "%s" (mcp_list answers the roster).', $tool)
                : implode("\n", $lines);

            return new ToolResult(
                toolCallId: $id,
                content: $this->truncateOutput($content, self::DEFAULT_MAX_OUTPUT_BYTES),
            );
        }

        $lines = [];
        $names = [];
        $count = 0;
        $current = null;
        foreach ($this->shape->dispatchedDescriptors as $descriptor) {
            if ($filter !== null && $filter !== '' && $descriptor->serverName !== $filter) {
                continue;
            }
            if ($descriptor->serverName !== $current) {
                $current = $descriptor->serverName;
                $lines[] = 'Server ' . $current . ':';
                $names[] = 'Server ' . $current . ':';
            }
            $count++;
            $lines[] = self::schemaLine($descriptor);
            $names[] = '- ' . $descriptor->name;
        }

        if ($lines === []) {
            $content = $filter === null || $filter === ''
                ? 'No deferred MCP tools: every configured server is below the schema-token floor or marked eager.'
                : sprintf('No deferred MCP tools on server "%s" (it may be eager, or not exist).', $filter);
        } else {
            $content = implode("\n", $lines);
            if (strlen($content) > self::DEFAULT_MAX_OUTPUT_BYTES) {
                // The full schema text could not survive the cap anyway, and
                // a truncateOutput() cut through a JSON Schema is the worst
                // answer this tool can give. C-MCP-6.a: a names-only
                // overview, pointer included, and the schemas ride along one
                // "tool" call at a time. The cap stays the final guard.
                $content = sprintf(
                    '%d deferred MCP tools ride above the mcp_list output cap — listed by name here;'
                    . ' call mcp_list with "tool" set to a name below for its exact schema.',
                    $count,
                ) . "\n" . implode("\n", $names);
            }
        }

        return new ToolResult(
            toolCallId: $id,
            content: $this->truncateOutput($content, self::DEFAULT_MAX_OUTPUT_BYTES),
        );
    }

    /** One roster line with the FULL schema: name, one-line summary, JSON. */
    private static function schemaLine(McpTool $descriptor): string
    {
        $schema = json_encode(
            $descriptor->inputSchema,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $summary = preg_replace('/\s+/', ' ', $descriptor->description) ?? $descriptor->description;

        return '- ' . $descriptor->name . ($summary === ''
            ? ''
            : ': ' . $summary)
            . ($schema === false ? '' : ' arguments: ' . $schema);
    }
}
