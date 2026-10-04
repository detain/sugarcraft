<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

use SugarCraft\Crush\Tools\BuiltIn\MemoryTool;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The `Memory` tool as the dream pass sees it (roadmap 5.4-3): `view` and
 * `recall` only.
 *
 * READ-ONLY BECAUSE THE WRITES BELONG TO THE PARENT. A dream turn runs in the
 * engine's forked child; a note it wrote there would bypass every rule
 * {@see AutoMemoryConsolidator::apply()} enforces — the secret check, the size
 * cap, the duplicate check and, above all, "only edit notes tagged
 * `auto-memory`" — and would race the user's own `/memory` commands. So the
 * model reads the notes through this tool and proposes its changes as the
 * JSON answer {@see DreamPass} applies in the main process.
 *
 * Named `Memory` so the permission gate classifies it exactly as the built-in
 * (no-ask), and a `permissionRules` deny on `Memory` still turns it off. It
 * lives outside `Tools/BuiltIn/` on purpose: the tool catalog discovers that
 * directory, and this is never a tool a session is launched with.
 */
final readonly class DreamMemoryView implements Tool
{
    private const ACTIONS = ['view', 'recall'];

    public function __construct(private MemoryWriter $writer)
    {
    }

    public function name(): string
    {
        return MemoryTool::NAME;
    }

    public function description(): string
    {
        return 'Read the persistent memory notes. `view` without an `id` lists every note (type, id, opening '
            . 'words, tags); with an `id` it returns that note in full. `recall` searches every note for '
            . '`query`. This tool only reads: propose changes in your JSON answer.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'What to do'],
                'id' => ['type' => 'string', 'description' => 'Note id (view)'],
                'query' => ['type' => 'string', 'description' => 'Text to search for (recall)'],
            ],
            'required' => ['action'],
        ];
    }

    public function execute(array $args): ToolResult
    {
        $action = $args['action'] ?? null;
        if (!\is_string($action) || !\in_array($action, self::ACTIONS, true)) {
            return new ToolResult('', 'Error: during the dream pass the Memory tool only reads; action must be one of '
                . implode(', ', self::ACTIONS) . ', and changes go in your JSON answer', true);
        }

        return (new MemoryTool($this->writer))->execute(array_intersect_key($args, ['action' => 1, 'id' => 1, 'query' => 1]));
    }
}
